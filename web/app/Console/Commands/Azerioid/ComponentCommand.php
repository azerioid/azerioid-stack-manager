<?php

namespace App\Console\Commands\Azerioid;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ComponentCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:component
        {action : list|install|remove}
        {id? : Component id}
        {--pkg-version= : Component package version (CLI --version maps here)}
        {--option=* : Install options as key=value (repeatable)}
        {--json : JSON output (list)}';

    protected $description = 'Manage components via broker component.* actions';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listComponents(),
            'install' => $this->installComponent(),
            'remove', 'uninstall', 'del' => $this->removeComponent(),
            default => $this->invalidAction(),
        };
    }

    private function listComponents(): int
    {
        try {
            $data = $this->brokerData('component.list', [], [], null, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        $components = $data['components'] ?? $data;
        if ($this->wantsJson()) {
            return $this->emitData(['components' => $components]);
        }

        $rows = [];
        foreach ((array) $components as $c) {
            if (! is_array($c)) {
                continue;
            }
            $rows[] = [
                (string) ($c['id'] ?? ''),
                (string) ($c['display_name'] ?? ''),
                (string) ($c['category'] ?? ''),
                (string) ($c['status'] ?? ''),
                ! empty($c['installable']) ? 'yes' : 'no',
            ];
        }

        return $this->emitTable(['id', 'name', 'category', 'status', 'installable'], $rows);
    }

    private function installComponent(): int
    {
        try {
            $id = trim((string) $this->argument('id'));
            if ($id === '') {
                throw new \RuntimeException('component install requires <id>.');
            }
            $operationId = 'cli-' . Str::uuid()->toString();
            $stdin = ['operation_id' => $operationId];
            $options = $this->parseOptions();
            $version = trim((string) $this->option('pkg-version'));
            if ($version !== '') {
                $options['version'] = $version;
            }
            if ($options !== []) {
                $stdin['options'] = $options;
            }

            $this->info("Installing {$id} (operation {$operationId})…");
            $res = $this->brokerCall('component.install', [$id], $stdin, 900);
            if (! $res->ok) {
                $log = $this->brokerCall('component.operation.log', [$operationId], [], 30, false);
                if ($log->ok && is_array($log->data['lines'] ?? null)) {
                    foreach ($log->data['lines'] as $line) {
                        $this->line((string) $line);
                    }
                }
                $this->throwBrokerFailure($res);
            }
            $this->info("Installed component {$id}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function removeComponent(): int
    {
        try {
            $id = trim((string) $this->argument('id'));
            if ($id === '') {
                throw new \RuntimeException('component remove requires <id>.');
            }
            $operationId = 'cli-' . Str::uuid()->toString();
            $this->info("Removing {$id} (operation {$operationId})…");
            $res = $this->brokerCall('component.uninstall', [$id], [
                'operation_id' => $operationId,
            ], 900);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
            }
            $this->info("Removed component {$id}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    /** @return array<string, mixed> */
    private function parseOptions(): array
    {
        $out = [];
        foreach ((array) $this->option('option') as $raw) {
            $raw = (string) $raw;
            if (! str_contains($raw, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $raw, 2);
            $k = trim($k);
            if ($k === '') {
                continue;
            }
            $out[$k] = is_numeric($v) ? $v + 0 : $v;
        }

        return $out;
    }

    private function invalidAction(): int
    {
        $this->error('Unknown component action. Use: list|install|remove');

        return self::INVALID;
    }
}
