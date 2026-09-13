<?php

namespace App\Console\Commands\Azerioid;

use App\Support\Format;
use AzerioidPanel\Broker\Validator;
use Illuminate\Console\Command;

class DbCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:db
        {action : list|add|del|edit|access}
        {subcommand? : show|set (with access)}
        {--engine= : mariadb|postgresql|mongodb}
        {--name= : Database name}
        {--user= : Database user (defaults to name)}
        {--mode= : localhost|specific|global (access set)}
        {--ip= : Comma-separated IPs/CIDRs (access set --mode=specific)}
        {--confirm : Required for --mode=global}
        {--reset-password : Generate and reveal a new password (edit)}
        {--json : JSON output (list/access show)}';

    protected $description = 'Manage databases via broker db.* actions';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listDb(),
            'add' => $this->addDb(),
            'del', 'delete', 'rm' => $this->delDb(),
            'edit' => $this->editDb(),
            'access' => $this->accessDb(),
            default => $this->invalidAction(),
        };
    }

    private function listDb(): int
    {
        try {
            $engine = trim((string) $this->option('engine'));
            $stdin = $engine !== '' ? ['engine' => $engine] : [];
            $data = $this->brokerData('db.list', [], $stdin, null, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        $databases = $data['databases'] ?? $data['items'] ?? [];
        if ($this->wantsJson()) {
            $safe = array_map(static function ($row) {
                if (! is_array($row)) {
                    return $row;
                }
                unset($row['password'], $row['passwd'], $row['secret']);

                return $row;
            }, is_array($databases) ? $databases : []);

            return $this->emitData([
                'engine' => $data['engine'] ?? $engine ?? null,
                'databases' => $safe,
            ]);
        }

        $rows = [];
        foreach ((array) $databases as $db) {
            if (! is_array($db)) {
                continue;
            }
            $users = $db['users'] ?? [];
            $userStr = is_array($users)
                ? implode(',', array_map(static fn ($u) => is_array($u) ? (string) ($u['user'] ?? '') : (string) $u, $users))
                : '';
            $access = is_array($db['access'] ?? null) ? $db['access'] : [];
            $rows[] = [
                (string) ($db['name'] ?? ''),
                Format::bytes((int) ($db['size_bytes'] ?? 0)),
                (string) ($db['table_count'] ?? ''),
                $userStr,
                (string) ($access['label'] ?? 'Localhost only'),
                ! empty($db['protected']) ? 'yes' : 'no',
            ];
        }

        return $this->emitTable(['name', 'size', 'tables', 'users', 'access', 'protected'], $rows);
    }

    private function addDb(): int
    {
        try {
            $engine = $this->requireEngine();
            $name = Validator::dbName((string) $this->option('name'));
            $user = Validator::userName((string) ($this->option('user') ?: $name));
            $password = Format::password();
            Validator::password($password);

            $res = $this->brokerCall('db.add', [$name, $user], [
                'password' => $password,
                'engine' => $engine,
            ]);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
            }

            $this->line("Created database {$name} (engine={$engine}, user={$user}).");
            $this->line('One-time password (will not be shown again):');
            $this->line($password);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function delDb(): int
    {
        try {
            $engine = $this->requireEngine();
            $name = Validator::dbName((string) $this->option('name'));
            $res = $this->brokerCall('db.del', [$name], ['engine' => $engine]);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
            }
            $this->info("Deleted database {$name}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function editDb(): int
    {
        try {
            if (! $this->option('reset-password')) {
                throw new \RuntimeException('db edit currently supports --reset-password only.');
            }
            $engine = $this->requireEngine();
            $user = Validator::userName((string) ($this->option('user') ?: $this->option('name')));
            $password = Format::password();
            Validator::password($password);

            $res = $this->brokerCall('db.resetpw', [$user], [
                'password' => $password,
                'engine' => $engine,
            ]);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
            }

            $this->line("Reset password for user {$user} (engine={$engine}).");
            $this->line('One-time password (will not be shown again):');
            $this->line($password);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function accessDb(): int
    {
        $sub = strtolower(trim((string) $this->argument('subcommand')));
        if ($sub === 'show') {
            return $this->accessShow();
        }
        if ($sub === 'set') {
            return $this->accessSet();
        }
        $this->error('Unknown db access action. Use: show|set');

        return self::INVALID;
    }

    private function accessShow(): int
    {
        try {
            $engine = $this->requireEngine();
            $name = Validator::dbName((string) $this->option('name'));
            $data = $this->brokerData('db.access.show', [$name], ['engine' => $engine], null, false);
            if ($this->wantsJson()) {
                return $this->emitData($data);
            }
            $access = is_array($data['access'] ?? null) ? $data['access'] : [];
            $this->line('Engine: ' . (string) ($data['engine'] ?? $engine));
            $this->line('Database: ' . (string) ($data['name'] ?? $name));
            $this->line('Mode: ' . (string) ($access['label'] ?? 'Localhost only'));
            $this->line('Enforcement: ' . (string) ($access['enforcement_label'] ?? ''));
            if (! empty($access['caveat'])) {
                $this->warn((string) $access['caveat']);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function accessSet(): int
    {
        try {
            $engine = $this->requireEngine();
            $name = Validator::dbName((string) $this->option('name'));
            $mode = Validator::accessMode((string) $this->option('mode'));
            if ($mode === 'global' && ! $this->option('confirm')) {
                throw new \RuntimeException(
                    'Global mode requires --confirm (exposes this database port to the entire internet, protected only by the password).'
                );
            }
            $ips = [];
            if ($mode === 'specific') {
                $ips = Validator::accessIps($mode, (string) $this->option('ip'));
            } elseif (trim((string) $this->option('ip')) !== '') {
                throw new \RuntimeException('IP list is only valid with --mode=specific.');
            }
            $stdin = [
                'engine' => $engine,
                'name' => $name,
                'mode' => $mode,
                'ips' => $ips,
            ];
            if ($mode === 'global') {
                $stdin['confirm'] = Validator::GLOBAL_ACCESS_CONFIRM;
            }
            $data = $this->brokerData('db.access.set', [$name], $stdin);
            if ($this->wantsJson()) {
                return $this->emitData($data);
            }
            $after = is_array($data['after'] ?? null) ? $data['after'] : [];
            $this->info('Remote access updated for ' . $name . ' (' . $engine . '): ' . (string) ($after['label'] ?? $mode));
            if (! empty($after['caveat'])) {
                $this->warn((string) $after['caveat']);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function requireEngine(): string
    {
        $engine = strtolower(trim((string) $this->option('engine')));
        if ($engine === '') {
            throw new \RuntimeException('--engine=mariadb|postgresql|mongodb is required.');
        }

        return $engine;
    }

    private function invalidAction(): int
    {
        $this->error('Unknown db action. Use: list|add|del|edit|access');

        return self::INVALID;
    }
}
