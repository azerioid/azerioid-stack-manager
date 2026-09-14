<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Mail\MailManager;
use AzerioidPanel\Broker\Runtime;

/** mail.status · mail.probe.outbound25 · mail.relay.selftest */
final class MailStatus
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new MailManager($config, $runtime);

        return match ($action) {
            'mail.probe.outbound25' => $manager->probeOutbound25(),
            'mail.relay.selftest' => $manager->relaySelftest(),
            default => $manager->status(self::probeRequested($input)),
        };
    }

    /** The probe costs a network round trip; pollers can skip it. */
    private static function probeRequested(array $input): bool
    {
        if (!array_key_exists('probe', $input)) {
            return true;
        }

        return filter_var($input['probe'], FILTER_VALIDATE_BOOLEAN);
    }
}
