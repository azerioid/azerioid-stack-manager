<?php

namespace App\Console\Commands\Azerioid;

use Illuminate\Console\Command;

/**
 * Saved private-registry credentials (request #6, ADR A50). Vhosts refer to them by name;
 * the password is only ever read from the environment or an interactive prompt.
 */
class DockerCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:docker
        {action : registry}
        {op : list|add|del}
        {name? : registry name (add/del)}
        {--host= : registry host, e.g. ghcr.io (default Docker Hub)}
        {--username= : registry username}
        {--json : JSON output}';

    protected $description = 'Private Docker registry credentials that Docker vhosts pull with';

    public function handle(): int
    {
        if (strtolower((string) $this->argument('action')) !== 'registry') {
            $this->error('Use: azerioid docker registry list|add|del');

            return self::INVALID;
        }

        try {
            return match (strtolower((string) $this->argument('op'))) {
                'list' => $this->list(),
                'add' => $this->add(),
                'del', 'delete', 'rm' => $this->del(),
                default => $this->invalidOp(),
            };
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function invalidOp(): int
    {
        $this->error('Use: azerioid docker registry list|add|del');

        return self::INVALID;
    }

    private function list(): int
    {
        $data = $this->brokerData('docker.registry.list');
        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $rows = (array) ($data['registries'] ?? []);
        if ($rows === []) {
            $this->line('No saved registries.');

            return self::SUCCESS;
        }
        $this->table(['Name', 'Host', 'Username'], array_map(
            static fn (array $r): array => [$r['name'], $r['host'], $r['username']],
            $rows
        ));

        return self::SUCCESS;
    }

    private function add(): int
    {
        $name = (string) $this->argument('name');
        $password = (string) (getenv('AZERIOID_REGISTRY_PASSWORD') ?: '');
        if ($password === '' && $this->input->isInteractive()) {
            $password = (string) $this->secret('Password or access token (not echoed, not stored in shell history)');
        }
        if (trim($password) === '') {
            throw new \RuntimeException('Set AZERIOID_REGISTRY_PASSWORD in the environment, or run interactively. Passwords are never accepted as arguments.');
        }
        $data = $this->brokerData('docker.registry.set', [], [
            'name' => $name,
            'host' => (string) $this->option('host'),
            'username' => (string) $this->option('username'),
            'password' => $password,
        ]);
        $this->info('Saved registry '.$data['name'].' ('.$data['username'].'@'.$data['host'].').');

        return self::SUCCESS;
    }

    private function del(): int
    {
        $data = $this->brokerData('docker.registry.delete', [(string) $this->argument('name')]);
        $this->info('Deleted registry '.$data['name'].'.');

        return self::SUCCESS;
    }
}
