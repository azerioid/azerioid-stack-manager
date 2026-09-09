<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use PHPUnit\Framework\TestCase;

final class SupervisorProgramTest extends TestCase
{
    private FakeRuntime $rt;
    private Config $cfg;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->cfg->wwwRoot = '/data/www';
        $this->cfg->stagingDir = '/var/lib/azerioid-panel/staging';
        $this->cfg->managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';
        $this->rt->dirs['/data/www'] = true;
        $this->rt->dirs['/data/www/myapp.example.com'] = true;
        $this->rt->dirs['/var/lib/azerioid-supervised'] = true;
        $this->rt->dirs['/var/lib/azerioid-supervised/apps'] = true;
        $this->rt->dirs['/etc/supervisor/conf.d'] = true;
        $this->rt->dirs['/var/log/azerioid-supervised'] = true;
        $this->rt->files[$this->cfg->managedComponentsPath] = json_encode([
            'components' => [
                'supervisor' => ['unit' => 'supervisor', 'installed_at' => '2026-01-01'],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->mockSupervisorctlOk();
        $this->kernel = new Kernel($this->cfg, $this->rt);
    }

    /** @return array{0:int,1:array} */
    private function capture(array $argv, array $stdin = []): array
    {
        ob_start();
        $code = $this->kernel->run($argv, $stdin);
        $out = ob_get_clean();

        return [$code, json_decode(trim((string) $out), true)];
    }

    private function mockSupervisorctlOk(): void
    {
        foreach (['reread', 'update', 'start', 'stop', 'restart', 'remove'] as $verb) {
            $this->rt->script(['/usr/bin/supervisorctl', $verb], 0, "{$verb}: ok");
            $this->rt->script(['/usr/bin/supervisorctl', $verb, 'azerioid-demo'], 0, "{$verb} azerioid-demo: ok");
        }
        $this->rt->script(['/usr/bin/supervisorctl', 'status', 'azerioid-demo'], 0, 'azerioid-demo RUNNING pid 42, uptime 0:01:00');
    }

    public function test_create_freeform_program_writes_config_as_supervised_user(): void
    {
        [$code, $json] = $this->capture(['broker', 'supervisor.program.create'], [
            'name' => 'demo',
            'command' => '/usr/bin/node app.js',
            'directory' => '/var/lib/azerioid-supervised/apps/demo',
            'autostart' => true,
            'autorestart' => true,
        ]);
        $this->assertSame(0, $code, json_encode($json));
        $conf = $this->rt->files['/etc/supervisor/conf.d/azerioid-demo.conf'] ?? '';
        $this->assertStringContainsString('user=' . SupervisedUser::USERNAME, $conf);
        $this->assertStringContainsString('command=/usr/bin/node app.js', $conf);
    }

    public function test_rejects_root_run_as_user_via_direct_broker_call(): void
    {
        [$code, $json] = $this->capture(['broker', 'supervisor.program.create'], [
            'name' => 'evil',
            'command' => '/usr/bin/true',
            'directory' => '/var/lib/azerioid-supervised/apps',
            'user' => 'root',
        ]);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Refusing privileged', (string) $json['error']);
        $this->assertArrayNotHasKey('/etc/supervisor/conf.d/azerioid-evil.conf', $this->rt->files);
    }

    public function test_rolls_back_invalid_config_on_reread_failure(): void
    {
        $this->rt->script(['/usr/bin/supervisorctl', 'reread'], 0, '', 'ERROR: invalid syntax');
        [$code, $json] = $this->capture(['broker', 'supervisor.program.create'], [
            'name' => 'bad',
            'command' => '/usr/bin/sleep 999',
            'directory' => '/var/lib/azerioid-supervised/apps',
        ]);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('rolled back', (string) $json['error']);
        $this->assertArrayNotHasKey('/etc/supervisor/conf.d/azerioid-bad.conf', $this->rt->files);
    }

    public function test_create_ensures_default_apps_directory(): void
    {
        unset($this->rt->dirs['/var/lib/azerioid-supervised/apps']);
        [$code] = $this->capture(['broker', 'supervisor.program.create'], [
            'name' => 'apps-dir',
            'command' => '/usr/bin/sleep 1',
            'directory' => '/var/lib/azerioid-supervised/apps',
        ]);
        $this->assertSame(0, $code);
        $this->assertTrue($this->rt->dirs['/var/lib/azerioid-supervised/apps'] ?? false);
    }

    public function test_el_writes_ini_into_supervisord_d(): void
    {
        $this->rt->files['/etc/supervisord.conf'] = "[include]\nfiles = supervisord.d/*.ini\n";
        $this->rt->dirs['/etc/supervisord.d'] = true;
        [$code] = $this->capture(['broker', 'supervisor.program.create'], [
            'name' => 'elini',
            'command' => '/usr/bin/sleep 5',
            'directory' => '/var/lib/azerioid-supervised/apps',
        ]);
        $this->assertSame(0, $code);
        $this->assertArrayHasKey('/etc/supervisord.d/azerioid-elini.ini', $this->rt->files);
        $this->assertArrayNotHasKey('/etc/supervisor/conf.d/azerioid-elini.conf', $this->rt->files);
    }

    public function test_delete_removes_config_and_metadata(): void
    {
        $this->capture(['broker', 'supervisor.program.create'], [
            'name' => 'demo',
            'command' => '/usr/bin/true',
            'directory' => '/var/lib/azerioid-supervised/apps',
        ]);
        [$code] = $this->capture(['broker', 'supervisor.program.delete', 'demo']);
        $this->assertSame(0, $code);
        $this->assertArrayNotHasKey('/etc/supervisor/conf.d/azerioid-demo.conf', $this->rt->files);
    }

    public function test_vhost_delete_blocked_when_supervisor_program_linked(): void
    {
        $this->rt->files['/etc/caddy/conf.d/app.example.com.conf'] = "app.example.com {\n    root * /data/www/app.example.com\n}\n";
        $this->rt->dirs['/data/www/app.example.com'] = true;
        [$code] = $this->capture(['broker', 'supervisor.program.create'], [
            'name' => 'nodeapp',
            'command' => 'node server.js',
            'directory' => '/data/www/app.example.com',
            'vhost_domain' => 'app.example.com',
        ]);
        $this->assertSame(0, $code);
        [$delCode, $delJson] = $this->capture(['broker', 'vhost.del', 'app.example.com']);
        $this->assertNotSame(0, $delCode);
        $this->assertStringContainsString('supervisor process', (string) $delJson['error']);
    }

    public function test_list_requires_supervisor_installed(): void
    {
        unset($this->rt->files[$this->cfg->managedComponentsPath]);
        [$code, $json] = $this->capture(['broker', 'supervisor.program.list']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('not installed', (string) $json['error']);
    }
}
