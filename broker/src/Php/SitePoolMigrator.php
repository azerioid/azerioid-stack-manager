<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Php;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\AppRuntime;
use AzerioidPanel\Broker\Web\WebServers;

/**
 * Moves every PHP-FPM site onto a pool of its own (ADR A55), one site at a time.
 *
 * For each site: probe it over HTTP, re-render its vhost (which creates the pool, takes the
 * site's files over for its identity and points the web server at the new socket, with the
 * driver's own validate-and-restore), and probe again. A site that answered before and does
 * not answer now is put back on the shared pool at once, marked, and left for an operator;
 * the other sites carry on. Pools no site uses any more are taken out at the end.
 *
 * The scheduler starts it (`vhost.phppool.converge`) in a transient unit of its own, like
 * A49: a host with many sites outlives one broker call.
 */
final class SitePoolMigrator
{
    public const CONVERGE_UNIT = 'azerioid-site-php-pools';

    /** Root-only: the panel user must not be able to rewrite what happened. */
    public const STATE_FILE = '/etc/azerioid-panel/site-php-pools.json';

    public const INSTALLING_MARKER = '/run/azerioid-panel-installing';

    private readonly SitePool $pools;

    /** @param int $pollMicros pause between HTTP probes of a site that just moved (tests pass 0) */
    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
        private readonly int $pollMicros = 1000000,
    ) {
        $this->pools = new SitePool($config, $runtime);
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $sites = $this->sites();
        $pending = array_values(array_filter($sites, static fn (array $s): bool => $s['state'] === 'pending'));
        $shared = array_values(array_filter($sites, static fn (array $s): bool => $s['state'] === 'shared'));
        $stale = $this->stale($sites);
        $state = $this->readState();
        $running = $this->convergeRunning();
        $migrated = $pending === [] && $stale === [];

        return [
            'migrated' => $migrated && $shared === [],
            'sites' => $sites,
            'pending' => array_column($pending, 'domain'),
            'shared' => $shared,
            'stale' => $this->paths($stale),
            'last_attempt' => $state,
            'running' => $running,
            'auto_eligible' => !$migrated && !$running,
            'verdict' => match (true) {
                $migrated && $shared === [] => 'OK: every PHP site runs in a pool of its own; no site\'s PHP can open another site\'s files.',
                $migrated => 'ATTENTION: ' . count($shared) . ' site(s) were put back on the shared PHP pool because they stopped answering after the move ('
                    . implode(', ', array_column($shared, 'domain')) . '). Fix the site, then retry: azerioid vhost php-pool apply --confirm',
                default => 'PENDING: ' . count($pending) . ' site(s) to move to a pool of their own'
                    . ($stale !== [] ? ', ' . count($stale) . ' unused pool(s) to take out' : '')
                    . '. It is done automatically, or now with: azerioid vhost php-pool apply --confirm',
            },
        ];
    }

    /**
     * Operator: moves pending sites, and retries those put back on the shared pool.
     *
     * @return array<string,mixed>
     */
    public function apply(string $confirm, ?string $only = null): array
    {
        Validator::typedConfirm($confirm, Validator::ISOLATE_PHP_CONFIRM);
        if ($this->convergeRunning()) {
            throw new BrokerException('An automatic PHP pool migration is running (' . self::CONVERGE_UNIT . '.service); wait for it, then check: azerioid vhost php-pool status', 3);
        }

        return $this->run('operator', true, $only);
    }

    /** @return array<string,mixed> */
    public function converge(bool $now): array
    {
        if ($this->runtime->fileExists(self::INSTALLING_MARKER)) {
            return ['started' => false, 'reason' => 'install.sh is running'];
        }
        if ($now) {
            return $this->run('automatic', false, null);
        }
        $status = $this->status();
        if (!$status['auto_eligible']) {
            return ['started' => false, 'reason' => $status['running'] ? 'a migration is already running' : 'nothing to do'];
        }
        if ($this->updateInProgress()) {
            return ['started' => false, 'reason' => 'a panel self-update is in progress'];
        }
        $start = $this->runtime->exec([
            '/usr/bin/systemd-run',
            '--unit=' . self::CONVERGE_UNIT,
            '--collect',
            '--description=AZERIOID per-site PHP pools (ADR A55)',
            rtrim($this->config->panelRoot, '/') . '/broker',
            'vhost.phppool.converge',
            'now',
        ], null, 30);
        if (!$start->ok()) {
            throw new BrokerException('Could not start the PHP pool migration unit: ' . trim($start->stderr . ' ' . $start->stdout), 1);
        }

        return ['started' => true, 'unit' => self::CONVERGE_UNIT . '.service'];
    }

    /** Per-site switches: open_basedir on or off. */
    public function setOpenBasedir(string $domain, bool $on): array
    {
        $site = $this->site($domain);
        $this->pools->saveSettings($domain, ['open_basedir' => $on]);
        if ($site !== null && $site['state'] === 'isolated') {
            $this->pools->ensure($domain, $site['php_version'], $site['root']);
        }

        return ['domain' => $domain] + $this->pools->settings($domain);
    }

    /** @return array<string,mixed> */
    private function run(string $trigger, bool $retryShared, ?string $only): array
    {
        if ($this->updateInProgress()) {
            throw new BrokerException('A panel self-update is in progress; refusing to move sites between PHP pools underneath it.', 3);
        }
        $sites = $this->sites();
        if ($only !== null) {
            $sites = array_values(array_filter($sites, static fn (array $s): bool => $s['domain'] === $only));
            if ($sites === []) {
                throw new BrokerException("{$only} is not a PHP-FPM site the panel manages.", 3);
            }
        }
        $todo = array_values(array_filter(
            $sites,
            static fn (array $s): bool => $s['state'] === 'pending' || ($retryShared && $s['state'] === 'shared'),
        ));

        $startedAt = $this->runtime->now();
        $this->writeState(['result' => 'running', 'trigger' => $trigger, 'started_at' => $startedAt]);
        $results = [];
        foreach ($todo as $site) {
            $results[$site['domain']] = $this->moveSite($site);
        }
        $removed = $only === null ? $this->pruneStale() : [];

        $failed = array_filter($results, static fn (array $r): bool => $r['result'] !== 'isolated');
        $state = [
            'result' => $failed === [] ? 'ok' : 'partial',
            'trigger' => $trigger,
            'started_at' => $startedAt,
            'finished_at' => $this->runtime->now(),
            'sites' => $results,
            'removed_pools' => $removed,
        ];
        $this->writeState($state);

        return ['changed' => $results !== [] || $removed !== []] + $state;
    }

    /**
     * @param  array{domain:string, root:string, php_version:string, state:string}  $site
     * @return array{result:string, before:int, after:int, reason?:string}
     */
    private function moveSite(array $site): array
    {
        $domain = $site['domain'];
        $driver = WebServers::for($this->config);
        $before = $this->httpCode($domain);
        if ($site['state'] === 'shared') {
            $this->pools->saveSettings($domain, ['isolated' => true]);
        }
        try {
            $driver->updateVhost($this->runtime, $this->config, $domain, []);
        } catch (\Throwable $e) {
            // The driver put the old vhost file back; the site never left the shared pool.
            $this->putBack($domain, $e->getMessage());

            return ['result' => 'shared', 'before' => $before, 'after' => $this->httpCode($domain), 'reason' => $e->getMessage()];
        }

        $after = $this->httpCode($domain);
        for ($i = 0; $i < 3 && $this->broke($before, $after); $i++) {
            $this->pause();
            $after = $this->httpCode($domain);
        }
        if (!$this->broke($before, $after)) {
            return ['result' => 'isolated', 'before' => $before, 'after' => $after];
        }

        $reason = "HTTP {$before} before the move, {$after} after";
        $this->putBack($domain, $reason);
        try {
            $driver->updateVhost($this->runtime, $this->config, $domain, []);
        } catch (\Throwable $e) {
            $reason .= '; restoring the shared pool also failed: ' . $e->getMessage();
        }
        $this->pools->prune($domain, null);

        return ['result' => 'shared', 'before' => $before, 'after' => $this->httpCode($domain), 'reason' => $reason];
    }

    private function putBack(string $domain, string $reason): void
    {
        $this->pools->saveSettings($domain, ['isolated' => false, 'reason' => mb_substr($reason, 0, 300)]);
    }

    /** A site that answered (any status below 500) and now does not, or fails server-side. */
    private function broke(int $before, int $after): bool
    {
        return $before > 0 && $before < 500 && ($after === 0 || $after >= 500);
    }

    /** @return list<string> removed pool files */
    private function pruneStale(): array
    {
        $removed = $this->paths($this->stale($this->sites()));
        foreach ($removed as $path) {
            $this->runtime->deleteFile($path);
        }
        if ($removed !== []) {
            foreach ($this->runtime->phpVersions() as $version) {
                $this->reloadFpm($version);
            }
        }

        return $removed;
    }

    /**
     * Pool files no site is served by: a deleted site, a site that no longer runs PHP-FPM, an
     * old PHP version, or a site put back on the shared pool.
     *
     * @param  list<array<string,mixed>>  $sites
     * @return array<string, array<string,string>>  pool name => [version => path]
     */
    private function stale(array $sites): array
    {
        $keep = [];
        foreach ($sites as $site) {
            if ($site['state'] === 'isolated') {
                $keep[SitePool::name($site['domain'])] = $site['php_version'];
            }
        }
        $stale = [];
        foreach ($this->pools->poolFiles() as $name => $versions) {
            foreach ($versions as $version => $path) {
                if (($keep[$name] ?? null) !== $version && !$this->pendingSite($sites, $name, (string) $version)) {
                    $stale[$name][(string) $version] = $path;
                }
            }
        }

        return $stale;
    }

    /**
     * @param  array<string, array<string,string>>  $stale
     * @return list<string>
     */
    private function paths(array $stale): array
    {
        $out = [];
        foreach ($stale as $versions) {
            foreach ($versions as $path) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /** A pool the migration is about to (re)use is not stale. */
    private function pendingSite(array $sites, string $name, string $version): bool
    {
        foreach ($sites as $site) {
            if ($site['state'] === 'pending' && SitePool::name($site['domain']) === $name && $site['php_version'] === $version) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every PHP-FPM site the panel manages, and where it stands:
     * isolated (served by its own pool), pending, or shared (put back; see reason).
     *
     * @return list<array{domain:string, root:string, php_version:string, state:string, open_basedir:bool, reason:?string}>
     */
    private function sites(): array
    {
        $out = [];
        foreach (WebServers::for($this->config)->listVhosts($this->runtime, $this->config) as $vhost) {
            $domain = (string) ($vhost['domain'] ?? '');
            $version = (string) ($vhost['php_version'] ?? '');
            $root = (string) ($vhost['root'] ?? '');
            if ($domain === '' || !empty($vhost['readonly']) || ($vhost['type'] ?? '') !== 'php' || $version === '' || $root === ''
                || AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM) !== AppRuntime::FPM
                || !preg_match('/^[a-z0-9.-]+$/i', $domain)) {
                continue;
            }
            $settings = $this->pools->settings($domain);
            $state = !$settings['isolated'] ? 'shared' : ($this->pools->served($domain, $version) ? 'isolated' : 'pending');
            $out[] = [
                'domain' => $domain,
                'root' => $root,
                'php_version' => $version,
                'state' => $state,
                'pool' => SitePool::name($domain),
                'open_basedir' => $settings['open_basedir'],
                'reason' => $settings['reason'],
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function site(string $domain): ?array
    {
        foreach ($this->sites() as $site) {
            if ($site['domain'] === $domain) {
                return $site;
            }
        }

        return null;
    }

    private function httpCode(string $domain): int
    {
        foreach ([['https', 443], ['http', 80]] as [$scheme, $port]) {
            $r = $this->runtime->exec([
                '/usr/bin/curl', '-sk', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15',
                '--resolve', "{$domain}:{$port}:127.0.0.1", "{$scheme}://{$domain}/",
            ], null, 25);
            $code = (int) trim($r->stdout);
            if ($code > 0) {
                return $code;
            }
        }

        return 0;
    }

    private function reloadFpm(string $version): void
    {
        $unit = \AzerioidPanel\Broker\Os\DistroPaths::for($this->runtime, $this->config)->phpFpmUnit($version);
        if (!$this->runtime->exec(['/usr/bin/systemctl', 'reload', $unit], null, 60)->ok()) {
            $this->runtime->exec(['/usr/bin/systemctl', 'restart', $unit], null, 60);
        }
    }

    private function pause(): void
    {
        if ($this->pollMicros > 0) {
            usleep($this->pollMicros);
        }
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        if (!$this->runtime->fileExists(self::STATE_FILE)) {
            return [];
        }
        $decoded = json_decode($this->runtime->readFile(self::STATE_FILE), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void
    {
        $this->runtime->mkdir(dirname(self::STATE_FILE), 0750);
        $this->runtime->writeFile(self::STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
    }

    private function convergeRunning(): bool
    {
        $r = $this->runtime->exec(['/usr/bin/systemctl', 'is-active', self::CONVERGE_UNIT . '.service'], null, 10);

        return in_array(trim($r->stdout), ['active', 'activating'], true);
    }

    private function updateInProgress(): bool
    {
        $r = $this->runtime->exec(['/usr/bin/pgrep', '-f', 'panel\.update\.apply'], null, 10);

        return $r->ok() && trim($r->stdout) !== '';
    }
}
