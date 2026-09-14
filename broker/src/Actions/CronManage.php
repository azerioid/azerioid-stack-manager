<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class CronManage
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
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
}
