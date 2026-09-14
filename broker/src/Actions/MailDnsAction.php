<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Mail\MailManager;
use AzerioidPanel\Broker\Runtime;

/** mail.dns.records · mail.dkim.rotate */
final class MailDnsAction
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new MailManager($config, $runtime);
        $domain = (string) ($args[0] ?? ($input['domain'] ?? ''));

        if ($action === 'mail.dkim.rotate') {
            return $manager->rotateDkim($domain);
        }

        $verify = !array_key_exists('verify', $input) || filter_var($input['verify'], FILTER_VALIDATE_BOOLEAN);

        return $manager->dnsRecords($domain, $verify);
    }
}
