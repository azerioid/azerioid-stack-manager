<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Firewall and fail2ban status.
 *
 * This used to probe ufw only, so on an EL host the Security page reported no
 * firewall at all — while the broker was actively writing firewalld rules through
 * DbAccessFirewall (A23), MailFirewall (A36) and SiteHttpFirewall. An operator had
 * no way to see rules the panel itself had added (A1/G2).
 */
final class FirewallStatus
{
    private const UFW_BIN = '/usr/sbin/ufw';

    private const FIREWALL_CMD = '/usr/bin/firewall-cmd';

    private const FAIL2BAN_BIN = '/usr/bin/fail2ban-client';

    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $ufw = $this->ufw($runtime);
        $firewalld = $this->firewalld($runtime);

        return [
            'ufw' => $ufw,
            'firewalld' => $firewalld,
            // Which backend the broker's own writers will target, using the same
            // precedence they do: ufw first, firewalld when ufw is absent.
            'backend' => $ufw['active'] ? 'ufw' : ($firewalld['active'] ? 'firewalld' : 'none'),
            'active' => $ufw['active'] || $firewalld['active'],
            'fail2ban' => $this->fail2ban($runtime),
        ];
    }

    /** @return array{installed:bool, active:bool, status?:string} */
    private function ufw(Runtime $runtime): array
    {
        if (!$runtime->fileExists(self::UFW_BIN)) {
            return ['installed' => false, 'active' => false];
        }
        $st = $runtime->exec([self::UFW_BIN, 'status', 'verbose'], null, 15);
        $text = trim($st->stdout . "\n" . $st->stderr);

        return [
            'installed' => true,
            'active' => str_contains(strtolower($text), 'status: active'),
            'status' => $text,
        ];
    }

    /**
     * @return array{installed:bool, active:bool, state?:string, default_zone?:string,
     *               ports?:list<string>, services?:list<string>, rich_rules?:list<string>, status?:string}
     */
    private function firewalld(Runtime $runtime): array
    {
        if (!$runtime->fileExists(self::FIREWALL_CMD)) {
            return ['installed' => false, 'active' => false];
        }
        $state = $runtime->exec([self::FIREWALL_CMD, '--state'], null, 15);
        $stateText = trim($state->stdout . ' ' . $state->stderr);
        if (trim($state->stdout) !== 'running') {
            return ['installed' => true, 'active' => false, 'state' => $stateText];
        }

        $zone = trim($runtime->exec([self::FIREWALL_CMD, '--get-default-zone'], null, 15)->stdout);
        $listing = $runtime->exec([self::FIREWALL_CMD, '--list-all'], null, 15);

        return [
            'installed' => true,
            'active' => true,
            'state' => 'running',
            'default_zone' => $zone,
            'ports' => $this->splitWords($runtime->exec([self::FIREWALL_CMD, '--list-ports'], null, 15)->stdout),
            'services' => $this->splitWords($runtime->exec([self::FIREWALL_CMD, '--list-services'], null, 15)->stdout),
            'rich_rules' => $this->splitLines($runtime->exec([self::FIREWALL_CMD, '--list-rich-rules'], null, 15)->stdout),
            // Same shape as ufw's `status` so the UI can render either backend.
            'status' => trim($listing->stdout . "\n" . $listing->stderr),
        ];
    }

    /** @return array{installed:bool, status?:string, jails?:list<array{jail:string,raw:string,banned:list<string>}>} */
    private function fail2ban(Runtime $runtime): array
    {
        if (!$runtime->fileExists(self::FAIL2BAN_BIN)) {
            return ['installed' => false];
        }
        $st = $runtime->exec([self::FAIL2BAN_BIN, 'status'], null, 15);
        $jails = [];
        if (preg_match('/Jail list:\s*(.+)$/m', $st->stdout, $m)) {
            foreach (preg_split('/,\s*/', trim($m[1])) ?: [] as $jail) {
                $jail = trim($jail);
                if ($jail === '') {
                    continue;
                }
                $js = $runtime->exec([self::FAIL2BAN_BIN, 'status', $jail], null, 15);
                $banned = [];
                if (preg_match('/Banned IP list:\s*(.*)$/m', $js->stdout, $bm)) {
                    $banned = array_values(array_filter(preg_split('/\s+/', trim($bm[1])) ?: []));
                }
                $jails[] = ['jail' => $jail, 'raw' => trim($js->stdout), 'banned' => $banned];
            }
        }

        return ['installed' => true, 'status' => trim($st->stdout), 'jails' => $jails];
    }

    /** @return list<string> */
    private function splitWords(string $out): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($out)) ?: []));
    }

    /** @return list<string> */
    private function splitLines(string $out): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", trim($out)))));
    }
}
