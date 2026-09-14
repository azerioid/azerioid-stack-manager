<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Database;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use PDO;
use PDOException;

final class PostgreSQLDriver implements DatabaseDriver
{
  /** @var list<string> */
    private const PROTECTED = ['postgres', 'template0', 'template1'];

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public function engine(): string
    {
        return 'postgresql';
    }

    public function isConfigured(): bool
    {
        return $this->config->postgresqlUser !== '' && $this->config->postgresqlPassword !== '';
    }

    public function list(): array
    {
        $rows = $this->query(
            "SELECT d.datname AS name,
                    pg_catalog.pg_database_size(d.datname) AS size_bytes,
                    pg_catalog.pg_get_userbyid(d.datdba) AS owner
             FROM pg_catalog.pg_database d
             WHERE d.datistemplate = false
             ORDER BY d.datname"
        );

        $databases = [];
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $owner = (string) ($row['owner'] ?? '');
            $databases[] = [
                'name' => $name,
                'size_bytes' => (int) $row['size_bytes'],
                'table_count' => 0,
                'users' => $owner !== '' ? [['user' => $owner, 'host' => 'local']] : [],
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

        $existing = $this->query(
            'SELECT 1 FROM pg_catalog.pg_database WHERE datname = ?',
            [$name]
        );
        if ($existing !== []) {
            throw new BrokerException("Database {$name} already exists.", 3);
        }

        $users = $this->query('SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = ?', [$user]);
        if ($users !== []) {
            throw new BrokerException("Database user {$user} already exists.", 3);
        }

        $escaped = SqlIdent::escapeLiteral($password);
        $createdRole = false;
        $createdDb = false;
        try {
            // EL defaults password_encryption=md5 while panel pg_hba uses scram-sha-256.
            // Hash new role passwords as SCRAM so TCP auth from Adminer/apps succeeds.
            $this->exec("SET password_encryption = 'scram-sha-256'");
            $this->exec(
                'CREATE ROLE "' . SqlIdent::postgres($user) . '" WITH LOGIN PASSWORD \'' . $escaped . '\''
            );
            $createdRole = true;
            $this->exec(
                'CREATE DATABASE "' . SqlIdent::postgres($name) . '" OWNER "' . SqlIdent::postgres($user) . '"'
            );
            $createdDb = true;
        } catch (\Throwable $e) {
            $this->rollback($name, $user, $createdDb, $createdRole);
            if ($e instanceof BrokerException) {
                throw $e;
            }
            throw new BrokerException($e->getMessage(), 1);
        }

        return [
            'name' => $name,
            'user' => $user,
            'hosts' => ['local'],
        ];
    }

    public function delete(string $name, string $user): array
    {
        $name = Validator::dbName($name);
        $user = Validator::userName($user);

        if ($this->isProtected($name)) {
            throw new BrokerException('Refusing to mutate a protected system database.', 3);
        }

        $existing = $this->query(
            'SELECT 1 FROM pg_catalog.pg_database WHERE datname = ?',
            [$name]
        );
        if ($existing === []) {
            throw new BrokerException('Database does not exist.', 3);
        }

        $this->exec('DROP DATABASE IF EXISTS "' . SqlIdent::postgres($name) . '" WITH (FORCE)');
        $this->exec('DROP ROLE IF EXISTS "' . SqlIdent::postgres($user) . '"');

        return ['name' => $name, 'user' => $user, 'dropped' => true];
    }

    public function resetPassword(string $user, string $password): array
    {
        $user = Validator::userName($user);
        Validator::password($password);
        $escaped = SqlIdent::escapeLiteral($password);
        $this->exec("SET password_encryption = 'scram-sha-256'");
        $this->exec('ALTER ROLE "' . SqlIdent::postgres($user) . '" WITH PASSWORD \'' . $escaped . '\'');

        return ['user' => $user, 'reset' => true];
    }

    public function dump(string $name, string $outputPath): array
    {
        $which = trim($name);
        $dumpName = ($which === '' || $which === 'all') ? 'all' : Validator::dbName($which);
        $pgpass = $this->pgpassFile();
        $args = [
            '/usr/bin/pg_dump',
            '-h',
            $this->config->postgresqlHost,
            '-p',
            (string) $this->config->postgresqlPort,
            '-U',
            $this->config->postgresqlUser,
            '--no-password',
            '-Fc',
        ];
        if ($dumpName !== 'all') {
            $args[] = $dumpName;
        } else {
            $args[] = '--all';
        }

        array_unshift($args, '/usr/bin/env', 'PGPASSFILE=' . $pgpass);
        try {
            $result = $this->runtime->exec($args, null, 600);
        } finally {
            $this->runtime->deleteFile($pgpass);
        }
        if (!$result->ok() || $result->stdout === '') {
            throw new BrokerException(trim($result->stderr) !== '' ? trim($result->stderr) : 'pg_dump failed.', 1);
        }

        $this->runtime->writeFile($outputPath, $result->stdout, 0640);

        return [
            'path' => $outputPath,
            'size_bytes' => strlen($result->stdout),
            'engine' => $this->engine(),
            'name' => $dumpName,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function query(string $sql, array $params = []): array
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    private function exec(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    private function pdo(): PDO
    {
        if (!$this->isConfigured()) {
            throw new BrokerException('PostgreSQL is not configured in broker.json.', 3);
        }
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=postgres',
            $this->config->postgresqlHost,
            $this->config->postgresqlPort
        );
        try {
            return new PDO(
                $dsn,
                $this->config->postgresqlUser,
                $this->config->postgresqlPassword,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            throw new BrokerException('PostgreSQL connection failed.', 1);
        }
    }

    private function pgpassFile(): string
    {
        $path = rtrim($this->config->stagingDir, '/') . '/pgpass-' . bin2hex(random_bytes(6));
        $this->runtime->mkdir($this->config->stagingDir, 0750);
        $line = sprintf(
            "%s:%d:*:%s:%s\n",
            $this->config->postgresqlHost,
            $this->config->postgresqlPort,
            $this->config->postgresqlUser,
            str_replace([':', '\\'], ['\\:', '\\\\'], $this->config->postgresqlPassword)
        );
        $this->runtime->writeFile($path, $line, 0600);

        return $path;
    }

    private function rollback(string $name, string $user, bool $createdDb, bool $createdRole): void
    {
        try {
            if ($createdDb) {
                $this->exec('DROP DATABASE IF EXISTS "' . SqlIdent::postgres($name) . '" WITH (FORCE)');
            }
            if ($createdRole) {
                $this->exec('DROP ROLE IF EXISTS "' . SqlIdent::postgres($user) . '"');
            }
        } catch (\Throwable) {
        }
    }

    private function isProtected(string $name): bool
    {
        return in_array(strtolower($name), self::PROTECTED, true);
    }
}
