<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

/**
 * Cron actions.
 *
 * `cron.list` / `cron.set` are the original whole-file root crontab pair, kept because
 * released CLI and UI code call them and an operator's existing scripts may too. B2
 * adds the structured jobs alongside them (Cron\CronManager): those run as the vhost's
 * own identity, are rendered from state rather than edited as text, and can be
 * disabled, run now and read back with their output.
 */
final class CronManage
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new \AzerioidPanel\Broker\Cron\CronManager($runtime, $config);

        switch ($action) {
            case 'cron.jobs':
                return $manager->list(isset($args[0]) ? (string) $args[0] : (string) ($input['owner'] ?? ''));
            case 'cron.job.add':
                return $manager->add($input);
            case 'cron.job.del':
                return $manager->delete($this->jobId($args, $input));
            case 'cron.job.enable':
                return $manager->setEnabled($this->jobId($args, $input), true, $input);
            case 'cron.job.disable':
                return $manager->setEnabled($this->jobId($args, $input), false, $input);
            case 'cron.job.run':
                return $manager->runNow($this->jobId($args, $input));
            case 'cron.job.log':
                return $manager->log($this->jobId($args, $input), (int) ($input['lines'] ?? 200));
        }

        if ($action === 'cron.list') {
            $result = $runtime->exec(['/usr/bin/crontab', '-l'], null, 10);
            $body = $result->ok() ? $result->stdout : '';
            $lines = $body === '' ? [] : explode("\n", rtrim($body, "\n"));
            return ['lines' => $lines, 'warning' => 'These entries run as root.'];
        }

        $raw = $input['lines'] ?? $input['crontab'] ?? null;
        if (!is_array($raw)) {
            throw new BrokerException('Provide lines as a JSON array.', 2);
        }
        Validator::typedConfirm((string) ($input['confirm'] ?? ''), 'UPDATE-ROOT-CRON');
        $validated = [];
        foreach ($raw as $line) {
            if (!is_string($line)) {
                throw new BrokerException('Cron lines must be strings.', 2);
            }
            $validated[] = Validator::cronLine($line);
        }
        $body = implode("\n", $validated);
        if ($body !== '' && !str_ends_with($body, "\n")) {
            $body .= "\n";
        }
        $tmp = rtrim($config->stagingDir, '/') . '/crontab.root';
        $runtime->mkdir($config->stagingDir, 0750);
        $runtime->writeFile($tmp, $body, 0600);
        try {
            $result = $runtime->exec(['/usr/bin/crontab', $tmp], null, 10);
        } finally {
            $runtime->deleteFile($tmp);
        }
        if (!$result->ok()) {
            throw new BrokerException(trim($result->stderr) !== '' ? trim($result->stderr) : 'crontab install failed.', 1);
        }
        return ['updated' => true, 'count' => count($validated)];
    }

    /**
     * @param  list<string>  $args
     * @param  array<string,mixed>  $input
     */
    private function jobId(array $args, array $input): string
    {
        $id = trim((string) ($args[0] ?? $input['id'] ?? ''));
        if ($id === '' || preg_match('/^job-[a-f0-9]{10}$/', $id) !== 1) {
            throw new BrokerException('Provide a job id (job-xxxxxxxxxx).', 2);
        }

        return $id;
    }
}
