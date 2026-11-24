<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Redis;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Secrets;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\VhostUser;
use AzerioidPanel\Broker\Web\WebServers;

/**
 * Per-site Redis users (A111).
 *
 * The packaged Redis is one instance on 127.0.0.1:6379 whose `default` user has no password, and
 * loopback is no boundary between sites (A54): every site's PHP, terminal and cron can reach it.
 * The panel owns an ACL file instead:
 *
 *   user default off                       (once enforced; on/nopass until then, see below)
 *   user azerioid-admin on #<sha256> ~* &* +@all          the panel's own
 *   user az-vh-<slug> on #<sha256> resetchannels ~<slug>:* &<slug>:* +@all -@admin -@dangerous …
 *
 * A site reaches only keys and channels under its own prefix, and none of the commands that act on
 * the whole instance (FLUSHALL/FLUSHDB, KEYS, CONFIG, DEBUG, …). The file holds hashes only; the
 * passwords are kept root-only in redis-acl.json and shown to the operator for the site's .env.
 *
 * A fresh install starts enforced. An existing install keeps `default` open until the operator
 * enforces it — sites that use Redis today connect without a password, and turning that off
 * before they have their own users would break them without warning.
 */
final class RedisAcl
{
    public const STATE = '/etc/azerioid-panel/redis-acl.json';

    public const ACL_FILE = '/etc/redis/azerioid-users.acl';

    public const ADMIN_USER = 'azerioid-admin';

    public const CONFIRM_ENFORCE = 'REDIS-PER-SITE';

    /** @var list<string> */
    private const CONF_CANDIDATES = ['/etc/redis/redis.conf', '/etc/redis.conf'];

