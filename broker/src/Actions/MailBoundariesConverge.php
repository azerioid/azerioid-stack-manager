<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Component\ManagedManifest;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Mail\MailPaths;
use AzerioidPanel\Broker\Mail\MailProvisioner;
use AzerioidPanel\Broker\Mail\MailState;
use AzerioidPanel\Broker\Component\OsRelease;
use AzerioidPanel\Broker\Component\OperationLogger;
use AzerioidPanel\Broker\Runtime;

/**
 * mail.boundaries.converge (A123–A125): bring a mail stack provisioned before them in line.
 * Idempotent; the scheduler runs it, and a host without the panel's mail component is skipped.
 */
final class MailBoundariesConverge
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        if (!ManagedManifest::load($runtime, $config->managedComponentsPath)->has('mail')) {
            return ['applied' => false, 'reason' => 'mail is not installed'];
        }
        if (!$runtime->isDir(MailState::DIR)) {
            $runtime->mkdir(MailState::DIR, 0750);
        }
        $paths = MailPaths::for($runtime, $config, OsRelease::detect($runtime));

        return (new MailProvisioner($config, $runtime, $paths))
            ->convergeBoundaries(new OperationLogger($runtime, MailState::DIR . '/boundaries.log'));
    }
}
