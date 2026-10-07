<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Deploy;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\CaddyParser;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\AppRuntime;
use AzerioidPanel\Broker\Vhost\DockerManager;
use AzerioidPanel\Broker\Vhost\OctaneManager;
use AzerioidPanel\Broker\Vhost\Pm2Manager;
use AzerioidPanel\Broker\Vhost\VhostUser;

/**
 * Git deploy for one site (B8 / request #12, ADR A41 — Proposition B only, narrowed; A53).
 *
 * The deploy key must stay unreadable by the site's identity (A41), yet the pull and the
 * post-deploy command must run as that identity. So the work is split:
 *
 *  1. root fetches the repository into a bare mirror with the key
 *     (/var/lib/azerioid-deploy/<site>/mirror.git — the key never leaves root);
 *  2. the site's identity checks the site out from that local mirror, and runs the
 *     post-deploy command, in the site's top directory;
 *  3. the runtime reloads through the actions that already exist (Octane, PM2, Docker).
 *
 *   /var/lib/azerioid-deploy/                    root 0711
 *     <site>/                                    root:<site group> 0750
 *       deploy.json   root 0600   repository, branch, command, schedule
 *       state.json    root 0600   current and previous commit, history
 *       id_ed25519    root 0600   the deploy key (id_ed25519.pub 0644)
 *       known_hosts   root 0600   host keys, first seen on the first fetch
 *       mirror.git/   root:<site group>, group-readable: the identity reads it, cannot change it
 *
 * In place, not atomic (operator decision, A41): during a checkout the site serves a mix of
 * old and new files for a moment. No webhooks. Rollback is code only.
 */
final class GitDeploy
{
    public const BASE = '/var/lib/azerioid-deploy';

    public const PRESETS = [
        'none' => null,
        'composer' => 'composer install --no-dev --optimize-autoloader --no-interaction',
        'laravel' => 'composer install --no-dev --optimize-autoloader --no-interaction && php artisan migrate --force && php artisan optimize',
        'npm' => 'npm ci && npm run build',
    ];

    public const CUSTOM_CONFIRM = 'RUN-AS-SITE';

    public const HISTORY = 20;

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    // ------------------------------------------------------------- config

