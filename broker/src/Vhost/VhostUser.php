<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Vhost;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Os\DistroPaths;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;

/**
 * Per-vhost Linux identity for terminal sessions, the file manager, SFTP and cron.
 *
 * Permission model (ADR A49):
 * - Each vhost identity has a primary group of its own, named after it (az-vh-X:az-vh-X),
 *   and owns the docroot tree as user:that-group.
 * - The readers — web server, site PHP pool users and azerioid-supervised — are members of
 *   every vhost group, because they serve or run every site. Vhost identities are members
 *   of their own group only, so one site's identity is "other" on every other docroot.
 * - Directories: 2770 (setgid), files: 0660 — files a reader creates (PHP uploads, Laravel
 *   logs, Octane caches) inherit the vhost group, so the identity can still manage them.
 *
 * Before A49 every identity shared the primary group azerioid-vhosts, which made each
 * site's identity a group member on every other site's 2770 docroot. Existing hosts are
 * moved off it by VhostIsolationMigrator; until then ensure() leaves a legacy identity as
 * it is rather than half-migrating it in the middle of a request.
 */
final class VhostUser
{
    /** The shared group every identity used to have as its primary group (pre-A49). */
    public const LEGACY_GROUP = 'azerioid-vhosts';
    public const PREFIX = 'az-vh-';

    public static function username(string $domain): string
    {
        $slug = strtolower($domain);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            throw new BrokerException('Cannot derive vhost user name.', 2);
        }
        $name = self::PREFIX . $slug;
        if (strlen($name) <= 32) {
            return $name;
        }
        $hash = substr(hash('crc32b', $domain), 0, 8);

