<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Database;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

/**
 * Per-database MariaDB host grants. Localhost / 127.0.0.1 are never dropped.
 * New host entries are created (hash-cloned) before stale remote hosts are dropped.
 */
final class MariaDBAccess
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    private const PLUGINS = ['mysql_native_password', 'caching_sha2_password', 'ed25519'];

    public function __construct(private readonly Runtime $runtime)
    {
    }

    /**
     * @param  list<string>  $ips
     * @return array{user:string,hosts_before:list<string>,hosts_after:list<string>}
     */
    public function apply(string $dbName, string $user, string $mode, array $ips): array
    {
        $dbName = Validator::dbName($dbName);
        $user = Validator::userName($user);
        $before = $this->hostsForUser($user);
        $originalHosts = $before;
        $desiredRemote = $this->desiredRemote($mode, $ips);
        $source = $this->hashSourceHost($before);

        foreach (self::LOCAL_HOSTS as $local) {
            if (!in_array($local, $before, true)) {
                $this->cloneHost($user, $source, $local, $dbName);
                $before[] = $local;
                $source = $local;
            }
        }

        foreach ($desiredRemote as $host) {
            if (!in_array($host, $before, true)) {
                $this->cloneHost($user, $source, $host, $dbName);
                $before[] = $host;
            } else {
                $this->grant($user, $host, $dbName);
            }
        }

        foreach ($before as $host) {
            if (in_array($host, self::LOCAL_HOSTS, true) || in_array($host, $desiredRemote, true)) {
                continue;
            }
            $this->dropHost($user, $host);
        }

        $this->runtime->dbExec('FLUSH PRIVILEGES');

        $after = array_values(array_unique(array_merge(self::LOCAL_HOSTS, $desiredRemote)));

        return [
            'user' => $user,
            'hosts_before' => $this->unique($originalHosts),
            'hosts_after' => $after,
        ];
    }

    public function userForDatabase(string $dbName): string
    {
        $rows = $this->runtime->dbQuery(
            "SELECT User FROM mysql.db WHERE Db = ? AND User <> '' ORDER BY User",
            [$dbName]
        );
        foreach ($rows as $row) {
            $user = (string) ($row['User'] ?? '');
            if ($user !== '' && $user !== 'root') {
                try {
                    return Validator::userName($user);
                } catch (BrokerException) {
                }
            }
        }
        throw new BrokerException("No grant user found for database {$dbName}.", 3);
    }

    /**
     * @param  list<string>  $ips
     * @return list<string>
     */
    private function desiredRemote(string $mode, array $ips): array
    {
        if ($mode === 'localhost') {
            return [];
        }
        if ($mode === 'global') {
            return ['%'];
        }

        return DbAccessPolicy::mariadbHosts($ips);
    }

    /** @param  list<string>  $existing */
    private function hashSourceHost(array $existing): string
    {
        foreach (self::LOCAL_HOSTS as $local) {
            if (in_array($local, $existing, true)) {
                return $local;
            }
        }
        if ($existing !== []) {
            return $existing[0];
        }
        throw new BrokerException('Database user has no host entries to clone credentials from.', 1);
    }

    private function cloneHost(string $user, string $fromHost, string $toHost, string $dbName): void
    {
        $fromHost = SqlIdent::mysqlHost($fromHost);
        $toHost = SqlIdent::mysqlHost($toHost);
        $rows = $this->runtime->dbQuery(
            'SELECT plugin, authentication_string FROM mysql.user WHERE User = ? AND Host = ?',
            [$user, $fromHost]
        );
        if ($rows === []) {
            throw new BrokerException("Cannot clone MariaDB user {$user}@{$fromHost}: not found.", 1);
        }
        $hash = trim((string) ($rows[0]['authentication_string'] ?? $rows[0]['AUTHENTICATION_STRING'] ?? ''));
        $plugin = (string) ($rows[0]['plugin'] ?? $rows[0]['PLUGIN'] ?? '');
        if (!in_array($plugin, self::PLUGINS, true)) {
            throw new BrokerException("Cannot clone MariaDB auth plugin {$plugin} to a remote host.", 3);
        }
        if ($hash === '' || preg_match('/[\'\\\\;\s\x00\x1a"]/', $hash) === 1) {
            throw new BrokerException(
                'Cannot clone MariaDB user: authentication string is empty or unsafe (len=' . strlen($hash) . ').',
                1
            );
        }
        $ident = $this->ident($user, $toHost);
        $this->runtime->dbExec(
            'CREATE USER IF NOT EXISTS ' . $ident . ' IDENTIFIED VIA ' . $plugin . ' USING ?',
            [$hash]
        );
        $this->grant($user, $toHost, $dbName);
    }

    private function grant(string $user, string $host, string $dbName): void
    {
        $this->runtime->dbExec(
            'GRANT ALL PRIVILEGES ON `' . SqlIdent::mysql($dbName) . '`.* TO ' . $this->ident($user, $host)
        );
    }

    private function dropHost(string $user, string $host): void
    {
        $this->runtime->dbExec('DROP USER IF EXISTS ' . $this->ident($user, $host));
    }

    private function ident(string $user, string $host): string
    {
        return '`' . SqlIdent::mysql($user) . '`@`' . SqlIdent::mysqlHost($host) . '`';
    }

    /** @return list<string> */
    private function hostsForUser(string $user): array
    {
        $rows = $this->runtime->dbQuery('SELECT Host FROM mysql.user WHERE User = ?', [$user]);
        $hosts = [];
        foreach ($rows as $row) {
            $host = (string) ($row['Host'] ?? '');
            if ($host === '') {
                continue;
            }
            try {
                $hosts[] = SqlIdent::mysqlHost($host);
            } catch (BrokerException) {
            }
        }

        return $this->unique($hosts);
    }

    /**
     * @param  list<string>  $hosts
     * @return list<string>
     */
    private function unique(array $hosts): array
    {
        return array_values(array_unique($hosts));
    }
}
