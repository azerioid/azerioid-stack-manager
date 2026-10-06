<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\PosixRuntime;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use AzerioidPanel\Broker\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the A47 broker hardening pass (source audit of broker/src).
 * Each asserts the broker emits the safe behaviour, not the OS outcome, so they run
 * under FakeRuntime / a scratch dir without a Linux host.
 */
final class AuditHardeningTest extends TestCase
{
    public function test_supervised_log_dir_stays_root_owned(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/id', '-u', SupervisedUser::USERNAME], 0);

        SupervisedUser::ensure($rt);

        $logChowns = array_values(array_filter(
            $rt->execLog,
            static fn (array $e): bool => ($e['command'][0] ?? '') === '/usr/bin/chown'
                && str_contains((string) end($e['command']), SupervisedUser::LOG_DIR)
        ));
        $this->assertNotEmpty($logChowns, 'the log dir ownership must be set explicitly');
        foreach ($logChowns as $e) {
            $spec = (string) $e['command'][array_search('-R', $e['command'], true) + 1];
            $this->assertSame('root:' . SupervisedUser::USERNAME, $spec,
                'supervisord writes these logs as root; the account must not own the dir');
        }
    }

    public function test_web_root_rejects_control_characters(): void
    {
        $rt = new FakeRuntime();
        $rt->dirs['/data/www'] = true;
        $this->expectException(BrokerException::class);
        Validator::webRoot("/data/www/evil\n[program:x]", '/data/www', $rt);
    }

    public function test_write_file_is_never_world_readable_for_a_private_mode(): void
    {
        $rt = new PosixRuntime();
        $dir = sys_get_temp_dir() . '/azaudit-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $target = $dir . '/secret';
        try {
            $rt->writeFile($target, "relay:password\n", 0600);
            $this->assertSame("relay:password\n", file_get_contents($target));
            clearstatcache();
            $this->assertSame('0600', substr(sprintf('%o', fileperms($target)), -4),
                'a 0600 secret must land at its final mode, never a 0644 window');
            // No leftover temp files from the atomic write.
            $this->assertSame(['secret'], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])));
        } finally {
            @unlink($target);
            @rmdir($dir);
        }
    }
}
