<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Sftp\SftpManager;

/**
 * SFTP actions (A48, B3 / request #11).
 *
 * `sftp.status` reads; the rest change sshd's configuration through a drop-in, validate it with
 * `sshd -t`, and reload rather than restart. Every guarantee lives in SftpManager, not here, so the
 * CLI and the panel get the same one.
 */
final class SftpManage
{
    /**
     * @param  list<string>  $args
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new SftpManager($runtime, $config);
        $domain = (string) ($args[0] ?? $input['domain'] ?? '');

        return match ($action) {
            'sftp.status' => $manager->status(),
            'sftp.configure' => $manager->configure(),
            'sftp.unconfigure' => $manager->unconfigure(),
            'sftp.enable' => $manager->enable($domain),
            'sftp.disable' => $manager->disable($domain),
            default => throw new BrokerException('Unsupported sftp action: ' . $action, 2),
        };
    }
}
