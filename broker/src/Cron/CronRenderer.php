<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Cron;

/**
 * Renders a crontab from state, keeping everything the panel did not write (B2).
 *
 * The block between the markers is the panel's; everything outside it is the
 * operator's and is reproduced byte for byte. That is the difference between this and
 * the textarea it replaces: a host that already had root cron jobs — a backup script,
 * a certbot hook, whatever the provider's image shipped — keeps them. Preserving them
 * is not politeness, it is the difference between a feature and an outage.
 *
 * Marker discipline follows A30: a begin line, an end line, a warning that the block
 * is generated, and no attempt to parse what is inside it on the way back in — state
 * is the source, the file is the output.
 */
final class CronRenderer
{
    public const BEGIN = '# BEGIN azerioid-panel managed jobs — do not edit inside this block';

    public const END = '# END azerioid-panel managed jobs';

    /** Base directory for job output; each identity gets its own subdirectory. */
    public const LOG_DIR = '/var/log/azerioid-panel/cron';

    /**
     * Per-identity subdirectory, because a site job runs *as the site* and therefore
     * has to be able to create and append to its own log. A single root-owned
     * directory would make every site job fail at the redirection — before its command
     * ran at all — which is the sort of bug that looks like "cron is broken".
     *
     * It also keeps one site's job output away from another's.
     */
    public static function logPathFor(string $runsAs, string $jobId): string
    {
        return self::LOG_DIR . '/' . $runsAs . '/' . $jobId . '.log';
    }

    /**
     * @param  list<CronJob>  $jobs  jobs belonging to this one identity
     */
    public function render(string $existing, array $jobs, string $wrapper): string
    {
        $preserved = $this->withoutManagedBlock($existing);
        if ($jobs === []) {
            // No block at all rather than an empty one: an operator reading the file
            // should not have to wonder whether the panel forgot something.
            return $preserved === '' ? '' : rtrim($preserved, "\n") . "\n";
        }

        $lines = [self::BEGIN];
        foreach ($jobs as $job) {
            if ($job->note !== '') {
                $lines[] = '# ' . $job->id . ': ' . $job->note;
            } else {
                $lines[] = '# ' . $job->id;
            }
            $line = $job->schedule . ' ' . $wrapper . ' ' . $job->id . ' '
                . self::logPathFor($job->runsAs(), $job->id) . ' ' . $job->command;
            // A disabled job stays in the file, commented, so the operator can see it
            // exists and what it would run. Deleting it from the file would make
            // "disabled" and "deleted" indistinguishable on the host.
            $lines[] = ($job->enabled ? '' : '# DISABLED ') . $line;
        }
        $lines[] = self::END;

        $block = implode("\n", $lines);

        return ($preserved === '' ? '' : rtrim($preserved, "\n") . "\n\n") . $block . "\n";
    }

    /**
     * Strips a previously rendered block, tolerating a missing end marker: a
     * half-written file must not cause the rest of the operator's crontab to be
     * treated as panel content and deleted.
     */
    public function withoutManagedBlock(string $body): string
    {
        $out = [];
        $inside = false;
        foreach (explode("\n", $body) as $line) {
            $trimmed = trim($line);
            if ($trimmed === self::BEGIN) {
                $inside = true;
                continue;
            }
            if ($trimmed === self::END) {
                $inside = false;
                continue;
            }
            if (!$inside) {
                $out[] = $line;
            }
        }

        return trim(implode("\n", $out)) === '' ? '' : implode("\n", $out);
    }

    /**
     * The wrapper exists so a job's exit code and output are recorded somewhere an
     * operator can read, instead of being mailed to a local mailbox nobody opens —
     * which is what cron does by default and why a failing cron job is usually
     * discovered by its consequences.
     */
    public function wrapperScript(): string
    {
        return <<<'SH'
#!/bin/sh
# AZERIOID Stack Manager cron wrapper (generated — do not edit).
#
# Usage: azerioid-cron-run <job-id> <log-path> <command...>
#
# Records start, output and exit code for one job. cron's default is to mail output
# to a local mailbox nobody reads, so a failing job is normally discovered by its
# consequences; this makes it visible to the panel instead.
set -u
id="$1"
log="$2"
shift 2
# The path is passed in rather than derived: the caller knows which identity this job
# runs as, and that identity is the only one that can write here.
started="$(date -Is)"
printf '%s START\n' "${started}" >>"${log}" 2>/dev/null || true
# Output is appended, not truncated, and bounded by logrotate rather than by this
# script: truncating here would race with a job that is still writing.
sh -c "$*" >>"${log}" 2>&1
status=$?
printf '%s EXIT %s\n' "$(date -Is)" "${status}" >>"${log}" 2>/dev/null || true
exit "${status}"
SH;
    }
}