        return substr(self::PREFIX . $hash . '-' . $slug, 0, 32);
    }

    /** The vhost's own group: same name as its identity (a user-private group). */
    public static function groupName(string $domain): string
    {
        return self::username($domain);
    }

    /**
     * @return array{username: string, root: string, domain: string}
     */
    public static function ensure(Runtime $runtime, Config $config, string $domain, string $root): array
    {
        if ($runtime->getuid() !== 0) {
            return ['username' => self::username($domain), 'root' => $root, 'domain' => $domain];
        }

        $username = self::username($domain);
        $group = self::groupName($domain);
        $exists = self::userExists($runtime, $username);

        if ($exists && self::primaryGroup($runtime, $username) === self::LEGACY_GROUP) {
            // Not migrated yet. Converting one identity here, inside a File Manager or
            // Terminal request, would skip the migration's verification and rollback;
            // VhostIsolationMigrator moves every identity in one journalled pass.
            self::applyOwnership($runtime, $root, $username, self::LEGACY_GROUP);
            self::record($runtime, $config, $domain, $username, $root);

            return ['username' => $username, 'root' => $root, 'domain' => $domain];
        }

        $changed = self::ensureVhostGroup($runtime, $config, $group);
        if (!$exists) {
            $runtime->exec([
                '/usr/sbin/useradd',
                '--system',
                '--home-dir', $root,
                '--shell', '/bin/bash',
                '--gid', $group,
                '--comment', 'AZERIOID Stack Manager vhost user for ' . $domain,
                $username,
            ], null, 30);
        }

        self::applyOwnership($runtime, $root, $username, $group);
        self::claimTop($runtime, $config, $root, $username, $group);
        self::record($runtime, $config, $domain, $username, $root);
        if ($changed) {
            // The web server must not restart inside the request that created the vhost
            // (the panel is served through it), so Caddy's restart is deferred.
            self::refreshReaders($runtime, $config, true);
        }

        return ['username' => $username, 'root' => $root, 'domain' => $domain];
    }

    public static function deprovision(Runtime $runtime, Config $config, string $domain): void
    {
        if ($runtime->getuid() !== 0) {
            return;
        }
        $path = dirname($config->managedComponentsPath) . '/vhost-users.json';
        $meta = self::load($runtime, $path);
        $username = (string) ($meta['users'][$domain]['username'] ?? '');
        if ($username === '') {
            $username = self::username($domain);
        }
        if ($username !== '' && self::userExists($runtime, $username)) {
            $runtime->exec(['/usr/sbin/userdel', '--force', $username], null, 30);
        }
        // userdel keeps a user-private group that still has members, and the readers
        // are members of every vhost group.
        if ($username !== '' && str_starts_with($username, self::PREFIX) && self::groupExists($runtime, $username)) {
            $runtime->exec(['/usr/sbin/groupdel', $username], null, 30);
        }
        unset($meta['users'][$domain]);
        self::save($runtime, $path, $meta);
    }

    public static function applyOwnership(Runtime $runtime, string $root, string $username, string $group): void
    {
        if ($runtime->getuid() !== 0 || !$runtime->isDir($root)) {
            return;
        }
        $runtime->exec(['/usr/bin/chown', '-R', $username . ':' . $group, $root], null, 120);
        $runtime->exec([
            '/bin/sh', '-c',
            'find ' . escapeshellarg($root) . ' -type d -exec chmod 2770 {} +',
        ], null, 120);
        $runtime->exec([
            '/bin/sh', '-c',
            'find ' . escapeshellarg($root) . ' -type f -exec chmod 0660 {} +',
        ], null, 120);
    }

    /**
     * A docroot like /data/www/app/public is created with its parent: root's, 0755. The
     * site's identity could not write its own app (.env, storage) and the directory was
     * open to every other site until the isolation converge closed it (A49-E1). Only a
     * directory root still owns is taken — one another account owns may be shared.
     */
    private static function claimTop(Runtime $runtime, Config $config, string $root, string $username, string $group): void
    {
        $www = rtrim($config->wwwRoot, '/') . '/';
        if ($runtime->getuid() !== 0 || !str_starts_with($root, $www)) {
            return;
        }
        $top = $www . explode('/', substr($root, strlen($www)))[0];
        if ($top === rtrim($root, '/') || !$runtime->isDir($top)) {
            return;
        }
        $owner = trim($runtime->exec(['/usr/bin/stat', '-c', '%u', $top], null, 10)->stdout);
        if ($owner !== '0') {
            return;
        }
        $runtime->exec(['/usr/bin/chown', '-h', $username . ':' . $group, $top], null, 30);
        $runtime->exec(['/usr/bin/chmod', '2770', $top], null, 30);
    }

    /**
     * @return array{users: array<string, array<string, mixed>>}
     */
    public static function load(Runtime $runtime, string $path): array
    {
        if (!$runtime->fileExists($path)) {
            return ['users' => []];
        }
        $decoded = json_decode($runtime->readFile($path), true);
        if (!is_array($decoded) || !isset($decoded['users']) || !is_array($decoded['users'])) {
            return ['users' => []];
        }

        return ['users' => $decoded['users']];
    }

    /**
     * Create the vhost's group if needed and make every reader a member.
     *
     * @return bool whether any reader's membership changed (its running processes then
     *              still hold the old group list and must be refreshed)
     */
    public static function ensureVhostGroup(Runtime $runtime, Config $config, string $group): bool
    {
        if (!self::groupExists($runtime, $group)) {
            $runtime->exec(['/usr/sbin/groupadd', '--system', $group], null, 30);
        }
        $changed = false;
        foreach (self::readerUsers($runtime, $config) as $user) {
            if (self::userInGroup($runtime, $user, $group)) {
                continue;
            }
            $runtime->exec(['/usr/bin/gpasswd', '-a', $user, $group], null, 30);
            $changed = true;
        }

        return $changed;
    }

    /**
     * Supplementary groups are fixed when a process starts, so a reader added to a new
     * vhost group cannot open that docroot until it re-reads them.
     *
     * php-fpm, Apache and nginx start as root and call initgroups() for each worker they
     * spawn, so a graceful reload is enough. Caddy runs as its own user from the start
     * and only a restart gives it the new list.
     */
    public static function refreshReaders(Runtime $runtime, Config $config, bool $deferWebRestart): void
    {
        $paths = DistroPaths::for($runtime, $config);
        $web = $config->webService;
        $units = [$paths->nginxUnit(), $paths->apacheUnit()];
        foreach ($runtime->phpVersions() as $ver) {
            $units[] = $paths->phpFpmUnit($ver);
        }
        foreach (array_unique(array_filter($units, static fn (string $u): bool => $u !== '' && $u !== $web)) as $unit) {
            $runtime->exec(['/usr/bin/systemctl', 'try-reload-or-restart', $unit], null, 60);
        }
        if ($web === '') {
            return;
        }
        if (!str_contains($web, 'caddy')) {
            $runtime->exec(['/usr/bin/systemctl', 'try-reload-or-restart', $web], null, 60);

            return;
        }
        if ($deferWebRestart) {
            $deferred = $runtime->exec([
                '/usr/bin/systemd-run',
                '--on-active=3',
                '--collect',
                '--unit=azerioid-web-regroup-' . substr(hash('sha256', $runtime->now() . random_int(0, PHP_INT_MAX)), 0, 12),
                '--description=AZERIOID: restart the web server to pick up a new vhost group',
                '/usr/bin/systemctl', 'try-restart', $web,
            ], null, 30);
            if ($deferred->ok()) {
                return;
            }
        }
        $runtime->exec(['/usr/bin/systemctl', 'try-restart', $web], null, 60);
    }

    /**
     * Every account that serves or runs site code and so must open every docroot.
     *
     * web_user in broker.json can lag the actual process user (e.g. www-data on a Caddy
     * stack), so known web/PHP process users that exist on this host are included too.
     *
     * @return list<string>
     */
    public static function readerUsers(Runtime $runtime, Config $config): array
    {
        $candidates = array_merge(
            [$config->webUser, $config->phpUser],
            DistroPaths::for($runtime, $config)->webProcessUsers(),
            [SupervisedUser::USERNAME],
        );
        $out = [];
        foreach ($candidates as $user) {
            $user = trim((string) $user);
            if ($user === '' || $user === 'root' || isset($out[$user]) || str_starts_with($user, self::PREFIX)) {
                continue;
            }
            if (!self::userExists($runtime, $user)) {
                continue;
            }
            $out[$user] = $user;
        }

        return array_values($out);
    }

    public static function primaryGroup(Runtime $runtime, string $username): ?string
    {
        $r = $runtime->exec(['/usr/bin/id', '-gn', $username], null, 10);

        return $r->ok() && trim($r->stdout) !== '' ? trim($r->stdout) : null;
    }

    /** The group a vhost's docroot is (or must be) owned by: its own, or the legacy one until migrated. */
    public static function docrootGroup(Runtime $runtime, string $domain): string
    {
        $primary = self::primaryGroup($runtime, self::username($domain));

        return $primary === self::LEGACY_GROUP ? self::LEGACY_GROUP : self::groupName($domain);
    }

    public static function groupExists(Runtime $runtime, string $group): bool
    {
        return $runtime->exec(['/usr/bin/getent', 'group', $group], null, 10)->ok();
    }

    public static function userExists(Runtime $runtime, string $username): bool
    {
        return $runtime->exec(['/usr/bin/id', '-u', $username], null, 10)->ok();
    }

    public static function userInGroup(Runtime $runtime, string $username, string $group): bool
    {
        $r = $runtime->exec(['/usr/bin/id', '-nG', $username], null, 10);
        if (!$r->ok()) {
            return false;
        }
        $groups = preg_split('/\s+/', trim($r->stdout)) ?: [];

        return in_array($group, $groups, true);
    }

    /**
     * @param  array{users: array<string, array<string, mixed>>}  $meta
     */
    private static function record(Runtime $runtime, Config $config, string $domain, string $username, string $root): void
    {
        $path = self::metadataPath($runtime, $config);
        $meta = self::load($runtime, $path);
        $meta['users'][$domain] = [
            'username' => $username,
            'root' => $root,
            'provisioned_at' => $runtime->now(),
        ];
        self::save($runtime, $path, $meta);
    }

    /**
     * @param  array{users: array<string, array<string, mixed>>}  $meta
     */
    private static function save(Runtime $runtime, string $path, array $meta): void
    {
        $runtime->mkdir(dirname($path), 0750);
        $runtime->writeFile(
            $path,
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            0640
        );
    }

    private static function metadataPath(Runtime $runtime, Config $config): string
    {
        return dirname($config->managedComponentsPath) . '/vhost-users.json';
    }
}
