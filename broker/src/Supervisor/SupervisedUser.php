<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Supervisor;

use AzerioidPanel\Broker\Runtime;

/** Dedicated unprivileged account for all Supervisor-managed processes (v1). */
final class SupervisedUser
{
    public const USERNAME = 'azerioid-supervised';
    public const HOME = '/var/lib/azerioid-supervised';
    public const LOG_DIR = '/var/log/azerioid-supervised';

    public static function ensure(Runtime $runtime): void
    {
        if ($runtime->getuid() !== 0) {
            return;
        }
        $check = $runtime->exec(['/usr/bin/id', '-u', self::USERNAME], null, 10);
        if ($check->ok()) {
            return;
        }
        $runtime->exec([
            '/usr/sbin/useradd',
            '--system',
            '--home-dir', self::HOME,
            '--shell', '/usr/sbin/nologin',
            '--comment', 'AZERIOID Stack Manager supervised processes',
            self::USERNAME,
        ], null, 30);
        $runtime->mkdir(self::HOME, 0750);
        $runtime->mkdir(self::LOG_DIR, 0750);
        $runtime->exec(['/usr/bin/chown', '-R', self::USERNAME . ':' . self::USERNAME, self::HOME], null, 30);
        $runtime->exec(['/usr/bin/chown', '-R', self::USERNAME . ':' . self::USERNAME, self::LOG_DIR], null, 30);
    }
}
