<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Cron;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\VhostUser;

/**
 * One scheduled job, as structured state rather than a line of text (B2 / request #4).
 *
 * The panel's only cron feature until now was a textarea holding the whole root
 * crontab: every job ran as root, and saving replaced the entire file — so two
 * operators editing at once silently lost one set of changes, and one stray keystroke
 * could delete every job on the host.
 *
 * Structured jobs fix all three. The crontab is *rendered* from state, so nothing is
 * lost; each job names its own identity, so a site's job no longer needs root; and a
 * job can be disabled or removed on its own.
 */
final class CronJob
{
    public const RUN_AS_ROOT = 'root';

    private function __construct(
        public readonly string $id,
        /** Vhost domain this belongs to, or 'root' for host-wide work. */
        public readonly string $owner,
        public readonly string $schedule,
        public readonly string $command,
        public readonly bool $enabled,
        public readonly string $note,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string,mixed> $input */
    public static function fromInput(array $input, string $now, ?string $id = null): self
    {
        $owner = strtolower(trim((string) ($input['owner'] ?? '')));
        if ($owner === '') {
            throw new BrokerException('A job needs an owner: a vhost domain, or "root".', 2);
        }
        if ($owner !== self::RUN_AS_ROOT) {
            $owner = Validator::domain($owner);
        }

        return new self(
            $id ?? 'job-' . substr(hash('sha256', $now . '|' . random_bytes(8)), 0, 10),
            $owner,
            self::normalizeSchedule((string) ($input['schedule'] ?? '')),
            self::normalizeCommand((string) ($input['command'] ?? '')),
            (bool) ($input['enabled'] ?? true),
            self::normalizeNote((string) ($input['note'] ?? '')),
            $now
        );
    }

    /** @param array<string,mixed> $row */
    public static function fromState(array $row): self
    {
        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['owner'] ?? self::RUN_AS_ROOT),
            (string) ($row['schedule'] ?? ''),
            (string) ($row['command'] ?? ''),
            (bool) ($row['enabled'] ?? true),
            (string) ($row['note'] ?? ''),
            (string) ($row['created_at'] ?? '')
        );
    }

    public function withEnabled(bool $enabled): self
    {
        return new self($this->id, $this->owner, $this->schedule, $this->command, $enabled, $this->note, $this->createdAt);
    }

    public function runsAsRoot(): bool
    {
        return $this->owner === self::RUN_AS_ROOT;
    }

    public function runsAs(): string
    {
        return $this->runsAsRoot() ? 'root' : VhostUser::username($this->owner);
    }

    /**
     * Five fields or one of the common shorthands. `@reboot` is refused: it is not a
     * schedule but a startup hook, and accepting one would give a site a way to run
     * code on every boot that appears in no schedule the operator reviews.
     */
    private static function normalizeSchedule(string $schedule): string
    {
        $schedule = trim(preg_replace('/\s+/', ' ', $schedule) ?? '');
        if ($schedule === '') {
            throw new BrokerException('A job needs a schedule.', 2);
        }
        if (str_starts_with($schedule, '@')) {
            $allowed = ['@yearly', '@annually', '@monthly', '@weekly', '@daily', '@midnight', '@hourly'];
            if (!in_array($schedule, $allowed, true)) {
                throw new BrokerException(
                    'Shorthand schedules are limited to ' . implode(', ', $allowed)
                    . '. @reboot is a startup hook, not a schedule, and is not accepted.',
                    2
                );
            }

            return $schedule;
        }

        $fields = explode(' ', $schedule);
        if (count($fields) !== 5) {
            throw new BrokerException(
                'A schedule needs five fields (minute hour day month weekday), or one of the @ shorthands.',
                2
            );
        }
        foreach ($fields as $index => $field) {
            if (!preg_match('/^[0-9*\/,\-]+$/', $field)) {
                throw new BrokerException(
                    'Schedule field ' . ($index + 1) . ' (' . $field . ') may only contain digits, * , - and /.',
                    2
                );
            }
        }

        return $schedule;
    }

    /**
     * cron hands the command to `sh -c`, so pipes and redirection are legitimate.
     * What it may not contain is anything that escapes its own crontab line: a line
     * break ends the line and starts another, and `%` is cron's own escape that turns
     * the rest of the line into stdin.
     */
    private static function normalizeCommand(string $command): string
    {
        $command = trim($command);
        if ($command === '') {
            throw new BrokerException('A job needs a command.', 2);
        }
        if (strlen($command) > 1000) {
            throw new BrokerException('Command is too long (max 1000 characters).', 2);
        }
        if (str_contains($command, "\n") || str_contains($command, "\r") || str_contains($command, "\0")) {
            throw new BrokerException(
                'A command cannot contain a line break or null byte: it would end the crontab line and start another.',
                2
            );
        }
        if (str_contains($command, '%')) {
            throw new BrokerException(
                'A command cannot contain %: cron reads it as an escape and passes the rest on stdin. '
                . 'Put the command in a script instead.',
                2
            );
        }

        return $command;
    }

    private static function normalizeNote(string $note): string
    {
        $note = trim($note);
        if ($note === '') {
            return '';
        }
        if (strlen($note) > 80 || preg_match('/\p{C}/u', $note) === 1) {
            throw new BrokerException('Note must be plain text, at most 80 characters.', 2);
        }

        return $note;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->owner,
            'schedule' => $this->schedule,
            'command' => $this->command,
            'enabled' => $this->enabled,
            'note' => $this->note,
            'created_at' => $this->createdAt,
            'runs_as' => $this->runsAs(),
        ];
    }
}
