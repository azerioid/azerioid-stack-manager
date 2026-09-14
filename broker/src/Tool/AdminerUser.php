<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tool;

use AzerioidPanel\Broker\Runtime;

/** Dedicated unprivileged account for the Adminer PHP-FPM pool. */
final class AdminerUser
{
    public const USERNAME = 'azerioid-adminer-tool';
    public const HOME = '/var/lib/azerioid-panel/tools/adminer';

    public static function ensure(Runtime $runtime, string $panelDataGroup = 'www-data'): void
    {
        if ($runtime->getuid() !== 0) {
            return;
        }
        $check = $runtime->exec(['/usr/bin/id', '-u', self::USERNAME], null, 10);
        if (!$check->ok()) {
            $runtime->exec([
                '/usr/sbin/useradd',
                '--system',
                '--home-dir', self::HOME,
                '--shell', '/usr/sbin/nologin',
                '--comment', 'AZERIOID Stack Manager Adminer tool',
                self::USERNAME,
            ], null, 30);
        }
        if ($panelDataGroup !== '') {
            $runtime->exec(['/usr/sbin/usermod', '-a', '-G', $panelDataGroup, self::USERNAME], null, 30);
        }
        $runtime->mkdir(self::HOME, 0750);
        $runtime->exec(['/usr/bin/chmod', '0750', self::HOME], null, 15);
        $runtime->exec(['/usr/bin/chown', '-R', self::USERNAME . ':' . self::USERNAME, self::HOME], null, 30);
    }
}
