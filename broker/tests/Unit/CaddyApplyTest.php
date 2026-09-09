<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\CaddyApply;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

final class CaddyApplyTest extends TestCase
{
    public function test_client_address_never_uses_localhost(): void
    {
        $this->assertSame('127.0.0.1:2019', CaddyApply::clientAddress('localhost:2019'));
        $this->assertSame('127.0.0.1:2019', CaddyApply::clientAddress('default'));
        $this->assertSame('127.0.0.1:2019', CaddyApply::clientAddress('off'));
        $this->assertSame('127.0.0.1:2019', CaddyApply::clientAddress('http://localhost:2019'));
        $this->assertSame('unix//run/caddy-admin.sock', CaddyApply::clientAddress('unix//run/caddy-admin.sock'));
    }

    public function test_apply_uses_explicit_ipv4_api_without_restart(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/caddy/Caddyfile'] = "{\n    admin 127.0.0.1:2019\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $rt->script(['/usr/bin/caddy', 'reload', '--config', '/etc/caddy/Caddyfile', '--address', '127.0.0.1:2019', '--force'], 0);
        $rt->script(['/usr/bin/systemctl', 'restart', 'caddy'], 1, '', 'restart must not run');

        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run(['broker', 'caddy.apply'], ['mode' => 'auto', 'expect_ports' => []]);
        $out = ob_get_clean();

        $this->assertSame(0, $code);
        $decoded = json_decode($out, true);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('api', $decoded['data']['path']);
        $this->assertSame('127.0.0.1:2019', $decoded['data']['address']);
        $this->assertFalse($decoded['data']['admin_enabled']);
        $restarts = array_filter($rt->execLog, fn ($e) => ($e['command'][1] ?? '') === 'restart');
        $this->assertSame([], $restarts);
    }

    public function test_enabling_admin_restarts_once_without_api_first(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $rt->script(['/usr/bin/caddy', 'reload', '--config', '/etc/caddy/Caddyfile', '--address', '127.0.0.1:2019', '--force'], 1, '', 'must not be called');
        $rt->script(['/usr/bin/systemctl', 'restart', 'caddy'], 0);

        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run(['broker', 'caddy.apply'], ['mode' => 'auto', 'expect_ports' => []]);
        $out = ob_get_clean();

        $this->assertSame(0, $code, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('restart', $decoded['data']['path']);
        $this->assertTrue($decoded['data']['admin_enabled']);
        $api = array_filter($rt->execLog, fn ($e) => ($e['command'][0] ?? '') === '/usr/bin/caddy' && ($e['command'][1] ?? '') === 'reload');
        $this->assertSame([], $api);
    }

    public function test_port_health_failure_is_specific(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/caddy/Caddyfile'] = "{\n    admin 127.0.0.1:2019\n}\n";
        $rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $rt->script(['/usr/bin/ss', '-tln'], 0, 'LISTEN 0 4096 127.0.0.1:80\n');

        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run(['broker', 'caddy.apply'], ['mode' => 'auto', 'expect_ports' => [59111]]);
        $out = ob_get_clean();

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('nothing is listening on port 59111', $out);
    }

    public function test_validate_and_reload_run_as_caddy_service_user(): void
    {
        $rt = new FakeRuntime();
        $rt->dirs['/var/lib/caddy'] = true;
        $rt->files['/usr/sbin/runuser'] = '';
        $rt->files['/usr/bin/env'] = '';
        $rt->files['/usr/bin/chown'] = '';
        $rt->files['/etc/caddy/Caddyfile'] = "{\n    admin 127.0.0.1:2019\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $rt->script(['/usr/bin/systemctl', 'show', 'caddy', '-p', 'User', '--value'], 0, "caddy\n");
        $rt->script(['/usr/bin/chown', '-R', 'caddy:caddy', '/var/lib/caddy'], 0);
        $env = ['/usr/bin/env', 'HOME=/var/lib/caddy', 'XDG_CONFIG_HOME=/var/lib/caddy/.config', 'XDG_DATA_HOME=/var/lib/caddy/.local/share'];
        $rt->script(
            array_merge(['/usr/sbin/runuser', '-u', 'caddy', '--'], $env, ['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile']),
            0,
            'Valid configuration'
        );
        $rt->script(
            array_merge(['/usr/sbin/runuser', '-u', 'caddy', '--'], $env, ['/usr/bin/caddy', 'reload', '--config', '/etc/caddy/Caddyfile', '--address', '127.0.0.1:2019', '--force']),
            0
        );
        $rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 1, '', 'must not run as root');
        $rt->script(['/usr/bin/caddy', 'reload', '--config', '/etc/caddy/Caddyfile', '--address', '127.0.0.1:2019', '--force'], 1, '', 'must not run as root');

        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run(['broker', 'caddy.apply'], ['mode' => 'auto', 'expect_ports' => []]);
        $out = ob_get_clean();

        $this->assertSame(0, $code, $out);
        $rootCli = array_filter($rt->execLog, static function (array $e): bool {
            return ($e['command'][0] ?? '') === '/usr/bin/caddy';
        });
        $this->assertSame([], $rootCli);
        $asUser = array_filter($rt->execLog, static function (array $e): bool {
            return ($e['command'][0] ?? '') === '/usr/sbin/runuser'
                && ($e['command'][1] ?? '') === '-u'
                && ($e['command'][2] ?? '') === 'caddy';
        });
        $this->assertNotSame([], $asUser);
        foreach ($asUser as $e) {
            $this->assertContains('XDG_DATA_HOME=/var/lib/caddy/.local/share', $e['command']);
            $this->assertNotContains('XDG_DATA_HOME=/root/.local/share', $e['command']);
        }
        $chowns = array_filter($rt->execLog, static function (array $e): bool {
            return ($e['command'][0] ?? '') === '/usr/bin/chown'
                && ($e['command'][3] ?? '') === '/var/lib/caddy';
        });
        $this->assertNotSame([], $chowns);
    }