    /** @return array<string,mixed> */
    public function config(string $domain): array
    {
        $domain = Validator::domain($domain);
        $this->vhost($domain);
        $cfg = $this->readJson($this->dir($domain) . '/deploy.json');

        return [
            'domain' => $domain,
            'configured' => $cfg !== [],
            'repository' => $cfg['repository'] ?? null,
            'branch' => $cfg['branch'] ?? null,
            'preset' => $cfg['preset'] ?? null,
            'command' => $cfg['command'] ?? null,
            'schedule' => $cfg['schedule'] ?? 'off',
            // A78: token is the public id in the webhook URL. The secret is NOT
            // returned here — config() runs on every page load, so emitting the
            // secret each time would spread it through page snapshots and any
            // operation logging. It is shown once, by configure()/rotateWebhook().
            'webhook_token' => $cfg['webhook_token'] ?? null,
            'webhook_configured' => ($cfg['webhook_secret'] ?? '') !== '',
            'public_key' => $this->runtime->fileExists($this->dir($domain) . '/id_ed25519.pub')
                ? trim($this->runtime->readFile($this->dir($domain) . '/id_ed25519.pub')) : null,
            'state' => $this->state($domain),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function configure(string $domain, array $input): array
    {
        $domain = Validator::domain($domain);
        [$vhost] = $this->vhost($domain);
        $repository = self::validateRepository($input['repository'] ?? '');
        $branch = self::validateBranch($input['branch'] ?? 'main');
        $preset = (string) ($input['preset'] ?? 'none');
        $command = null;
        if ($preset === 'custom') {
            // Arbitrary code as the site — no wider than what the site's own PHP can already
            // do (A41), but it is still an explicit choice.
            Validator::typedConfirm((string) ($input['confirm'] ?? ''), self::CUSTOM_CONFIRM);
            $command = self::validateCommand($input['command'] ?? '');
        } elseif (!array_key_exists($preset, self::PRESETS)) {
            throw new BrokerException('preset must be none, composer, laravel, npm or custom.', 2);
        }
        $schedule = self::validateSchedule($input['schedule'] ?? 'off');

        $this->ensureDir($domain);
        // A78: a push-to-deploy webhook secret (HMAC key) and a public token
        // (unguessable id in the webhook URL). Preserved across re-configures so
        // saving settings does not silently invalidate a configured webhook.
        $existing = $this->readJson($this->dir($domain) . '/deploy.json');
        $webhookSecret = (string) ($existing['webhook_secret'] ?? '');
        $webhookToken = (string) ($existing['webhook_token'] ?? '');
        if ($webhookSecret === '' || $webhookToken === '') {
            $webhookSecret = bin2hex(random_bytes(32));
            $webhookToken = bin2hex(random_bytes(16));
        }
        $this->runtime->writeFile($this->dir($domain) . '/deploy.json', json_encode([
            'repository' => $repository,
            'branch' => $branch,
            'preset' => $preset,
            'command' => $command,
            'schedule' => $schedule,
            'webhook_secret' => $webhookSecret,
            'webhook_token' => $webhookToken,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
        if (!$this->runtime->fileExists($this->dir($domain) . '/id_ed25519')) {
            $this->generateKey($domain);
        }

        // Show the secret once, here (config() never re-emits it).
        return $this->config($domain) + ['webhook_secret' => $webhookSecret];
    }

    /** A new key: the old one stops working the moment the provider forgets it. */
    public function rotateKey(string $domain): array
    {
        $domain = Validator::domain($domain);
        $this->vhost($domain);
        $this->ensureDir($domain);
        foreach (['id_ed25519', 'id_ed25519.pub'] as $f) {
            if ($this->runtime->fileExists($this->dir($domain) . '/' . $f)) {
                $this->runtime->deleteFile($this->dir($domain) . '/' . $f);
            }
        }
        $this->generateKey($domain);

        return $this->config($domain);
    }

    public function remove(string $domain): array
    {
        $domain = Validator::domain($domain);
        $this->runtime->exec(['/bin/rm', '-rf', '--one-file-system', $this->dir($domain)], null, 120);

        return ['domain' => $domain, 'removed' => true];
    }

    /** A78: replace the webhook secret + token (e.g. if the secret leaked). */
    public function rotateWebhook(string $domain): array
    {
        $domain = Validator::domain($domain);
        $this->vhost($domain);
        $cfg = $this->readJson($this->dir($domain) . '/deploy.json');
        if ($cfg === []) {
            throw new BrokerException('Deploy is not configured for this site.', 2);
        }
        $cfg['webhook_secret'] = bin2hex(random_bytes(32));
        $cfg['webhook_token'] = bin2hex(random_bytes(16));
        $this->runtime->writeFile(
            $this->dir($domain) . '/deploy.json',
            json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            0600
        );

        // Show the new secret once (config() never re-emits it).
        return $this->config($domain) + ['webhook_secret' => $cfg['webhook_secret']];
    }

    /**
     * A78: verify a push webhook and, if valid, launch the site's deploy in the
     * background. Called by the unauthenticated /hooks/deploy/{token} web route,
     * which is a thin relay — the HMAC secret never leaves root (it lives in
     * deploy.json), so verification happens here, not in the web layer.
     *
     * @return array{accepted:bool, domain?:string, reason?:string}
     */
    public function webhook(string $token, string $provider, string $signature, string $body): array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            throw new BrokerException('Invalid webhook token.', 2);
        }
        $cfg = null;
        $domain = '';
        foreach ($this->runtime->isDir(self::BASE) ? $this->runtime->listDir(self::BASE) : [] as $slug) {
            $candidate = $this->readJson(self::BASE . '/' . $slug . '/deploy.json');
            $candToken = (string) ($candidate['webhook_token'] ?? '');
            if ($candToken !== '' && hash_equals($candToken, $token)) {
                $cfg = $candidate;
                $domain = (string) ($this->readJson(self::BASE . '/' . $slug . '/state.json')['domain'] ?? '');
                break;
            }
        }
        // Generic failure (never reveal whether the token matched a site).
        if ($cfg === null || $domain === '' || !self::verifySignature(
            $provider,
            (string) ($cfg['webhook_secret'] ?? ''),
            $signature,
            $body
        )) {
            throw new BrokerException('Webhook rejected.', 2);
        }

        // Fail closed: only deploy on a confirmed push to the configured branch.
        // A payload with no determinable branch (a ping/non-push event, or a
        // malformed ref) must NOT deploy.
        $pushed = self::branchFromPayload($body);
        $configured = (string) ($cfg['branch'] ?? 'main');
        if ($pushed === '' || $pushed !== $configured) {
            return [
                'accepted' => false,
                'domain' => $domain,
                'reason' => 'branch ' . ($pushed !== '' ? $pushed : '(none)') . ' != ' . $configured,
            ];
        }

        // Launch the deploy out of band so the webhook returns immediately; the
        // deploy itself runs as the site identity inside GitDeploy::deploy.
        $this->runtime->exec([
            '/usr/bin/systemd-run', '--quiet', '--collect',
            '--unit=azerioid-deploy-' . substr(hash('sha256', $domain), 0, 16),
            rtrim($this->config->panelRoot, '/') . '/broker', 'deploy.run', $domain,
        ], null, 30);

        return ['accepted' => true, 'domain' => $domain];
    }

    private static function verifySignature(string $provider, string $secret, string $signature, string $body): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }
        if ($provider === 'gitlab') {
            // GitLab sends the shared secret verbatim in X-Gitlab-Token.
            return hash_equals($secret, $signature);
        }

        // GitHub (default): X-Hub-Signature-256: sha256=<hex HMAC of the raw body>.
        return hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $signature);
    }

