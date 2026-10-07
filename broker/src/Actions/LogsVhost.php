<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

/**
 * A82: tail or search a single vhost's Caddy access log. Unlike logs.tail/logs.search
 * (a fixed system-log key allowlist), the path here is derived from a validated domain
 * — `<web_log_dir>/access_<domain>.log` (A76) — and re-checked to resolve under the web
 * log dir, so a crafted domain cannot read a file elsewhere. Read-only.
 */
final class LogsVhost
{
    /** @return array<string,mixed> */
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $domain = Validator::domain((string) ($args[0] ?? ($input['domain'] ?? '')));
        $type = strtolower(trim((string) ($args[1] ?? ($input['type'] ?? 'access'))));
        if ($type !== 'access') {
            // App/error logs for runtime sites are served by the Docker logs page and
            // the Octane/PM2 status; only the access log is per-vhost here.
            throw new BrokerException('Only the access log is available per vhost.', 2);
        }
        $lines = Validator::lineCount($args[2] ?? ($input['lines'] ?? 200));
        $needle = isset($input['needle']) && $input['needle'] !== ''
            ? Validator::searchNeedle((string) $input['needle'])
            : null;

        $base = rtrim($config->webLogDir, '/');
        $path = $base . '/access_' . $domain . '.log';

        $out = ['domain' => $domain, 'type' => $type, 'path' => $path];
        if ($needle !== null) {
            $out['needle'] = $needle;
        }

        if (!$runtime->fileExists($path)) {
            return $out + ['missing' => true, 'lines' => []];
        }
        if ($runtime->resolveUnderBase($path, $base) === null) {
            throw new BrokerException('Log path failed allowlist resolution.', 3);
        }

        if ($needle !== null) {
            $result = $runtime->exec(['/usr/bin/grep', '-F', '-n', '-m', '200', '--', $needle, $path], null, 15);

            return $out + ['missing' => false, 'lines' => $result->stdout === '' ? [] : explode("\n", rtrim($result->stdout, "\n"))];
        }

        $result = $runtime->exec(['/usr/bin/tail', '-n', (string) $lines, $path]);
        $body = $result->ok() ? $result->stdout : $runtime->readFile($path);
        $split = preg_split("/\r?\n/", rtrim($body, "\n")) ?: [];
        if (count($split) > $lines) {
            $split = array_slice($split, -$lines);
        }

        return $out + ['missing' => false, 'lines' => $split];
    }
}
