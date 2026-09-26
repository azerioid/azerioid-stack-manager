<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Cron;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Runtime;

/**
 * The panel's cron state, and the only writer of it (B2 / request #4).
 *
 * State lives in a broker-owned file rather than the panel database, for the same
 * reason vhosts do (A9, A44): the thing that runs must be the thing that is true.
 * cron reads crontabs, so the crontab is rendered from this file and the panel reads
 * it back through the broker — a panel database row can be a projection, never the
 * source.
 *
 * Every write is read-modify-write of a single small file, which is safe here because
 * the broker is the only writer and each invocation is one action. That is already a
 * stronger guarantee than the feature this replaces, where two operators saving a
 * textarea would overwrite each other's jobs entirely.
 */
final class CronStore
{
    public const PATH = '/var/lib/azerioid-panel/cron/jobs.json';

    public function __construct(private readonly Runtime $runtime)
    {
    }

    /** @return list<CronJob> */
    public function all(): array
    {
        if (!$this->runtime->fileExists(self::PATH)) {
            return [];
        }
        $data = json_decode($this->runtime->readFile(self::PATH), true);
        if (!is_array($data)) {
            throw new BrokerException(
                'The cron state file is unreadable. Nothing has been changed; fix or remove '
                . self::PATH . ' (the crontabs currently installed keep running).',
                1
            );
        }
        $jobs = [];
        foreach ($data['jobs'] ?? [] as $row) {
            if (is_array($row)) {
                $jobs[] = CronJob::fromState($row);
            }
        }

        return $jobs;
    }

    public function find(string $id): CronJob
    {
        foreach ($this->all() as $job) {
            if ($job->id === $id) {
                return $job;
            }
        }

        throw new BrokerException('No such cron job: ' . $id . '.', 3);
    }

    /** @param list<CronJob> $jobs */
    public function save(array $jobs): void
    {
        $this->runtime->mkdir(dirname(self::PATH), 0750);
        $this->runtime->writeFile(self::PATH, (string) json_encode([
            'version' => 1,
            'jobs' => array_map(static fn (CronJob $j): array => [
                'id' => $j->id,
                'owner' => $j->owner,
                'schedule' => $j->schedule,
                'command' => $j->command,
                'enabled' => $j->enabled,
                'note' => $j->note,
                'created_at' => $j->createdAt,
            ], array_values($jobs)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0640);
    }

    /** Every identity that currently has, or has had, panel-managed jobs. */
    public function owners(): array
    {
        $owners = [];
        foreach ($this->all() as $job) {
            $owners[$job->owner] = true;
        }

        return array_keys($owners);
    }
}