    private static function branchFromPayload(string $body): string
    {
        $data = json_decode($body, true);
        $ref = is_array($data) ? (string) ($data['ref'] ?? '') : '';

        return str_starts_with($ref, 'refs/heads/') ? substr($ref, strlen('refs/heads/')) : '';
    }

    /** @return list<array<string,mixed>> every site with deploy configured (the panel's scheduler reads this) */
    public function listAll(): array
    {
        $out = [];
        foreach ($this->runtime->isDir(self::BASE) ? $this->runtime->listDir(self::BASE) : [] as $slug) {
            $cfg = $this->readJson(self::BASE . '/' . $slug . '/deploy.json');
            $domain = (string) ($this->readJson(self::BASE . '/' . $slug . '/state.json')['domain'] ?? '');
            if ($cfg === [] || $domain === '') {
                continue;
            }
            $state = $this->state($domain);
            $out[] = ['domain' => $domain, 'schedule' => $cfg['schedule'] ?? 'off', 'last_deploy_at' => $state['last_deploy_at'] ?? null,
                'current' => $state['current'] ?? null];
        }

        return $out;
    }

    // ------------------------------------------------------------- deploy

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function deploy(string $domain, array $input): array
    {
        $domain = Validator::domain($domain);
        [$vhost] = $this->vhost($domain);
        $cfg = $this->readJson($this->dir($domain) . '/deploy.json');
        if ($cfg === []) {
            throw new BrokerException("Deploy is not configured for {$domain}.", 3);
        }
        $trigger = in_array($input['trigger'] ?? '', ['manual', 'schedule'], true) ? (string) $input['trigger'] : 'manual';
        $log = [];
        $this->fetchMirror($domain, (string) $cfg['repository'], (string) $cfg['branch'], $log);
        $target = trim($this->git($domain, ['rev-parse', 'refs/heads/' . $cfg['branch']], true, $log));
        if (!preg_match('/^[0-9a-f]{40}$/', $target)) {
            throw new BrokerException('The branch ' . $cfg['branch'] . ' does not exist in the repository.', 3);
        }
        $state = $this->state($domain);
        if (($state['current'] ?? null) === $target && $trigger === 'schedule') {
            return ['domain' => $domain, 'deployed' => false, 'commit' => $target, 'reason' => 'already at this commit', 'log' => $log];
        }

        return $this->checkoutAndFinish($domain, $vhost, $cfg, $target, $trigger, $log);
    }

    /**
     * Code only (A41): the commit before the current one, post-deploy command re-run.
     *
     * @return array<string,mixed>
     */
    public function rollback(string $domain): array
    {
        $domain = Validator::domain($domain);
        [$vhost] = $this->vhost($domain);
        $cfg = $this->readJson($this->dir($domain) . '/deploy.json');
        $previous = $this->state($domain)['previous'] ?? null;
        if ($cfg === [] || !is_string($previous) || !preg_match('/^[0-9a-f]{40}$/', $previous)) {
            throw new BrokerException("{$domain} has no previous deploy to roll back to.", 3);
        }
        $log = [];

        return $this->checkoutAndFinish($domain, $vhost, $cfg, $previous, 'rollback', $log);
    }

