<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Mail\MailManager;
use AzerioidPanel\Broker\Mail\MailState;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Supervisor\SupervisorManager;
use AzerioidPanel\Broker\Terminal\TerminalManager;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\OctaneManager;
use AzerioidPanel\Broker\Vhost\VhostUser;
use AzerioidPanel\Broker\Web\WebServers;

final class VhostDel
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $domain = Validator::domain($args[0] ?? ($input['domain'] ?? ''));
        $mailDropped = self::dropMailOrRefuse($domain, $input, $runtime, $config);

        $manager = new SupervisorManager($config, $runtime);
        $linked = [];
        try {
            $manager->assertInstalled();
            $linked = $manager->programsForVhost($domain);
        } catch (BrokerException $e) {
            if ($e->errorCode !== 3 || !str_contains($e->getMessage(), 'not installed')) {
                throw $e;
            }
        }

        // The panel owns the octane-<domain> worker outright, so it goes with the vhost.
        // Operator-created processes still need the explicit remove flag.
        $octaneProgram = OctaneManager::programName($domain);
        $operatorOwned = array_values(array_filter(
            $linked,
            static fn (array $program): bool => ($program['name'] ?? '') !== $octaneProgram
        ));

        if ($operatorOwned !== [] && !self::boolInput($input['remove_supervisor_programs'] ?? false)) {
            $names = implode(', ', array_column($operatorOwned, 'name'));
            throw new BrokerException(
                "Vhost {$domain} has supervisor process(es): {$names}. "
                . 'Remove them first, or pass remove_supervisor_programs=true to delete with the vhost.',
                3
            );
        }

        foreach ($linked as $program) {
            $manager->delete((string) $program['name']);
        }

        $terminal = new TerminalManager($config, $runtime);
        $terminal->stopForVhost($domain);
        VhostUser::deprovision($runtime, $config, $domain);

        $result = WebServers::for($config)->removeVhost($runtime, $config, $domain);
        if ($mailDropped !== null) {
            $result['mail_dropped'] = $mailDropped;
        }

        return $result;
    }

    /**
     * Mailboxes are vhost-scoped (A36 §9.1), so deleting the vhost would orphan
     * real mail. Refuse until the operator asks for it by name — the Maildir tree
     * is not something the panel can put back.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null  purge result, or null when the domain had no mail
     */
    private static function dropMailOrRefuse(string $domain, array $input, Runtime $runtime, Config $config): ?array
    {
        $footprint = (new MailState($runtime))->domainMailFootprint($domain);
        if (!$footprint['has_data']) {
            return null;
        }
        if (!self::boolInput($input['drop_mail'] ?? false)) {
            $counts = count($footprint['mailboxes']) . ' mailbox(es), ' . count($footprint['aliases']) . ' alias(es)';
            throw new BrokerException(
                "Vhost {$domain} has mail enabled ({$counts}). Disable mail for this domain first, "
                . 'or pass drop_mail=true with confirm=' . Validator::DROP_MAIL_CONFIRM . ' to delete the mail data with the vhost.',
                3
            );
        }
        Validator::typedConfirm((string) ($input['confirm'] ?? ''), Validator::DROP_MAIL_CONFIRM);

        return (new MailManager($config, $runtime))->purgeDomain($domain);
    }

    private static function boolInput(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
