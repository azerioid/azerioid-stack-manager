<?php

namespace App\Jobs;

use App\Models\ComponentOperation;
use App\Services\Broker\BrokerCallException;
use App\Services\Broker\BrokerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RunComponentOperationJob implements ShouldQueue
{
    use Queueable;

    /** Package operations are slow; a docker build or a repo add can take minutes. */
    public int $timeout = 900;

    /**
     * Deliberately unbounded, with retryUntil() providing the ceiling instead.
     *
     * This used to be `tries = 1` alongside `release(15)` for contention, which
     * silently destroyed the queued-behind operation: a released job is re-reserved
     * with attempts = 2, the worker fails it as MaxAttemptsExceeded *before*
     * handle() runs, and because handle() never ran, the row was never updated —
     * so the UI showed an install stuck at "pending" forever. With retryUntil()
     * set, Laravel bounds by deadline rather than attempt count, so waiting for a
     * lock no longer burns the job's only life.
     */
    public int $tries = 0;

    public const LOCK_KEY = 'azerioid:component-operation';

    public function __construct(public int $operationId)
    {
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(BrokerClient $broker): void
    {
        $operation = ComponentOperation::query()->find($this->operationId);
        if ($operation === null) {
            return;
        }
        // A retry after the work already finished must not run it twice.
        if (! in_array($operation->status, ['queued', 'running'], true)) {
            return;
        }

        // Only one package operation at a time: apt and dnf take their own locks,
        // and two concurrent installs would fight over them. This used to be a
        // read-then-act check against the operations table, which two workers could
        // both pass. An atomic lock cannot be raced, and it expires on its own if a
        // worker dies holding it.
        $lock = Cache::lock(self::LOCK_KEY, $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        try {
            $this->runOperation($broker, $operation);
        } finally {
            $lock->release();
        }
    }

    /**
     * The row is left in a terminal state on every path so the Components page,
     * which gates on queued/running rows, can never be wedged by this job.
     */
    public function failed(?\Throwable $e): void
    {
        ComponentOperation::query()
            ->where('id', $this->operationId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'error' => $e !== null
                    ? 'Job failed: ' . $e->getMessage()
                    : 'Job failed without reaching the broker.',
                'finished_at' => now(),
            ]);
    }

    private function runOperation(BrokerClient $broker, ComponentOperation $operation): void
    {
        $operation->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $brokerAction = $operation->action === 'uninstall' ? 'component.uninstall' : 'component.install';
        $operationKey = 'op-' . $operation->id;

        try {
            $stdin = ['operation_id' => $operationKey];
            if (is_array($operation->options) && $operation->options !== []) {
                $stdin['options'] = $operation->options;
            }
            $response = $broker->call(
                $brokerAction,
                [$operation->component_id],
                $stdin,
                900,
            );

            $logResponse = $broker->call('component.operation.log', [$operationKey], [], 30, audit: false);
            $lines = $logResponse->ok ? ($logResponse->data['lines'] ?? []) : [];

            if (! $response->ok) {
                $operation->update([
                    'status' => 'failed',
                    'error' => $response->error,
                    'log' => is_array($lines) ? implode("\n", $lines) : null,
                    'finished_at' => now(),
                ]);

                return;
            }

            $operation->update([
                'status' => 'completed',
                'error' => null,
                'log' => is_array($lines) ? implode("\n", $lines) : null,
                'finished_at' => now(),
            ]);
        } catch (BrokerCallException $e) {
            Log::error('Component operation failed', ['operation' => $operation->id, 'error' => $e->getMessage()]);
            $operation->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }
}
