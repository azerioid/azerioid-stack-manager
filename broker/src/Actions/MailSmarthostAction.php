<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Component\OsRelease;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Mail\MailPaths;
use AzerioidPanel\Broker\Mail\MailSmarthost;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

/**
 * mail.smarthost.show · set · clear · test
 *
 * The relay password is accepted on stdin only and never echoed back.
 */
final class MailSmarthostAction
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $paths = MailPaths::for($runtime, $config, OsRelease::detect($runtime));
        $smarthost = new MailSmarthost($config, $runtime, $paths);

        return match ($action) {
            'mail.smarthost.show' => ['smarthost' => $smarthost->describe()],
            'mail.smarthost.test' => $smarthost->test(),
            'mail.smarthost.clear' => $this->clear($smarthost, $input),
            default => $smarthost->set(
                (string) ($input['host'] ?? ''),
                (int) ($input['port'] ?? 587),
                (string) ($input['username'] ?? ''),
                (string) ($input['password'] ?? ''),
                (string) ($input['tls'] ?? 'starttls')
            ),
        };
    }

    /** Clearing the relay silently breaks outbound mail on a blocked host, so it is confirmed. */
    private function clear(MailSmarthost $smarthost, array $input): array
    {
        Validator::typedConfirm((string) ($input['confirm'] ?? ''), 'CLEAR-RELAY');
        $smarthost->clear();

        return ['smarthost' => null, 'cleared' => true];
    }
}
