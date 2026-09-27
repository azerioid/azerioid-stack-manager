<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Panel;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * The one sudoers file that lets the panel reach the broker.
 *
 * Every writer (installer-equivalent self-update, R1 hardening, A39 identity
 * migration) goes through here so the file is validated with `visudo -c -f`
 * BEFORE it replaces the live one. A sudoers file that fails to parse disables
 * sudo for the whole host, not just for the panel.
 */
final class PanelSudoers
{
    public const PATH = '/etc/sudoers.d/azerioid-panel';

    /**
     * @param list<string> $users every identity that must hold the grant
     */
    public static function render(Config $config, array $users, string $header): string
    {
        if ($users === []) {
            throw new BrokerException('Refusing to write an empty sudoers grant.', 3);
        }
        $broker = rtrim($config->panelRoot, '/') . '/broker';
        $body = '# AZERIOID Stack Manager — sudoers (' . $header . ")\n";
        foreach ($users as $u) {
            if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $u) !== 1) {
                throw new BrokerException('Refusing to write an invalid user into sudoers: ' . $u, 2);
            }
            $body .= "Defaults:{$u} !requiretty\n";
            $body .= "Defaults:{$u} umask=0022\n";
        }
        foreach ($users as $u) {
            $body .= "{$u} ALL=(root) NOPASSWD: {$broker}\n";
        }

        return $body;
    }

    /**
     * @param list<string> $users
     */
    public static function install(Runtime $runtime, Config $config, array $users, string $header): void
    {
        $body = self::render($config, $users, $header);

        $tmp = rtrim($config->stagingDir, '/') . '/sudoers.azerioid-panel.check';
        $runtime->mkdir($config->stagingDir, 0750);
        $runtime->writeFile($tmp, $body, 0440);
        $check = $runtime->exec(['/usr/sbin/visudo', '-c', '-f', $tmp], null, 15);
        $runtime->deleteFile($tmp);
        if (!$check->ok()) {
            throw new BrokerException('Generated sudoers failed visudo validation: ' . trim($check->stderr . ' ' . $check->stdout), 1);
        }

        $runtime->writeFile(self::PATH, $body, 0440);
        $runtime->chmod(self::PATH, 0440);

        $post = $runtime->exec(['/usr/sbin/visudo', '-c'], null, 15);
        if (!$post->ok()) {
            throw new BrokerException('System sudoers invalid after write: ' . trim($post->stderr . ' ' . $post->stdout), 1);
        }
    }

    /**
     * Users currently granted the broker by the live file.
     *
     * @return list<string>
     */
    public static function users(Runtime $runtime, Config $config): array
    {
        if (!$runtime->fileExists(self::PATH)) {
            return [];
        }
        try {
            $body = $runtime->readFile(self::PATH);
        } catch (BrokerException) {
            return [];
        }
        $users = [];
        $broker = preg_quote(rtrim($config->panelRoot, '/') . '/broker', '/');
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(\S+)\s+ALL=\(root\)\s+NOPASSWD:\s*' . $broker . '\s*$/', $line, $m) === 1) {
                $users[] = $m[1];
            }
        }

        return array_values(array_unique($users));
    }

    /**
     * Set the user field of the scheduler line(s) in /etc/cron.d/azerioid-panel.
     *
     * Line by line and only on job lines: the header comment and `SHELL=` /
     * `PATH=` lines also have whitespace-separated fields, and a pattern that
     * lets `\s` cross newlines rewrites one of those instead of the job.
     * Returns null when no job line was found.
     */
    public static function cronUser(string $body, string $user): ?string
    {
        $found = false;
        $lines = explode("\n", $body);
        foreach ($lines as $i => $line) {
            if (preg_match('/^((?:[0-9*@\/,-][^ \t]*[ \t]+){5})([a-z_][a-z0-9_-]*)([ \t].*)$/', $line, $m) !== 1) {
                continue;
            }
            $lines[$i] = $m[1] . $user . $m[3];
            $found = true;
        }

        return $found ? implode("\n", $lines) : null;
    }
}
