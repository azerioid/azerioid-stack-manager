<?php

namespace App\Jobs;

use App\Models\Operation;
use App\Services\Alerts\TelegramNotifier;
use App\Services\Broker\BrokerCallException;
use App\Services\Broker\BrokerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs one queued Operation (B5 / request #13).
 *
 * Deliberately mirrors the shape RunComponentOperationJob ended up with after the
 * A1 fixes, because those were learned the hard way:
 *
 *  - Bounded by retryUntil() rather than an attempt count, so releasing to wait for
 *    a lock does not consume the job's only life and silently destroy it.
 *  - Contention handled with an atomic cache lock, not a read-then-act check two
 *    workers could both pass.
 *  - failed() leaves the row terminal on every path, so a dead worker cannot leave
 *    an operation wedged at `running` forever.
 *
 * The lock is **per subject**, which is the point of this phase: two different
 * vhosts can build at once, while one vhost cannot build twice concurrently.
 */
class RunOperationJob implements ShouldQueue
{
    use Queueable;

    /** Long enough for a docker build pulling base layers. */
    public int $timeout = 1800;

    public int $tries = 0;

    /**
     * @param  array<string,mixed>  $stdin  full broker input, secrets included —
     *                                      carried on the queue payload, never
     *                                      written to the operations table.
     */
    public function __construct(
        public int $operationId,
        public array $stdin = [],
    ) {
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(BrokerClient $broker, TelegramNotifier $notifier): void
    {
        $operation = Operation::query()->find($this->operationId);
        if ($operation === null) {
            return;
        }
        // Cancelled while queued, or already finished by a previous attempt.
        if (! $operation->isActive()) {
            return;
        }

        $lock = Cache::lock($operation->lockKey(), $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        try {
            $this->run($broker, $notifier, $operation);
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $e): void
    {
        Operation::query()
            ->where('id', $this->operationId)
            ->whereIn('status', Operation::ACTIVE)
            ->update([
                'status' => Operation::STATUS_FAILED,
                'error' => $e !== null
                    ? 'Job failed: ' . $e->getMessage()
                    : 'Job failed without reaching the broker.',
                'finished_at' => now(),
            ]);
    }

    private function run(BrokerClient $broker, TelegramNotifier $notifier, Operation $operation): void
    {
        // Re-read under the lock: it may have been cancelled while we waited.
        $operation->refresh();
        if (! $operation->isActive()) {
            return;
        }

        $operation->update([
            'status' => Operation::STATUS_RUNNING,
            'started_at' => now(),
            'step' => 'Started',
        ]);

        try {
            $response = $broker->call(
                $operation->broker_action,
                is_array($operation->args) ? $operation->args : [],
                $this->stdin,
                $this->timeout - 60,
            );

            $operation->update([
                'status' => $response->ok ? Operation::STATUS_COMPLETED : Operation::STATUS_FAILED,
                'error' => $response->ok ? null : $response->error,
                'step' => $response->ok ? 'Finished' : 'Failed',
                'log' => self::logFrom($response->data),
                'finished_at' => now(),
            ]);
        } catch (BrokerCallException $e) {
            Log::error('Operation failed', ['operation' => $operation->id, 'error' => $e->getMessage()]);
            $operation->update([
                'status' => Operation::STATUS_FAILED,
                'error' => $e->getMessage(),
                'step' => 'Failed',
                'finished_at' => now(),
            ]);
        }

        $this->notify($notifier, $operation->refresh());
    }

    /**
     * Some actions return their own output; keep it so the record is useful after
     * the fact rather than only while it runs.
     */
    private static function logFrom(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }
        foreach (['log', 'lines', 'preview', 'detail'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_array($value)) {
                return substr(implode("\n", array_map('strval', $value)), 0, 20000);
            }
            if (is_string($value) && $value !== '') {
                return substr($value, 0, 20000);
            }
        }

        return null;
    }

    private function notify(TelegramNotifier $notifier, Operation $operation): void
    {
        // Only failures are worth interrupting someone for; a successful build is
        // visible on the Operations page when they look.
        if ($operation->status !== Operation::STATUS_FAILED) {
            return;
        }
        try {
            $notifier->send(
                'OPERATION FAILED ' . $operation->kind . ' on ' . $operation->subject_id
                . "\n" . (string) $operation->error
            );
        } catch (\Throwable $e) {
            Log::warning('Operation failure notification failed', ['error' => $e->getMessage()]);
        }
    }
}
