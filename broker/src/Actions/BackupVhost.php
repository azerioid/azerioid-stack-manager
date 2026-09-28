<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Backup\VhostBundle;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

/**
 * Vhost bundles (B6, ADR A52).
 *
 *  backup.vhost.settings        the databases that belong to a site
 *  backup.vhost.settings.set    replace that list
 *  backup.vhost.run             write a bundle (manifest + parts)
 *  backup.vhost.list            bundles by name, no passphrase
 *  backup.vhost.restore         preview, or with apply + typed domain confirm, restore parts
 */
final class BackupVhost
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $bundle = new VhostBundle($config, $runtime);
        if ($action === 'backup.vhost.list') {
            return $bundle->list($input);
        }
        $domain = Validator::domain((string) ($args[0] ?? ($input['domain'] ?? '')));

        return match ($action) {
            'backup.vhost.settings' => $bundle->settings($domain),
            'backup.vhost.settings.set' => $bundle->saveSettings($domain, $input),
            'backup.vhost.run' => $bundle->run($domain, $input),
            'backup.vhost.restore' => $bundle->restore($domain, $input),
            default => throw new BrokerException('Unknown vhost backup action.', 2),
        };
    }
}