    public function test_root_caddy_unit_still_pins_xdg_data_home(): void
    {
        $rt = new FakeRuntime();
        $rt->dirs['/var/lib/caddy'] = true;
        $rt->files['/usr/sbin/runuser'] = '';
        $rt->files['/usr/bin/env'] = '';
        $rt->files['/etc/caddy/Caddyfile'] = "{\n    admin 127.0.0.1:2019\n}\n";
        $rt->script(['/usr/bin/systemctl', 'show', 'caddy', '-p', 'User', '--value'], 0, "root\n");
        $env = ['/usr/bin/env', 'HOME=/var/lib/caddy', 'XDG_CONFIG_HOME=/var/lib/caddy/.config', 'XDG_DATA_HOME=/var/lib/caddy/.local/share'];
        $rt->script(array_merge($env, ['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile']), 0, 'Valid configuration');
        $rt->script(array_merge($env, ['/usr/bin/caddy', 'reload', '--config', '/etc/caddy/Caddyfile', '--address', '127.0.0.1:2019', '--force']), 0);

        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run(['broker', 'caddy.apply'], ['mode' => 'auto', 'expect_ports' => []]);
        ob_end_clean();

        $this->assertSame(0, $code);
        $asUser = array_filter($rt->execLog, static fn (array $e) => ($e['command'][0] ?? '') === '/usr/sbin/runuser');
        $this->assertSame([], $asUser);
        $chowns = array_filter($rt->execLog, static fn (array $e) => ($e['command'][0] ?? '') === '/usr/bin/chown');
        $this->assertSame([], $chowns);
        $pinned = array_filter($rt->execLog, static function (array $e): bool {
            return ($e['command'][0] ?? '') === '/usr/bin/env'
                && in_array('XDG_DATA_HOME=/var/lib/caddy/.local/share', $e['command'], true);
        });
        $this->assertNotSame([], $pinned);
    }
}
