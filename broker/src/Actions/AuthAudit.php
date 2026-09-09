<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class AuthAudit
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $lines = Validator::lineCount($args[0] ?? 400, 1000);
        [$path, $body, $source] = $this->readAuthLog($runtime, $config, $lines);
        if ($body === null) {
            return [
                'path' => $path,
                'missing' => true,
                'source' => $source,
                'success' => [],
                'failed' => [],
                'failed_count' => 0,
                'new_root_ips' => [],
            ];
        }

        $success = [];
        $failed = [];
        $rootIps = [];
        foreach (preg_split("/\r?\n/", $body) ?: [] as $line) {
            if (preg_match('/sshd(?:-session)?\[\d+\]:\s+Accepted\s+(\S+)\s+for\s+(\S+)\s+from\s+(\S+)/', $line, $m)) {
                $row = ['user' => $m[2], 'ip' => $m[3], 'method' => $m[1], 'line' => $line];
                $success[] = $row;
                if ($m[2] === 'root') {
                    $rootIps[$m[3]] = ($rootIps[$m[3]] ?? 0) + 1;
                }
            } elseif (preg_match('/sshd(?:-session)?\[\d+\]:\s+Failed\s+(\S+)\s+for\s+(?:invalid user\s+)?(\S+)\s+from\s+(\S+)/', $line, $m)) {
                $failed[] = ['user' => $m[2], 'ip' => $m[3], 'method' => $m[1], 'line' => $line];
            }
        }
        $known = [];
        $newRoot = [];
        foreach (array_reverse($success) as $row) {
            if ($row['user'] !== 'root') {
                continue;
            }
            if (!isset($known[$row['ip']])) {
                if (count($known) > 0) {
                    $newRoot[] = $row;
                }
                $known[$row['ip']] = true;
            }
        }
        return [
            'path' => $path,
            'missing' => false,
            'source' => $source,
            'success' => array_slice(array_reverse($success), 0, 50),
            'failed' => array_slice(array_reverse($failed), 0, 50),
            'failed_count' => count($failed),
            'new_root_ips' => array_slice($newRoot, 0, 20),
        ];
    }

    /** @return array{0:string,1:?string,2:string} path, body|null, source */
    private function readAuthLog(Runtime $runtime, Config $config, int $lines): array
    {
        $candidates = [];
        foreach (['auth', 'auth-secure', 'auth-syslog'] as $key) {
            $candidate = $config->logPaths[$key] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                $candidates[] = $candidate;
            }
        }
        // OS-family probes (Debian/Ubuntu rsyslog vs EL /var/log/secure vs journal-only hosts).
        foreach (['/var/log/auth.log', '/var/log/secure', '/var/log/syslog'] as $fallback) {
            $candidates[] = $fallback;
        }
        $seen = [];
        foreach ($candidates as $candidate) {
            if (isset($seen[$candidate])) {
                continue;
            }
            $seen[$candidate] = true;
            if (!$runtime->fileExists($candidate)) {
                continue;
            }
            $result = $runtime->exec(['/usr/bin/tail', '-n', (string) $lines, $candidate]);
            $body = $result->ok() ? $result->stdout : $runtime->readFile($candidate);
            return [$candidate, $body, 'file'];
        }

        // Journald-only hosts (common on Debian 12+/Ubuntu with systemd-journald, no rsyslog).
        if ($runtime->fileExists('/usr/bin/journalctl')) {
            $result = $runtime->exec([
                '/usr/bin/journalctl',
                '-u', 'ssh',
                '-u', 'sshd',
                '-n', (string) $lines,
                '--no-pager',
                '-o', 'short-iso',
            ], null, 30);
            if ($result->ok() && trim($result->stdout) !== '') {
                return ['journalctl://ssh', $result->stdout, 'journal'];
            }
        }

        return [$config->logPaths['auth'] ?? '/var/log/auth.log', null, 'none'];
    }
}
