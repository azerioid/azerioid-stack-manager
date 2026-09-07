<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Tls\Certbot;

final class TlsRenew
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $certbot = new Certbot($runtime, $config);
        $hook = $certbot->ensureRenewalHook();

        if ($action === 'tls.renew.hook-install') {
            return ['hook' => $hook, 'installed' => true];
        }

        // Default: dry-run renew (safe; uses staging semantics for renewal simulation).
        $result = $certbot->renewDryRun();
        $timer = $runtime->exec(['/usr/bin/systemctl', 'is-active', 'certbot.timer']);
        $timerState = trim($timer->stdout);

        return [
            'dry_run' => $result,
            'hook' => $hook,
            'certbot_timer' => $timerState !== '' ? $timerState : 'unknown',
            'note' => 'Caddy-native HTTP-01 certs renew via Caddy itself; certbot.timer covers Apache/Nginx/DNS-01 paths.',
        ];
    }
}
