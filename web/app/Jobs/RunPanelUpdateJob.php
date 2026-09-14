<?php

namespace App\Jobs;

use App\Models\PanelUpdateOperation;
use App\Services\Broker\BrokerCallException;
use App\Services\Broker\BrokerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunPanelUpdateJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $operationId)
    {
    }

    /**
     * Prevent concurrent panel self-updates (replaces a hand-rolled
     * "another operation is running → release(15)" check).
     *
     * Operator-facing refusal of a second apply remains at dispatch time
     * (CLI / Updates page: "already queued or running"). This middleware is
     * the queue-level safety net if two jobs are already on the queue.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('panel-update'))
                ->releaseAfter(15)
                ->expireAfter(960),
        ];
    }

    public function handle(BrokerClient $broker): void
    {
        $operation = PanelUpdateOperation::query()->find($this->operationId);
        if ($operation === null) {
            return;
        }

        $operation->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $operationKey = 'panel-up-' . $operation->id;

        try {
            // Literal confirm token (do not autoload PanelUpdater here — during a
            // failed/partial deploy web/lib may be unreadable to the queue user).
            $stdin = [
                'operation_id' => $operationKey,
                'confirm' => 'PANEL-UPDATE',
            ];
            if (is_string($operation->target_tag) && $operation->target_tag !== '') {
                $stdin['tag'] = $operation->target_tag;
            }
            $response = $broker->call(
                'panel.update.apply',
                [],
                $stdin,
                900,
            );

            $logResponse = $broker->call('panel.update.operation.log', [$operationKey], [], 30, audit: false);
            $lines = $logResponse->ok ? ($logResponse->data['lines'] ?? []) : [];
            $logText = is_array($lines) ? implode("\n", $lines) : null;

            if (!$response->ok) {
                $rolledBack = str_contains((string) $response->error, 'rolled back');
                $operation->update([
                    'status' => 'failed',
                    'error' => $response->error,
                    'log' => $logText,
                    'rolled_back' => $rolledBack,
                    'finished_at' => now(),
                ]);

                return;
            }

            $operation->update([
                'status' => 'completed',
                'error' => null,
                'log' => $logText,
                'from_commit' => $response->data['from_commit'] ?? null,
                'to_commit' => $response->data['to_commit'] ?? null,
                'from_tag' => $response->data['from_tag'] ?? null,
                'to_tag' => $response->data['to_tag'] ?? null,
                'rolled_back' => (bool) ($response->data['rolled_back'] ?? false),
                'finished_at' => now(),
            ]);
        } catch (BrokerCallException|Throwable $e) {
            Log::error('Panel update failed', ['operation' => $operation->id, 'error' => $e->getMessage()]);
            $operation->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'rolled_back' => str_contains($e->getMessage(), 'rolled back'),
                'finished_at' => now(),
            ]);
        }
    }
}
