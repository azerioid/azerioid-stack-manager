<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Mail\MailManager;
use AzerioidPanel\Broker\Runtime;

/** mail.alias.list · mail.alias.add · mail.alias.del */
final class MailAlias
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new MailManager($config, $runtime);
        $address = (string) ($args[0] ?? ($input['address'] ?? ''));

        return match ($action) {
            'mail.alias.list' => $manager->listAliases(MailInput::optionalDomain($input['domain'] ?? '')),
            'mail.alias.add' => $manager->addAlias(
                $address,
                (string) ($args[1] ?? ($input['destination'] ?? '')),
                MailInput::bool($input['confirm_external'] ?? false)
            ),
            default => $manager->deleteAlias($address),
        };
    }
}
