<?php

namespace App\Console\Commands\Azerioid;

use App\Support\Format;
use AzerioidPanel\Broker\Validator;
use Illuminate\Console\Command;

class DbCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:db
        {action : list|add|del|edit}
        {--engine= : mariadb|postgresql|mongodb}
        {--name= : Database name}
        {--user= : Database user (defaults to name)}
        {--reset-password : Generate and reveal a new password (edit)}
        {--json : JSON output (list)}';

    protected $description = 'Manage databases via broker db.* actions';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listDb(),
            'add' => $this->addDb(),
            'del', 'delete', 'rm' => $this->delDb(),
            'edit' => $this->editDb(),
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
            // Never include passwords in list output.
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
            $rows[] = [
                (string) ($db['name'] ?? ''),
                Format::bytes((int) ($db['size_bytes'] ?? 0)),
                (string) ($db['table_count'] ?? ''),
                $userStr,
                ! empty($db['protected']) ? 'yes' : 'no',
            ];
        }

        return $this->emitTable(['name', 'size', 'tables', 'users', 'protected'], $rows);
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
                throw new \RuntimeException((string) $res->error);
            }

            // Use line() (not info/warn components) so output is captured reliably in tests/CI.
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
                throw new \RuntimeException((string) $res->error);
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
            // resetpw takes the DB user; UI uses user name. Allow --user or fall back to --name.
            $user = Validator::userName((string) ($this->option('user') ?: $this->option('name')));
            $password = Format::password();
            Validator::password($password);

            $res = $this->brokerCall('db.resetpw', [$user], [
                'password' => $password,
                'engine' => $engine,
            ]);
            if (! $res->ok) {
                throw new \RuntimeException((string) $res->error);
            }

            $this->line("Reset password for user {$user} (engine={$engine}).");
            $this->line('One-time password (will not be shown again):');
            $this->line($password);

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
        $this->error('Unknown db action. Use: list|add|del|edit');

        return self::INVALID;
    }
}
