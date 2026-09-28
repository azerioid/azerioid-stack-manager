<?php

namespace App\Console\Commands\Azerioid;

use Illuminate\Console\Command;

/**
 * Elasticsearch health, indices and password (B7, ADR A54).
 */
class SearchCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:search
        {action : status|indices|password-reset}
        {--json : JSON output}';

    protected $description = 'Elasticsearch: health, indices, reset the elastic user\'s password';

    public function handle(): int
    {
        try {
            $data = match (strtolower((string) $this->argument('action'))) {
                'status' => $this->brokerData('search.status', [], [], 60, false),
                'indices' => $this->brokerData('search.indices', [], [], 60, false),
                'password-reset' => $this->brokerData('search.password.reset', [], [], 180),
                default => throw new \InvalidArgumentException('Use: azerioid search status|indices|password-reset'),
            };
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if (isset($data['indices'])) {
            foreach ($data['indices'] as $i) {
                $this->line(str_pad($i['name'], 40).' '.$i['health'].'  '.$i['docs'].' docs');
            }

            return self::SUCCESS;
        }
        if (isset($data['password'])) {
            $this->warn('Shown once.');
            $this->line('user     : elastic');
            $this->line('password : '.$data['password']);
            $this->line('url      : '.$data['url']);

            return self::SUCCESS;
        }
        foreach (['status', 'version', 'nodes', 'active_shards', 'unassigned_shards', 'heap_used_percent', 'heap_max_mb', 'url'] as $k) {
            $this->line(str_pad($k, 18).': '.(string) ($data[$k] ?? ''));
        }

        return self::SUCCESS;
    }
}