    /**
     * @param  array<string,mixed>  $vhost
     * @param  array<string,mixed>  $cfg
     * @param  list<string>  $log
     * @return array<string,mixed>
     */
    private function checkoutAndFinish(string $domain, array $vhost, array $cfg, string $commit, string $trigger, array &$log): array
    {
        $top = $this->top($vhost);
        $started = $this->runtime->now();
        $state = $this->state($domain);
        $status = 'failed';
        $error = null;
        $checkedOut = false;
        try {
            $this->checkout($domain, $top, $commit, $log);
            $checkedOut = true;
            $command = $cfg['preset'] === 'custom' ? (string) $cfg['command'] : self::PRESETS[$cfg['preset']] ?? null;
            if ($command !== null) {
                $this->runAsSite($domain, $top, $command, $vhost, $log);
            }
            $log[] = $this->reloadRuntime($domain, $vhost);
            $status = 'ok';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $log[] = 'FAILED: ' . $error;
        }

        $history = is_array($state['history'] ?? null) ? $state['history'] : [];
        array_unshift($history, ['commit' => $commit, 'at' => $started, 'status' => $status, 'trigger' => $trigger, 'error' => $error]);
        $next = [
            'domain' => $domain,
            'current' => $status === 'ok' ? $commit : ($state['current'] ?? null),
            'previous' => $status === 'ok' && ($state['current'] ?? null) !== $commit ? ($state['current'] ?? null) : ($state['previous'] ?? null),
            'last_deploy_at' => $started,
            'history' => array_slice($history, 0, self::HISTORY),
        ];
        $this->runtime->writeFile($this->dir($domain) . '/state.json', json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
        if ($status !== 'ok') {
            throw new BrokerException('Deploy of ' . substr($commit, 0, 12) . ' failed: ' . $error
                . ($checkedOut
                    ? ' The new files are in place (deploys are not atomic); roll back or deploy again.'
                    : ' The site\'s files were not changed.'), 1);
        }

        return ['domain' => $domain, 'deployed' => true, 'commit' => $commit, 'trigger' => $trigger, 'log' => $log];
    }

    /** @param list<string> $log */
    private function fetchMirror(string $domain, string $repository, string $branch, array &$log): void
    {
        $this->ensureDir($domain);
        $mirror = $this->dir($domain) . '/mirror.git';
        if (!$this->runtime->isDir($mirror)) {
            $this->mustGit(['init', '--bare', '--quiet', $mirror], $log, 'create the local mirror');
        }
        $key = $this->dir($domain) . '/id_ed25519';
        $ssh = 'ssh -i ' . $key . ' -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new'
            . ' -o UserKnownHostsFile=' . $this->dir($domain) . '/known_hosts';
        $fetch = $this->runtime->exec([
            '/usr/bin/env', 'GIT_SSH_COMMAND=' . $ssh, 'GIT_TERMINAL_PROMPT=0',
            '/usr/bin/git', '--git-dir=' . $mirror, 'fetch', '--prune', '--no-tags', $repository,
            '+refs/heads/' . $branch . ':refs/heads/' . $branch,
        ], null, 600);
        if (!$fetch->ok()) {
            throw new BrokerException('Fetching ' . $branch . ' from ' . $repository . ' failed: '
                . substr(trim($fetch->stderr), -400) . (str_contains($fetch->stderr, 'Permission denied')
                    ? ' Add the deploy key (read-only) to the repository first.' : ''), 1);
        }
        // Group-readable for the site's identity; changeable by root only.
        $group = VhostUser::docrootGroup($this->runtime, $domain);
        $this->runtime->exec(['/usr/bin/chown', '-R', 'root:' . $group, $mirror], null, 120);
        $this->runtime->exec(['/usr/bin/chmod', '-R', 'u=rwX,g=rX,o=', $mirror], null, 120);
        $log[] = 'Fetched ' . $branch . ' into the local mirror.';
    }

    /** @param list<string> $log */
    private function checkout(string $domain, string $top, string $commit, array &$log): void
    {
        $mirror = $this->dir($domain) . '/mirror.git';
        $user = VhostUser::username($domain);
        // umask 007: files the site's PHP must write (storage/, bootstrap/cache) are group-writable,
        // and nothing is opened to other accounts.
        $git = 'umask 007; cd ' . escapeshellarg($top) . ' && '
            . '(test -d .git || git init --quiet) && '
            // Every branch head of the mirror: a rollback target from an earlier deploy is
            // already in this .git, and fetching refs (not a bare SHA) needs no upload-pack
            // allowance. The mirror is root's and the fetch is the site's, so upload-pack must
            // trust it — and for a local path git strips `-c` from upload-pack's environment,
            // so the setting goes on upload-pack's own command line. (Giving the mirror to the
            // site instead would let it plant hooks that root runs on the next fetch.)
            . 'git fetch --quiet --no-tags --upload-pack=' . escapeshellarg('git -c safe.directory=' . $mirror . ' upload-pack')
            . ' ' . escapeshellarg($mirror) . " '+refs/heads/*:refs/remotes/deploy/*' && "
            . 'git checkout --quiet --force --detach ' . escapeshellarg($commit);
        $r = $this->runtime->exec(['/usr/sbin/runuser', '-u', $user, '--', '/bin/bash', '-c', $git], null, 600);
        if (!$r->ok()) {
            throw new BrokerException('Checkout of ' . substr($commit, 0, 12) . ' failed: ' . substr(trim($r->stderr), -400), 1);
        }
        $log[] = 'Checked out ' . substr($commit, 0, 12) . ' in ' . $top . ' as ' . $user . '.';
    }

    /**
     * @param  array<string,mixed>  $vhost
     * @param  list<string>  $log
     */
    private function runAsSite(string $domain, string $top, string $command, array $vhost, array &$log): void
    {
        $user = VhostUser::username($domain);
        if (in_array($user, ['root', 'azerioid-supervised'], true) || !str_starts_with($user, VhostUser::PREFIX)) {
            throw new BrokerException('Refusing to run a deploy command as ' . $user . '.', 3);
        }
        $php = isset($vhost['php_version']) && preg_match('/^\d\.\d$/', (string) $vhost['php_version']) === 1
            ? '/usr/bin/php' . $vhost['php_version'] : null;
        $path = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        // `php` and `composer` run under the site's own PHP version, not whatever the host default is.
        $prelude = 'umask 007; export HOME=' . escapeshellarg($top) . ' PATH=' . escapeshellarg($path)
            . ' COMPOSER_HOME=' . escapeshellarg($top . '/.composer') . '; '
            . ($php !== null && $this->runtime->fileExists($php) ? 'php() { ' . $php . ' "$@"; }; composer() { ' . $php . ' $(command -v composer) "$@"; }; export -f php composer; ' : '')
            . 'cd ' . escapeshellarg($top) . ' && ';
        $r = $this->runtime->exec(['/usr/sbin/runuser', '-u', $user, '--', '/bin/bash', '-c', $prelude . $command], null, 900);
        $out = trim($r->stdout . "\n" . $r->stderr);
        $log[] = '$ ' . $command . "\n" . substr($out, -2000);
        if (!$r->ok()) {
            throw new BrokerException('The post-deploy command failed (exit ' . $r->exitCode . ').', 1);
        }
    }

    /** @param array<string,mixed> $vhost */
    private function reloadRuntime(string $domain, array $vhost): string
    {
        return match (AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM)) {
            AppRuntime::OCTANE => 'Reloaded Octane: ' . ((new OctaneManager($this->config, $this->runtime))->reload($domain)['method'] ?? 'ok') . '.',
            AppRuntime::PM2 => 'Reloaded PM2: ' . ((new Pm2Manager($this->config, $this->runtime))->reload($domain)['method'] ?? 'ok') . '.',
            AppRuntime::DOCKER => (new DockerManager($this->config, $this->runtime))->restart($domain) !== [] ? 'Restarted the container.' : '',
            default => 'PHP-FPM serves the new files on the next request; nothing to reload.',
        };
    }

