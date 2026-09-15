<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Mail\MailManager;
use AzerioidPanel\Broker\Runtime;

/** mail.hostname.show · mail.hostname.set */
final class MailHostname
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new MailManager($config, $runtime);
        if ($action === 'mail.hostname.show') {
            return $manager->showHostname();
        }

        return $manager->setHostname((string) ($args[0] ?? ($input['hostname'] ?? '')));
    }
}
