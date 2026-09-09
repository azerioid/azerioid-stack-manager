<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Database;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Systemd;

final class DbAccessBind
{
    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    /**
     * @return array{changed:bool,listen:string,path:?string,restarted:bool}
     */
    public function sync(string $engine, bool $public): array
    {
        return match ($engine) {
            'mariadb' => $this->mariadb($public),
            'postgresql' => $this->postgresql($public),
            'mongodb' => $this->mongodb($public),
            default => throw new BrokerException('Unknown database engine.', 2),
        };
    }

    private function mariadb(bool $public): array
    {
        $want = $public ? '0.0.0.0' : '127.0.0.1';
        $path = $this->firstExisting([
            $this->config->mariadbServerCnf,
            '/etc/mysql/mariadb.conf.d/50-server.cnf',
            '/etc/my.cnf.d/server.cnf',
            '/etc/my.cnf.d/mariadb-server.cnf',
        ]);
        if ($path === null) {
            return ['changed' => false, 'listen' => $want, 'path' => null, 'restarted' => false];
        }
        $original = $this->runtime->readFile($path);
        $cnf = $original;
        if (preg_match('/^#?\s*bind-address\s*=/mi', $cnf) === 1) {
            $cnf = preg_replace('/^#?\s*bind-address\s*=.*$/mi', 'bind-address = ' . $want, $cnf, 1) ?? $cnf;
        } elseif (preg_match('/^\[mysqld\]/mi', $cnf) === 1) {
            $cnf = preg_replace('/^\[mysqld\]/mi', "[mysqld]\nbind-address = {$want}", $cnf, 1) ?? $cnf;
        } else {
            $cnf = "[mysqld]\nbind-address = {$want}\n\n" . $cnf;
        }
        if ($cnf === $original) {
            return ['changed' => false, 'listen' => $want, 'path' => $path, 'restarted' => false];
        }
        $this->runtime->writeFile($path, $cnf, 0644);
        $this->control('restart', ['mariadb', 'mysql']);

        return ['changed' => true, 'listen' => $want, 'path' => $path, 'restarted' => true];
    }

    private function postgresql(bool $public): array
    {
        $want = $public ? '*' : 'localhost';
        $quoted = "'" . $want . "'";
        $path = $this->firstExisting(array_merge(
            $this->runtime->glob('/etc/postgresql/*/main/postgresql.conf'),
            $this->runtime->glob('/var/lib/pgsql/*/data/postgresql.conf'),
            ['/var/lib/pgsql/data/postgresql.conf']
        ));
        if ($path === null) {
            return ['changed' => false, 'listen' => $want, 'path' => null, 'restarted' => false];
        }
        $original = $this->runtime->readFile($path);
        $cnf = $original;
        if (preg_match('/^#?listen_addresses\s*=/m', $cnf) === 1) {
            $cnf = preg_replace('/^#?listen_addresses\s*=.*/m', 'listen_addresses = ' . $quoted, $cnf) ?? $cnf;
        } else {
            $cnf .= "\nlisten_addresses = {$quoted}\n";
        }
        if ($cnf === $original) {
            return ['changed' => false, 'listen' => $want, 'path' => $path, 'restarted' => false];
        }
        $this->runtime->writeFile($path, $cnf, 0644);
        $this->control('restart', $this->postgresUnits());

        return ['changed' => true, 'listen' => $want, 'path' => $path, 'restarted' => true];
    }

    private function mongodb(bool $public): array
    {
        $want = $public ? '0.0.0.0' : '127.0.0.1';
        $path = $this->firstExisting(['/etc/mongod.conf', '/etc/mongodb.conf']);
        if ($path === null) {
            return ['changed' => false, 'listen' => $want, 'path' => null, 'restarted' => false];
        }
        $original = $this->runtime->readFile($path);
        $cnf = $original;
        if (preg_match('/^\s*bindIp\s*:/m', $cnf) === 1) {
            $cnf = preg_replace('/^\s*bindIp\s*:.*/m', '  bindIp: ' . $want, $cnf) ?? $cnf;
        } elseif (preg_match('/^net\s*:/m', $cnf) === 1) {
            $cnf = preg_replace('/^net\s*:/m', "net:\n  bindIp: {$want}", $cnf, 1) ?? $cnf;
        } else {
            $cnf = rtrim($cnf) . "\nnet:\n  bindIp: {$want}\n";
        }
        if ($cnf === $original) {
            return ['changed' => false, 'listen' => $want, 'path' => $path, 'restarted' => false];
        }
        $this->runtime->writeFile($path, $cnf, 0644);
        $this->control('restart', ['mongod', 'mongodb']);

        return ['changed' => true, 'listen' => $want, 'path' => $path, 'restarted' => true];
    }

    /** @param  list<string>  $units */
    private function control(string $action, array $units): void
    {
        $last = null;
        foreach ($units as $unit) {
            try {
                Systemd::control($this->runtime, $action, $unit);

                return;
            } catch (BrokerException $e) {
                $last = $e;
            }
        }
        if ($last !== null) {
            throw $last;
        }
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

    /** @param  list<string>  $paths */
    private function firstExisting(array $paths): ?string
    {
        foreach ($paths as $path) {
            if ($path !== '' && $this->runtime->fileExists($path)) {
                return $path;
            }
        }

        return null;
    }
}
