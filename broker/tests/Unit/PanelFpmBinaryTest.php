<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Panel\PanelFpmBinary;
use AzerioidPanel\Broker\Panel\PanelIdentityMigrator as M;
use PHPUnit\Framework\TestCase;

/**
 * KI-3: on EL the panel master runs a bin_t copy of php-fpm that used to be made once
 * and never refreshed, so the panel missed distro PHP security updates.
 */
final class PanelFpmBinaryTest extends TestCase
{
    private const COPY = '/usr/local/lib/azerioid-panel/sbin/php-fpm';

    private const DISTRO = '/usr/sbin/php-fpm';

    private FakeRuntime $rt;

    private Config $config;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->config = new Config();
        $this->config->panelRoot = '/usr/local/lib/azerioid-panel';
        $this->config->panelPhpVersion = '8.4';
    }

    private function seedEl(string $execArgs = '--nodaemonize -c /etc/azerioid-panel/php.ini --fpm-config /etc/azerioid-panel/php-fpm.conf'): void
    {
        $this->rt->files[M::UNIT_FILE] = "[Service]\nType=notify\nExecStart=" . self::COPY . ' ' . $execArgs . "\n";
        $this->rt->files[self::COPY] = 'old';
        $this->rt->files[self::DISTRO] = 'new';
    }

    private function ran(array $command): bool
    {
        foreach ($this->rt->execLog as $e) {
            if ($e['command'] === $command) {
                return true;
            }
        }

        return false;
    }

    public function test_apt_host_is_left_alone(): void
    {
        $this->rt->files[M::UNIT_FILE] = "[Service]\nExecStart=/usr/sbin/php-fpm8.4 --nodaemonize -c /etc/azerioid-panel/php.ini\n";

        $this->assertSame([], (new PanelFpmBinary($this->rt, $this->config))->refresh());
        $this->assertSame([], $this->rt->execLog);
    }

    public function test_current_copy_is_not_touched(): void
    {
        $this->seedEl();
        $this->rt->script(['/usr/bin/cmp', '-s', self::DISTRO, self::COPY], 0);

        $out = (new PanelFpmBinary($this->rt, $this->config))->refresh();

        $this->assertStringContainsString('current', $out[0]);
        $this->assertFalse($this->ran(['/bin/cp', '-f', self::DISTRO, self::COPY . '.new']));
        $this->assertFalse($this->ran(['/usr/bin/systemctl', 'restart', M::UNIT]));
    }

    public function test_stale_copy_is_replaced_relabelled_tested_and_restarted(): void
    {
        $this->seedEl();
        $this->rt->script(['/usr/bin/cmp', '-s', self::DISTRO, self::COPY], 1);

        $out = (new PanelFpmBinary($this->rt, $this->config))->refresh();

        $this->assertStringContainsString('refreshed', $out[0]);
        $this->assertTrue($this->ran(['/bin/cp', '-f', self::DISTRO, self::COPY . '.new']));
        $this->assertTrue($this->ran(['/sbin/restorecon', self::COPY . '.new']), 'the new copy must get its bin_t label');
        $this->assertTrue($this->ran(['/bin/mv', '-f', self::COPY . '.new', self::COPY]));
        $this->assertTrue(
            $this->ran([self::COPY, '-t', '-c', '/etc/azerioid-panel/php.ini', '--fpm-config', '/etc/azerioid-panel/php-fpm.conf']),
            'config test runs with the unit\'s own arguments, minus --nodaemonize'
        );
        $this->assertTrue($this->ran(['/usr/bin/systemctl', 'restart', M::UNIT]));
    }

    public function test_pre_migration_el_unit_is_tested_with_its_own_arguments(): void
    {
        // Before A39 the EL unit has no -c: the test must not invent one.
        $this->seedEl('--nodaemonize --fpm-config /etc/azerioid-panel/php-fpm.conf');
        $this->rt->script(['/usr/bin/cmp', '-s', self::DISTRO, self::COPY], 1);

        (new PanelFpmBinary($this->rt, $this->config))->refresh();

        $this->assertTrue($this->ran([self::COPY, '-t', '--fpm-config', '/etc/azerioid-panel/php-fpm.conf']));
    }

    public function test_failed_config_test_puts_the_previous_binary_back(): void
    {
        $this->seedEl();
        $this->rt->script(['/usr/bin/cmp', '-s', self::DISTRO, self::COPY], 1);
        $this->rt->script([self::COPY, '-t', '-c', '/etc/azerioid-panel/php.ini', '--fpm-config', '/etc/azerioid-panel/php-fpm.conf'], 78, '', 'bad');

        try {
            (new PanelFpmBinary($this->rt, $this->config))->refresh();
            $this->fail('a binary that fails its config test must not stay installed');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('put back', $e->getMessage());
        }
        $this->assertTrue($this->ran(['/bin/mv', '-f', self::COPY . '.previous', self::COPY]));
        $this->assertFalse($this->ran(['/usr/bin/systemctl', 'restart', M::UNIT]), 'nothing restarts onto a bad binary');
    }
}