    // ------------------------------------------------------------ helpers

    /** @return array<string,mixed> */
    public function state(string $domain): array
    {
        return $this->readJson($this->dir($domain) . '/state.json');
    }

    private function generateKey(string $domain): void
    {
        $key = $this->dir($domain) . '/id_ed25519';
        $r = $this->runtime->exec(['/usr/bin/ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'azerioid-deploy@' . $domain, '-f', $key], null, 30);
        if (!$r->ok()) {
            throw new BrokerException('Could not create a deploy key: ' . trim($r->stderr), 1);
        }
        $this->runtime->chmod($key, 0600);
        $this->runtime->chmod($key . '.pub', 0644);
        // Record which domain this directory belongs to, for listAll().
        if (!$this->runtime->fileExists($this->dir($domain) . '/state.json')) {
            $this->runtime->writeFile($this->dir($domain) . '/state.json', json_encode(['domain' => $domain]) . "\n", 0600);
        }
    }

    private function ensureDir(string $domain): void
    {
        if (!$this->runtime->isDir(self::BASE)) {
            $this->runtime->mkdir(self::BASE, 0711);
        }
        $this->runtime->chmod(self::BASE, 0711);
        $dir = $this->dir($domain);
        if (!$this->runtime->isDir($dir)) {
            $this->runtime->mkdir($dir, 0750);
        }
        if ($this->runtime->getuid() === 0) {
            $this->runtime->chown($dir, 'root', VhostUser::docrootGroup($this->runtime, $domain));
            $this->runtime->chmod($dir, 0750);
        }
    }

    private function dir(string $domain): string
    {
        return self::BASE . '/' . trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($domain)), '-');
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $log
     */
    private function git(string $domain, array $args, bool $mirror, array &$log): string
    {
        $cmd = array_merge(['/usr/bin/git'], $mirror ? ['--git-dir=' . $this->dir($domain) . '/mirror.git'] : [], $args);
        $r = $this->runtime->exec($cmd, null, 60);

        return $r->ok() ? $r->stdout : '';
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $log
     */
    private function mustGit(array $args, array &$log, string $what): void
    {
        $r = $this->runtime->exec(array_merge(['/usr/bin/git'], $args), null, 60);
        if (!$r->ok()) {
            throw new BrokerException("Could not {$what}: " . trim($r->stderr), 1);
        }
    }

    /** @return array{0: array<string,mixed>, 1: string} */
    private function vhost(string $domain): array
    {
        foreach ($this->runtime->glob(rtrim($this->config->caddyConfD, '/') . '/*.conf') as $file) {
            $parsed = CaddyParser::parseFile($file, $this->runtime->readFile($file), $this->config->readonlyVhosts);
            if (($parsed['domain'] ?? '') === $domain) {
                if (!empty($parsed['readonly'])) {
                    throw new BrokerException("{$domain} is managed outside the panel.", 3);
                }

                return [$parsed, $file];
            }
        }

        throw new BrokerException('Vhost config does not exist.', 3);
    }

    /** @param array<string,mixed> $vhost */
    private function top(array $vhost): string
    {
        $root = rtrim((string) ($vhost['root'] ?? ''), '/');
        $www = rtrim($this->config->wwwRoot, '/') . '/';
        if (!str_starts_with($root, $www)) {
            throw new BrokerException('Git deploy needs a site under ' . $www . '.', 3);
        }

        return $www . explode('/', substr($root, strlen($www)))[0];
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (!$this->runtime->fileExists($path)) {
            return [];
        }
        $d = json_decode($this->runtime->readFile($path), true);

        return is_array($d) ? $d : [];
    }

    public static function validateRepository(mixed $value): string
    {
        $repo = trim((string) $value);
        // SSH (git@host:path or ssh://) with the deploy key, or public HTTPS. Never credentials
        // in the URL, never a local path or another transport (ext::, file://).
        $ok = preg_match('#^(?:ssh://)?[A-Za-z0-9._-]+@[A-Za-z0-9.-]+(?::\d{1,5})?[:/][A-Za-z0-9._/~-]+$#', $repo) === 1
            || preg_match('#^https://[A-Za-z0-9.-]+(?::\d{1,5})?/[A-Za-z0-9._/~-]+$#', $repo) === 1;
        if (!$ok || strlen($repo) > 300 || str_contains($repo, '..')) {
            throw new BrokerException('repository must be git@host:owner/repo.git, ssh://git@host/owner/repo.git or a public https:// URL (no credentials in it).', 2);
        }

        return $repo;
    }

    public static function validateBranch(mixed $value): string
    {
        $branch = trim((string) $value);
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$#', $branch) || str_contains($branch, '..') || str_ends_with($branch, '.lock')) {
            throw new BrokerException('branch must be a plain branch name.', 2);
        }

        return $branch;
    }

    public static function validateCommand(mixed $value): string
    {
        $command = trim((string) $value);
        if ($command === '' || strlen($command) > 1000 || preg_match('/[\r\n\0]/', $command)) {
            throw new BrokerException('command must be one line, at most 1000 characters.', 2);
        }

        return $command;
    }

    public static function validateSchedule(mixed $value): string
    {
        $schedule = strtolower(trim((string) $value));
        if ($schedule === 'off' || $schedule === 'hourly' || preg_match('/^daily@([01]?\d|2[0-3])$/', $schedule) === 1) {
            return $schedule;
        }

        throw new BrokerException('schedule must be off, hourly or daily@<hour>.', 2);
    }
}
