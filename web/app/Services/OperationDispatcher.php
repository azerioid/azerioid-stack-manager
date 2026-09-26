<?php

namespace App\Services;

use App\Jobs\RunOperationJob;
use App\Models\Operation;
use Illuminate\Support\Facades\Auth;

/**
 * Decides which broker calls are too slow to run inline, and queues them (B5).
 *
 * The rule is "does this routinely take minutes" — not "does it have a generous
 * timeout". A docker build pulling base layers, a restore streaming a whole
 * archive back onto disk: those ran behind a blocking HTTP request with a
 * 900-second broker timeout, so the operator watched a spinner with no output,
 * no cancel, and no record of what happened afterwards.
 *
 * Everything else stays inline. Read-only calls and quick writes would gain a
 * database row and a round trip through the queue for nothing, and an action
 * whose result the operator needs *now* (the port a runtime just bound) is worse
 * off behind a queue boundary.
 */
class OperationDispatcher
{
    /**
     * broker action => [kind, subject type, stdin keys naming the subject].
     *
     * Only actions that are *genuinely* slow, not merely ones with a generous
     * timeout. The 900-second ceiling on most vhost runtime calls is a maximum,
     * not a typical duration: an Octane or PM2 enable waits for a worker to
     * listen and normally finishes in seconds, and it returns something the
     * operator wants immediately — the port it is now serving from. Queueing
     * those would lose that for no gain.
     *
     * What is actually slow: anything that builds a container image, and anything
     * that unpacks an archive.
     *
     * The third element exists because the subject of an operation is not always
     * the first argument. A restore is called with the *archive* key, but what the
     * operator is watching is the database or the site being restored into, and
     * that only appears in stdin.
     *
     * Not here, deliberately:
     *  - backup.db / backup.files / backup.caddy are slow, but they already
     *    maintain a BackupJob row that the Backups page and the backup-staleness
     *    alert rule both read. Converting them means either duplicating that
     *    bookkeeping or leaving BackupJob stuck at `running`, which the alert rule
     *    would read as a missed backup — so they wait for the BackupJob/operations
     *    unification rather than being half-converted here.
     *  - backup.verify has no panel call site at all; it is a CLI command that
     *    prints its verdict, where running inline is the point.
     *
     * @var array<string, array{0:string, 1:string, 2:list<string>}>
     */
    public const ASYNC_ACTIONS = [
        // Builds an image whenever the vhost supplies a Dockerfile or compose file.
        'vhost.docker.build' => ['docker.build', 'vhost', []],
        // Only the standalone enable; the one during vhost creation stays inline.
        'vhost.docker.enable' => ['docker.enable', 'vhost', []],
        'backup.restore.db' => ['backup.restore.db', 'database', ['target', 'database']],
        'backup.restore.files' => ['backup.restore.files', 'vhost', ['site', 'domain']],
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
        [$kind, $subjectType, $subjectKeys] = self::ASYNC_ACTIONS[$action]
            ?? throw new \InvalidArgumentException('Not an async action: ' . $action);

        $operation = Operation::query()->create([
            'user_id' => Auth::id(),
            'kind' => $kind,
            'subject_type' => $subjectType,
            'subject_id' => self::subjectId($subjectKeys, $args, $stdin),
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

    /**
     * Named stdin keys win over the first argument; host-wide work gets a stable
     * subject so its lock (see Operation::lockKeyFor) serialises against itself.
     *
     * @param  list<string>  $subjectKeys
     * @param  list<string>  $args
     * @param  array<string,mixed>  $stdin
     */
    private static function subjectId(array $subjectKeys, array $args, array $stdin): string
    {
        $candidates = [];
        foreach ($subjectKeys as $key) {
            $candidates[] = $stdin[$key] ?? null;
        }
        $candidates[] = $args[0] ?? null;

        foreach ($candidates as $candidate) {
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
