<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Vhost;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Os\DistroPaths;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;

/**
 * Per-vhost Linux identity for terminal sessions and the file manager.
 *
 * Permission model (v1):
 * - Vhost user owns the docroot tree (user:azerioid-vhosts).
 * - Web server user (caddy/www-data) is in group azerioid-vhosts for read+execute on served files.
 * - Directories: 2770 (setgid), files: 0660 — new files inherit group for PHP-FPM reads.
 */
final class VhostUser
{
    public const GROUP = 'azerioid-vhosts';
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

    /**
     * @return array{username: string, root: string, domain: string}
     */
    public static function ensure(Runtime $runtime, Config $config, string $domain, string $root): array
    {
        if ($runtime->getuid() !== 0) {
            return ['username' => self::username($domain), 'root' => $root, 'domain' => $domain];
        }

        self::ensureGroup($runtime, $config);
        $username = self::username($domain);
        if (!$runtime->fileExists('/etc/passwd') || !self::userExists($runtime, $username)) {
            $runtime->exec([
                '/usr/sbin/useradd',
                '--system',
                '--home-dir', $root,
                '--shell', '/bin/bash',
                '--gid', self::GROUP,
                '--comment', 'AZERIOID Stack Manager vhost user for ' . $domain,
                $username,
            ], null, 30);
        }

        self::applyOwnership($runtime, $root, $username);
        self::record($runtime, $config, $domain, $username, $root);

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
        unset($meta['users'][$domain]);
        self::save($runtime, $path, $meta);
    }

    public static function applyOwnership(Runtime $runtime, string $root, string $username): void
    {
        if ($runtime->getuid() !== 0 || !$runtime->isDir($root)) {
            return;
        }
        $runtime->exec(['/usr/bin/chown', '-R', $username . ':' . self::GROUP, $root], null, 120);
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

    private static function ensureGroup(Runtime $runtime, Config $config): void
    {
        $check = $runtime->exec(['/usr/bin/getent', 'group', self::GROUP], null, 10);
        if (!$check->ok()) {
            $runtime->exec(['/usr/sbin/groupadd', '--system', self::GROUP], null, 30);
        }
        // web_user in broker.json can lag the actual process user (e.g. www-data on a
        // Caddy stack). Add every reader that must open docroots: configured users plus
        // known web/PHP process users that exist on this host.
        $changed = false;
        foreach (self::readerUsers($runtime, $config) as $user) {
            if (self::userInGroup($runtime, $user, self::GROUP)) {
                continue;
            }
            $runtime->exec(['/usr/sbin/usermod', '-aG', self::GROUP, $user], null, 30);
            $changed = true;
        }
        if ($changed) {
            // Supplementary groups are copied at process start; FPM/apache/nginx
            // otherwise keep serving 404 "File not found" until restart.
            foreach (DistroPaths::for($runtime, $config)->reloadableServiceUnits() as $unit) {
                if ($unit === '') {
                    continue;
                }
                $runtime->exec(['/usr/bin/systemctl', 'try-restart', $unit], null, 30);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function readerUsers(Runtime $runtime, Config $config): array
    {
        $candidates = array_merge(
            [$config->webUser, $config->phpUser],
            DistroPaths::for($runtime, $config)->webProcessUsers(),
            [SupervisedUser::USERNAME],
        );
        $out = [];
        foreach ($candidates as $user) {
            $user = trim((string) $user);
            if ($user === '' || $user === 'root' || isset($out[$user])) {
                continue;
            }
            if (!self::userExists($runtime, $user)) {
                continue;
            }
            $out[$user] = $user;
        }

        return array_values($out);
    }

    private static function userExists(Runtime $runtime, string $username): bool
    {
        return $runtime->exec(['/usr/bin/id', '-u', $username], null, 10)->ok();
    }

    private static function userInGroup(Runtime $runtime, string $username, string $group): bool
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
