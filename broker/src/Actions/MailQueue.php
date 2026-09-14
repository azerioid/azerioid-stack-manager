<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Mail\MailManager;
use AzerioidPanel\Broker\Runtime;

/** mail.queue · mail.logs · mail.test.send · mail.alerts.set */
final class MailQueue
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new MailManager($config, $runtime);

        return match ($action) {
            'mail.logs' => $manager->logs((int) ($input['lines'] ?? 100)),
            'mail.test.send' => $manager->testSend(
                (string) ($args[0] ?? ($input['from'] ?? '')),
                (string) ($args[1] ?? ($input['to'] ?? '')),
                (string) ($input['subject'] ?? '')
            ),
            'mail.alerts.set' => $manager->setAlertsViaLocalMail(MailInput::bool($input['enabled'] ?? false)),
            default => $manager->queue(
                MailInput::bool($input['flush'] ?? false),
                (string) ($input['confirm'] ?? '')
            ),
        };
    }
}
