<?php

namespace App\Services;

use App\Jobs\RunOperationJob;
use App\Models\Operation;
use Illuminate\Support\Facades\Auth;

/**
 * Decides which broker calls are too slow to run inline, and queues them (B5).
 *
 * The rule is simply "can this plausibly exceed about ten seconds". A docker build
 * pulling base layers, a package install adding a repository, a multi-gigabyte
 * backup, an Octane enable waiting for a worker to listen — all of these were
 * running behind a blocking HTTP request with a 900-second broker timeout, which
 * meant the operator watched a spinner with no output and no record afterwards.
 *
 * Read-only calls and quick writes stay inline; queueing them would add latency
 * and a database row for nothing.
 */
class OperationDispatcher
{
    /**
     * broker action => [kind, subject type, how to derive the subject id].
     *
     * Explicit rather than pattern-matched: a new slow action should be a
     * deliberate addition here, and anything not listed keeps working exactly as
     * it does today.
     */
    public const ASYNC_ACTIONS = [
        'vhost.docker.build' => ['docker.build', 'vhost'],
        'vhost.docker.enable' => ['docker.enable', 'vhost'],
        'vhost.docker.disable' => ['docker.disable', 'vhost'],
        'vhost.octane.enable' => ['octane.enable', 'vhost'],
        'vhost.octane.disable' => ['octane.disable', 'vhost'],
        'vhost.pm2.enable' => ['pm2.enable', 'vhost'],
        'vhost.pm2.disable' => ['pm2.disable', 'vhost'],
        'backup.db' => ['backup.db', 'database'],
        'backup.files' => ['backup.files', 'vhost'],
        'backup.caddy' => ['backup.caddy', 'panel'],
        'backup.restore.db' => ['backup.restore.db', 'database'],
        'backup.restore.files' => ['backup.restore.files', 'vhost'],
        'backup.verify' => ['backup.verify', 'backup'],
        'mail.domain.enable' => ['mail.domain.enable', 'vhost'],
        'mail.domain.disable' => ['mail.domain.disable', 'vhost'],
    ];

    /** Inputs that must never be written to the operations table. */
    private const REDACT = [
        'passphrase', 'password', 'secret', 'token', 'spaces',
        'content_base64', 'html', 'confirm',
    ];

    public static function isAsync(string $action): bool
    {
        return array_key_exists($action, self::ASYNC_ACTIONS);
    }

    /**
     * Queue an operation and return its row.
     *
     * @param  list<string>  $args
     * @param  array<string,mixed>  $stdin
     */
    public function dispatch(string $action, array $args, array $stdin): Operation
    {
        [$kind, $subjectType] = self::ASYNC_ACTIONS[$action]
            ?? throw new \InvalidArgumentException('Not an async action: ' . $action);

        $operation = Operation::query()->create([
            'user_id' => Auth::id(),
            'kind' => $kind,
            'subject_type' => $subjectType,
            'subject_id' => self::subjectId($args, $stdin),
            'broker_action' => $action,
            'args' => array_values($args),
            // Secrets are stripped, not redacted in place: the operations table is
            // read by the UI and kept for 180 days.
            'options' => self::safeOptions($stdin),
            'status' => Operation::STATUS_QUEUED,
        ]);

        RunOperationJob::dispatch($operation->id, $stdin);

        return $operation;
    }

    /** @param list<string> $args */
    private static function subjectId(array $args, array $stdin): string
    {
        foreach ([$args[0] ?? null, $stdin['domain'] ?? null, $stdin['database'] ?? null, $stdin['site'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return substr(trim($candidate), 0, 253);
            }
        }

        return 'host';
    }

    /** @param array<string,mixed> $stdin */
    private static function safeOptions(array $stdin): array
    {
        $out = [];
        foreach ($stdin as $key => $value) {
            $lower = strtolower((string) $key);
            foreach (self::REDACT as $bad) {
                if (str_contains($lower, $bad)) {
                    continue 2;
                }
            }
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
