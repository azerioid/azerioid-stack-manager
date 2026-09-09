<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Database;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class MariaDBDriver implements DatabaseDriver
{
  /** @var list<string> */
    private const PROTECTED = [
        'information_schema',
        'mysql',
        'performance_schema',
        'sys',
        'lacmp_panel',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public function engine(): string
    {
        return 'mariadb';
    }

    public function isConfigured(): bool
    {
        return $this->config->mysqlUser !== '' && $this->config->mysqlPassword !== '';
    }

    public function list(): array
    {
        $rows = $this->runtime->dbQuery(
            'SELECT s.SCHEMA_NAME AS name,
                    COALESCE(SUM(t.DATA_LENGTH + t.INDEX_LENGTH), 0) AS size_bytes,
                    COUNT(t.TABLE_NAME) AS table_count
             FROM information_schema.SCHEMATA s
             LEFT JOIN information_schema.TABLES t
               ON t.TABLE_SCHEMA = s.SCHEMA_NAME
             GROUP BY s.SCHEMA_NAME
             ORDER BY s.SCHEMA_NAME'
        );

        $usersByDb = [];
        $grants = $this->runtime->dbQuery(
            "SELECT Db, User, Host FROM mysql.db WHERE Db <> ''"
        );
        foreach ($grants as $g) {
            $db = str_replace(['\\_', '\\%'], ['_', '%'], (string) $g['Db']);
            $usersByDb[$db][] = [
                'user' => $g['User'],
                'host' => $g['Host'],
            ];
        }

        $databases = [];
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $databases[] = [
                'name' => $name,
                'size_bytes' => (int) $row['size_bytes'],
                'table_count' => (int) $row['table_count'],
                'users' => $usersByDb[$name] ?? [],
                'protected' => $this->isProtected($name),
            ];
        }

        return $databases;
    }

    public function add(string $name, string $user, string $password): array
    {
        $name = Validator::dbName($name);
        $user = Validator::userName($user);
        Validator::password($password);

        if ($this->isProtected($name)) {
            throw new BrokerException('Refusing to mutate a protected system database.', 3);
        }

        $existing = $this->runtime->dbQuery(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$name]
        );
        if ($existing !== []) {
            throw new BrokerException("Database {$name} already exists.", 3);
        }

        $users = $this->runtime->dbQuery('SELECT User, Host FROM mysql.user WHERE User = ?', [$user]);
        if ($users !== []) {
            throw new BrokerException("Database user {$user} already exists.", 3);
        }

        $createdDb = false;
        $createdHosts = [];
        try {
            $this->runtime->dbExec(
                'CREATE DATABASE `' . SqlIdent::mysql($name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $createdDb = true;
            foreach (['localhost', '127.0.0.1'] as $host) {
                $this->runtime->dbExec(
                    'CREATE USER `' . SqlIdent::mysql($user) . '`@`' . SqlIdent::mysql($host) . '` IDENTIFIED BY ?',
                    [$password]
                );
                $createdHosts[] = $host;
                $this->runtime->dbExec(
                    'GRANT ALL PRIVILEGES ON `' . SqlIdent::mysql($name) . '`.* TO `'
                    . SqlIdent::mysql($user) . '`@`' . SqlIdent::mysql($host) . '`'
                );
            }
            $this->runtime->dbExec('FLUSH PRIVILEGES');
        } catch (\Throwable $e) {
            $this->rollback($name, $user, $createdDb, $createdHosts);
            if ($e instanceof BrokerException) {
                throw $e;
            }
            throw new BrokerException($e->getMessage(), 1);
        }

        return [
            'name' => $name,
            'user' => $user,
            'hosts' => ['localhost', '127.0.0.1'],
        ];
    }

    public function delete(string $name, string $user): array
    {
        $name = Validator::dbName($name);
        $user = Validator::userName($user);

        if ($this->isProtected($name)) {
            throw new BrokerException('Refusing to mutate a protected system database.', 3);
        }

        $existing = $this->runtime->dbQuery(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$name]
        );
        if ($existing === []) {
            throw new BrokerException('Database does not exist.', 3);
        }

        $this->runtime->dbExec('DROP DATABASE IF EXISTS `' . SqlIdent::mysql($name) . '`');
        $hosts = $this->hostsForUser($user);
        if ($hosts === []) {
            $hosts = ['localhost', '127.0.0.1'];
        }
        foreach ($hosts as $host) {
            try {
                $this->runtime->dbExec(
                    'DROP USER IF EXISTS `' . SqlIdent::mysql($user) . '`@`' . SqlIdent::mysqlHost($host) . '`'
                );
            } catch (\Throwable) {
            }
        }
        $this->runtime->dbExec('FLUSH PRIVILEGES');

        return ['name' => $name, 'user' => $user, 'dropped' => true];
    }

    public function resetPassword(string $user, string $password): array
    {
        $user = Validator::userName($user);
        Validator::password($password);

        $hosts = $this->hostsForUser($user);
        if ($hosts === []) {
            $hosts = ['localhost', '127.0.0.1'];
        }
        foreach ($hosts as $host) {
            $this->runtime->dbExec(
                'ALTER USER `' . SqlIdent::mysql($user) . '`@`' . SqlIdent::mysqlHost($host) . '` IDENTIFIED BY ?',
                [$password]
            );
        }
        $this->runtime->dbExec('FLUSH PRIVILEGES');

        return ['user' => $user, 'reset' => true];
    }

    public function dump(string $name, string $outputPath): array
    {
        $which = trim($name);
        $args = [
            '/usr/bin/mysqldump',
            '--protocol=socket',
            '--socket=' . $this->config->mysqlSocket,
            '--single-transaction',
            '--quick',
            '--routines',
            '--skip-comments',
        ];
        if ($which === '' || $which === 'all') {
            $args[] = '--all-databases';
            $dumpName = 'all';
        } else {
            $dumpName = Validator::dbName($which);
            $args[] = $dumpName;
        }

        $cnf = $this->defaultsFile();
        array_splice($args, 1, 0, ['--defaults-extra-file=' . $cnf]);
        try {
            $result = $this->runtime->exec($args, null, 600);
        } finally {
            $this->runtime->deleteFile($cnf);
        }
        if (!$result->ok() || $result->stdout === '') {
            throw new BrokerException(trim($result->stderr) !== '' ? trim($result->stderr) : 'mysqldump failed.', 1);
        }

        $gzip = $this->runtime->exec(
            ['/bin/gzip', '-c'],
            $result->stdout,
            300
        );
        if (!$gzip->ok() || $gzip->stdout === '') {
            throw new BrokerException('Failed to compress database dump.', 1);
        }

        $this->runtime->writeFile($outputPath, $gzip->stdout, 0640);

        return [
            'path' => $outputPath,
            'size_bytes' => strlen($gzip->stdout),
            'engine' => $this->engine(),
            'name' => $dumpName,
        ];
    }

    /** @param list<string> $createdHosts */
    private function rollback(string $name, string $user, bool $createdDb, array $createdHosts): void
    {
        try {
            if ($createdDb) {
                $this->runtime->dbExec('DROP DATABASE IF EXISTS `' . SqlIdent::mysql($name) . '`');
            }
            foreach ($createdHosts as $host) {
                $this->runtime->dbExec(
                    'DROP USER IF EXISTS `' . SqlIdent::mysql($user) . '`@`' . SqlIdent::mysql($host) . '`'
                );
            }
            $this->runtime->dbExec('FLUSH PRIVILEGES');
        } catch (\Throwable) {
        }
    }

    private function defaultsFile(): string
    {
        $path = rtrim($this->config->stagingDir, '/') . '/mysqldump-' . bin2hex(random_bytes(6)) . '.cnf';
        $this->runtime->mkdir($this->config->stagingDir, 0750);
        $this->runtime->writeFile(
            $path,
            "[client]\nuser=" . $this->config->mysqlUser . "\npassword=" . $this->config->mysqlPassword . "\n",
            0600
        );

        return $path;
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

        return array_values(array_unique($hosts));
    }

    private function isProtected(string $name): bool
    {
        return in_array(strtolower($name), self::PROTECTED, true)
            || in_array($name, $this->config->protectedDatabases, true);
    }
}
