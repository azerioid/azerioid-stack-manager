<?php

namespace App\Jobs;

use App\Models\PanelUpdateOperation;
use App\Services\Broker\BrokerCallException;
use App\Services\Broker\BrokerClient;
use AzerioidPanel\Broker\Panel\PanelUpdater;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunPanelUpdateJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $operationId)
    {
    }

    public function handle(BrokerClient $broker): void
    {
        $operation = PanelUpdateOperation::query()->find($this->operationId);
        if ($operation === null) {
            return;
        }

        if (PanelUpdateOperation::query()
            ->where('status', 'running')
            ->where('id', '!=', $operation->id)
            ->exists()) {
            $this->release(15);

            return;
        }

        $operation->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $operationKey = 'panel-up-' . $operation->id;

        try {
            $response = $broker->call(
                'panel.update.apply',
                [],
                [
                    'operation_id' => $operationKey,
                    'confirm' => PanelUpdater::CONFIRM,
                ],
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
                'rolled_back' => (bool) ($response->data['rolled_back'] ?? false),
                'finished_at' => now(),
            ]);
        } catch (BrokerCallException $e) {
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
