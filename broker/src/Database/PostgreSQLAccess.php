<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Database;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Systemd;
use AzerioidPanel\Broker\Validator;

/**
 * Per-database PostgreSQL pg_hba.conf host lines, then reload (no restart).
 * Existing local/peer and loopback lines are left untouched.
 */
final class PostgreSQLAccess
{
    public function __construct(private readonly Runtime $runtime)
    {
    }

    /**
     * @param  list<string>  $ips
     * @return array{user:string,hba_path:string,reloaded:bool}
     */
    public function apply(string $dbName, string $user, string $mode, array $ips): array
    {
        $dbName = Validator::dbName($dbName);
        $user = Validator::userName($user);
        $path = $this->hbaPath();
        $original = $this->runtime->readFile($path);
        $stripped = $this->stripBlock($original, $dbName);
        $block = $this->block($dbName, $user, $mode, $ips, $this->authMethod($original));
        $next = $block === '' ? rtrim($stripped) . "\n" : rtrim($stripped) . "\n" . $block;
        if ($next !== $original) {
            $this->runtime->writeFile($path, $next, 0640);
        }
        $this->reload();

        return [
            'user' => $user,
            'hba_path' => $path,
            'reloaded' => true,
        ];
    }

    private function reload(): void
    {
        $last = null;
        foreach ($this->postgresUnits() as $unit) {
            try {
                Systemd::control($this->runtime, 'reload', $unit);

                return;
            } catch (BrokerException $e) {
                $last = $e;
            }
        }
        if ($this->runtime->fileExists('/usr/bin/pg_ctlcluster')) {
            foreach ($this->runtime->glob('/etc/postgresql/*/main/pg_hba.conf') as $hba) {
                if (preg_match('#/postgresql/(\d+)/main/#', $hba, $m) === 1) {
                    $result = $this->runtime->exec(['/usr/bin/pg_ctlcluster', $m[1], 'main', 'reload'], null, 30);
                    if ($result->ok()) {
                        return;
                    }
                }
            }
        }
        if ($last !== null) {
            throw $last;
        }
        throw new BrokerException('Failed to reload PostgreSQL after pg_hba.conf change.', 1);
    }

    /** @return list<string> */
    private function postgresUnits(): array
    {
        $units = [];
        foreach ($this->runtime->glob('/etc/postgresql/*/main/postgresql.conf') as $path) {
            if (preg_match('#/postgresql/(\d+)/main/#', $path, $m) === 1) {
                $units[] = 'postgresql@' . $m[1] . '-main';
            }
        }
        $units[] = 'postgresql';

        return array_values(array_unique($units));
    }

    /**
     * @param  list<string>  $ips
     */
    private function block(string $dbName, string $user, string $mode, array $ips, string $auth): string
    {
        if ($mode === 'localhost') {
            return '';
        }
        $db = $this->hbaIdent($dbName);
        $role = $this->hbaIdent($user);
        $lines = ['# AZERIOID-DB-ACCESS-BEGIN ' . $dbName];
        $cidrs = $mode === 'global' ? ['0.0.0.0/0'] : array_map([DbAccessPolicy::class, 'pgCidr'], $ips);
        foreach ($cidrs as $cidr) {
            $lines[] = 'host ' . $db . ' ' . $role . ' ' . $cidr . ' ' . $auth;
        }
        $lines[] = '# AZERIOID-DB-ACCESS-END ' . $dbName;

        return implode("\n", $lines) . "\n";
    }

    private function stripBlock(string $content, string $dbName): string
    {
        $quoted = preg_quote($dbName, '/');
        $stripped = preg_replace(
            '/^# AZERIOID-DB-ACCESS-BEGIN ' . $quoted . '\n.*?# AZERIOID-DB-ACCESS-END ' . $quoted . '\n/sm',
            '',
            $content
        );

        return is_string($stripped) ? $stripped : $content;
    }

    private function hbaIdent(string $name): string
    {
        return '"' . Validator::dbName($name) . '"';
    }

    private function authMethod(string $hba): string
    {
        if (preg_match('/\bscram-sha-256\b/', $hba) === 1) {
            return 'scram-sha-256';
        }
        if (preg_match('/\bmd5\b/', $hba) === 1) {
            return 'md5';
        }

        return 'scram-sha-256';
    }

    private function hbaPath(): string
    {
        $candidates = array_merge(
            $this->runtime->glob('/etc/postgresql/*/main/pg_hba.conf'),
            $this->runtime->glob('/var/lib/pgsql/*/data/pg_hba.conf'),
            ['/var/lib/pgsql/data/pg_hba.conf']
        );
        foreach ($candidates as $path) {
            if ($this->runtime->fileExists($path)) {
                return $path;
            }
        }
        throw new BrokerException('pg_hba.conf not found.', 3);
    }
}