    /**
     * Besides @admin and @dangerous, the commands that list names across the whole keyspace or
     * all channels (SCAN, RANDOMKEY, PUBSUB) — Redis checks values against the key pattern, not
     * the names those return. Laravel's connection naming stays allowed.
     */
    private const SITE_EXTRA = '-scan -randomkey -pubsub +client|setname +client|getname +client|id';

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
    ) {
    }

    public function confPath(): ?string
    {
        foreach (self::CONF_CANDIDATES as $path) {
            if ($this->runtime->fileExists($path)) {
                return $path;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $state = $this->state();
        $sites = [];
        foreach ((array) ($state['sites'] ?? []) as $domain => $site) {
            $sites[] = ['domain' => (string) $domain, 'user' => (string) ($site['user'] ?? ''), 'prefix' => (string) ($site['prefix'] ?? '')];
        }

        return [
            'installed' => $this->confPath() !== null,
            'managed' => $this->isManaged(),
            'enforced' => (bool) ($state['enforced'] ?? false),
            'sites' => $sites,
        ];
    }

    /**
     * Take over the instance's users (component install: $enforce true from the start). Idempotent.
     */
    public function setup(bool $enforce): void
    {
        $conf = $this->confPath() ?? throw new BrokerException('Redis is not installed (redis.conf not found).', 3);
        $wasManaged = $this->isManaged();
        $state = $this->state();
        if (!isset($state['admin_password'])) {
            $state['admin_password'] = Secrets::generatePassword();
        }
        $state['enforced'] = ($state['enforced'] ?? false) || $enforce;
        $state['sites'] = (array) ($state['sites'] ?? []);
        $this->saveState($state);
        $this->writeAclFile($state);

        $content = $this->runtime->readFile($conf);
        $next = self::wireAclFile($content);
        if ($next !== $content) {
            $this->runtime->writeFile($conf, $next, 0640);
        }
        // aclfile cannot be set at runtime; a restart picks it up (and the file's users).
        // If Redis will not come up with it, put redis.conf back as it was and restart again:
        // a cache that is down takes every site using it down with it.
        $unit = $this->unit();
        try {
            $restart = $this->runtime->exec(['/usr/bin/systemctl', 'restart', $unit], null, 60);
            if (!$restart->ok()) {
                throw new BrokerException('Redis did not restart with the panel\'s ACL file: ' . trim($restart->stderr), 1);
            }
            $this->assertAdminWorks();
        } catch (BrokerException $e) {
            $this->runtime->writeFile($conf, $content, 0640);
            $this->runtime->exec(['/usr/bin/systemctl', 'restart', $unit], null, 60);
            if ($this->runtime->fileExists(self::STATE) && !$wasManaged) {
                $this->runtime->deleteFile(self::STATE);
            }
            throw $e;
        }
    }

    /** @return array<string,string> the site's connection settings, password included */
    public function enableSite(string $domain): array
    {
        $domain = $this->assertVhost($domain);
        $state = $this->managedState();
        if (!isset($state['sites'][$domain])) {
            $slug = VhostUser::slug($domain);
            $state['sites'][$domain] = [
                'user' => VhostUser::username($domain),
                'prefix' => $slug . ':',
                'password' => Secrets::generatePassword(),
            ];
            $this->saveState($state);
            $this->reload($state);
        }

        return $this->connection($domain, $state['sites'][$domain]);
    }

    /** @return array<string,string> */
    public function showSite(string $domain): array
    {
        $domain = Validator::domain($domain);
        $site = $this->state()['sites'][$domain] ?? null;
        if (!is_array($site)) {
            throw new BrokerException("Redis is not enabled for {$domain}.", 3);
        }

        return $this->connection($domain, $site);
    }

    /** @return array{domain:string,removed:bool} */
    public function disableSite(string $domain): array
    {
        $domain = Validator::domain($domain);
        $state = $this->state();
        if (!isset($state['sites'][$domain])) {
            return ['domain' => $domain, 'removed' => false];
        }
        unset($state['sites'][$domain]);
        $this->saveState($state);
        if ($this->isManaged()) {
            $this->reload($state);
        }

        return ['domain' => $domain, 'removed' => true];
    }

    /**
     * Turn `default` off: from now on only per-site users (and the panel) get in. A typed confirm,
     * because every site still connecting without a password stops working.
     *
     * @return array<string,mixed>
     */
    public function enforce(string $confirm): array
    {
        Validator::typedConfirm($confirm, self::CONFIRM_ENFORCE);
        $state = $this->managedState();
        $state['enforced'] = true;
        $this->saveState($state);
        $this->reload($state);
        // The point of it: a client without credentials is refused.
        $anon = $this->runtime->exec(['/usr/bin/redis-cli', '-h', '127.0.0.1', '-p', '6379', 'PING'], null, 15);
        if (str_contains(strtoupper($anon->stdout . $anon->stderr), 'PONG')) {
            throw new BrokerException('Redis still answers a client without credentials after enforcing per-site users.', 1);
        }

        return $this->status();
    }

    /** Component uninstall: the users go with the service. */
    public function forget(): void
    {
        foreach ([self::STATE, self::ACL_FILE] as $path) {
            if ($this->runtime->fileExists($path)) {
                $this->runtime->deleteFile($path);
            }
        }
    }

    /**
     * `aclfile` pointed at the panel's file, line by line (A39 rule: never a regex across lines).
     * `requirepass` and inline `user` lines conflict with an ACL file and are commented out.
     */
    public static function wireAclFile(string $content): string
    {
        $lines = explode("\n", $content);
        $found = false;
        foreach ($lines as $i => $line) {
            $trim = ltrim($line);
            if (preg_match('/^aclfile\s/i', $trim) === 1) {
                $lines[$i] = $found ? '# ' . $line . '  # azerioid-panel A111' : 'aclfile ' . self::ACL_FILE;
                $found = true;
            } elseif (preg_match('/^(requirepass|user)\s/i', $trim) === 1) {
                $lines[$i] = '# ' . $line . '  # azerioid-panel A111: users live in ' . self::ACL_FILE;
            }
        }
        if (!$found) {
            $tail = end($lines) === '' ? array_pop($lines) : null;
            $lines[] = '# azerioid-panel A111: per-site users';
            $lines[] = 'aclfile ' . self::ACL_FILE;
            if ($tail !== null) {
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $state */
    public static function renderAcl(array $state): string
    {
        // No comment lines: Redis refuses an ACL file with anything but `user` lines and will
        // not start (found against a real redis-server 7.4).
        $out = ($state['enforced'] ?? false)
            ? "user default off\n"
            : "user default on nopass ~* &* +@all\n";
        $out .= 'user ' . self::ADMIN_USER . ' on #' . hash('sha256', (string) $state['admin_password']) . " ~* &* +@all\n";
        foreach ((array) ($state['sites'] ?? []) as $site) {
            $user = (string) $site['user'];
            $prefix = (string) $site['prefix'];
            if (preg_match('/^[a-z0-9-]+:$/', $prefix) !== 1 || preg_match('/^az-vh-[a-z0-9-]+$/', $user) !== 1) {
                continue;
            }
            $out .= "user {$user} on #" . hash('sha256', (string) $site['password'])
                . " resetchannels ~{$prefix}* &{$prefix}* +@all -@admin -@dangerous " . self::SITE_EXTRA . "\n";
        }

        return $out;
    }

    // ------------------------------------------------------------------ internals

    /** @param array<string,mixed> $state */
    private function reload(array $state): void
    {
        $this->writeAclFile($state);
        $load = $this->adminCli(['ACL', 'LOAD']);
        if (!$load->ok() || !str_contains(strtoupper($load->stdout), 'OK')) {
            throw new BrokerException('Redis refused the panel\'s ACL file: ' . trim($load->stdout . ' ' . $load->stderr), 1);
        }
    }

    /** @param array<string,mixed> $state */
    private function writeAclFile(array $state): void
    {
        // Readable by redis (it loads the file), hashes only, written whole each time.
        $this->runtime->writeFile(self::ACL_FILE, self::renderAcl($state), 0640);
        $this->runtime->exec(['/usr/bin/chown', 'root:redis', self::ACL_FILE], null, 10);
        $this->runtime->chmod(self::ACL_FILE, 0640);
    }

    private function assertAdminWorks(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $ping = $this->adminCli(['PING']);
            if ($ping->ok() && str_contains(strtoupper($ping->stdout), 'PONG')) {
                return;
            }
            usleep(250_000);
        }
        throw new BrokerException('Redis does not accept the panel\'s admin user after loading its ACL file.', 1);
    }

    /** redis-cli as the panel admin; the password goes in the environment, never on argv. */
    private function adminCli(array $args): \AzerioidPanel\Broker\ExecResult
    {
        return $this->runtime->exec(
            ['/usr/bin/redis-cli', '-h', '127.0.0.1', '-p', '6379', '--user', self::ADMIN_USER, '--no-auth-warning', ...$args],
            null,
            15,
            ['REDISCLI_AUTH' => (string) ($this->state()['admin_password'] ?? '')]
        );
    }

    /** @param array<string,mixed> $site  @return array<string,string> */
    private function connection(string $domain, array $site): array
    {
        return [
            'domain' => $domain,
            'host' => '127.0.0.1',
            'port' => '6379',
            'user' => (string) $site['user'],
            'password' => (string) $site['password'],
            'prefix' => (string) $site['prefix'],
        ];
    }

    private function assertVhost(string $domain): string
    {
        $domain = Validator::domain($domain);
        foreach (WebServers::for($this->config)->listVhosts($this->runtime, $this->config) as $v) {
            if (($v['domain'] ?? '') === $domain) {
                return $domain;
            }
        }
        throw new BrokerException("No vhost named {$domain}.", 3);
    }

    /** State of an instance the panel already manages; sets it up (not enforced) on first use. @return array<string,mixed> */
    private function managedState(): array
    {
        if (!$this->isManaged()) {
            $this->setup(false);
        }

        return $this->state();
    }

    private function isManaged(): bool
    {
        $conf = $this->confPath();
        if ($conf === null || !$this->runtime->fileExists(self::STATE)) {
            return false;
        }
        foreach (explode("\n", $this->runtime->readFile($conf)) as $line) {
            if (trim($line) === 'aclfile ' . self::ACL_FILE) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        if (!$this->runtime->fileExists(self::STATE)) {
            return [];
        }
        $decoded = json_decode($this->runtime->readFile(self::STATE), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $state */
    private function saveState(array $state): void
    {
        $this->runtime->mkdir(dirname(self::STATE), 0750);
        $this->runtime->writeFile(self::STATE, (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
        $this->runtime->chmod(self::STATE, 0600);
    }

    private function unit(): string
    {
        foreach (['redis-server', 'redis'] as $unit) {
            if ($this->runtime->exec(['/usr/bin/systemctl', 'cat', $unit], null, 10)->ok()) {
                return $unit;
            }
        }

        return 'redis-server';
    }
}
