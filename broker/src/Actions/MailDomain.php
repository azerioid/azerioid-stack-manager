<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Mail\MailManager;
use AzerioidPanel\Broker\Runtime;

/** mail.domain.list · mail.domain.enable · mail.domain.disable */
final class MailDomain
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new MailManager($config, $runtime);
        $domain = (string) ($args[0] ?? ($input['domain'] ?? ''));

        return match ($action) {
            'mail.domain.list' => $manager->listDomains(),
            'mail.domain.enable' => $manager->enableDomain($domain),
            default => $manager->disableDomain(
                $domain,
                MailInput::bool($input['drop_mail'] ?? false),
                (string) ($input['confirm'] ?? '')
            ),
        };
    }
}
