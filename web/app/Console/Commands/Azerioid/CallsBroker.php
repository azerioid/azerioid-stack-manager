<?php

namespace App\Console\Commands\Azerioid;

use App\Services\Broker\BrokerCallException;
use App\Services\Broker\BrokerClient;
use App\Services\Broker\BrokerResponse;
use Illuminate\Console\Command;

trait CallsBroker
{
    protected function brokerCall(
        string $action,
        array $args = [],
        array $stdin = [],
        ?int $timeout = null,
        bool $audit = true,
    ): BrokerResponse {
        $this->configureBrokerForCli();

        // Tag audit trail so CLI vs UI is distinguishable without schema changes.
        $stdin = array_merge(['origin' => 'cli'], $stdin);

        /** @var BrokerClient $client */
        $client = app(BrokerClient::class);

        return $client->call($action, $args, $stdin, $timeout, $audit);
    }

    protected function brokerData(
        string $action,
        array $args = [],
        array $stdin = [],
        ?int $timeout = null,
        bool $audit = true,
    ): array {
        $response = $this->brokerCall($action, $args, $stdin, $timeout, $audit);
        if (! $response->ok) {
            throw new BrokerCallException((string) ($response->error ?: 'Broker call failed.'), $response->code);
        }

        return is_array($response->data) ? $response->data : [];
    }

    protected function configureBrokerForCli(): void
    {
        $path = (string) config('azerioid.broker.path', '/usr/local/lib/azerioid-panel/broker');
        if (is_file($path) && is_executable($path)) {
            config([
                'azerioid.broker.driver' => 'sudo',
                // Root can invoke the broker binary directly; non-root uses sudoers.
                'azerioid.broker.use_sudo' => function_exists('posix_geteuid')
                    ? posix_geteuid() !== 0
                    : true,
            ]);
        }
    }

    protected function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /** @param  array<int, string>  $headers  @param  list<list<string|int|null>>  $rows */
    protected function emitTable(array $headers, array $rows): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'headers' => $headers,
                'rows' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $data */
    protected function emitData(array $data): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    protected function failBroker(\Throwable $e): int
    {
        $this->error($e->getMessage());

        return self::FAILURE;
    }
}
