<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Vhost\VhostUser;
use PHPUnit\Framework\TestCase;

final class TerminalSessionTest extends TestCase
{
    private FakeRuntime $rt;
    private Config $cfg;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->cfg->wwwRoot = '/data/www';
        $this->cfg->panelPort = 3169;
        $this->cfg->ttydBin = '/usr/local/bin/ttyd';
        $this->cfg->terminalSessionsPath = '/var/lib/azerioid-panel/terminal-sessions.json';
        $this->cfg->terminalCaddyRoutesPath = '/var/lib/azerioid-panel/caddy-terminal-routes.conf';
        $this->cfg->managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';

        $this->rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $this->rt->files['/usr/local/bin/ttyd'] = 'fake';
        $this->rt->files['/usr/sbin/runuser'] = 'fake';
        $this->rt->files['/usr/bin/systemd-run'] = 'fake';
        $this->rt->files['/bin/systemctl'] = 'fake';
        $this->rt->files['/etc/caddy/conf.d/shop.example.com.conf'] = <<<'CADDY'
shop.example.com {
    root * /data/www/shop.example.com
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server
}
CADDY;
        $this->rt->files['/etc/caddy/conf.d/projob.az.conf'] = file_get_contents(__DIR__ . '/../fixtures/vhost-projob.conf');
        $this->rt->dirs['/data/www/shop.example.com'] = true;
        $this->rt->dirs['/var/lib/azerioid-panel'] = true;
        $this->rt->uid = 1000;
        $this->rt->defaultExec = new \AzerioidPanel\Broker\ExecResult([], 0, "4242\n", '');

        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid');

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

    public function test_starts_session_for_user_vhost(): void
    {
        [$code, $json] = $this->capture(['broker', 'terminal.session.start', 'shop.example.com'], [
            'admin_user_id' => '1',
            'source_ip' => '127.0.0.1',
        ]);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertSame('shop.example.com', $json['data']['domain']);
        $this->assertStringStartsWith('az-vh-', $json['data']['username']);
        $this->assertStringContainsString('/terminal/', $json['data']['ws_path']);
        $this->assertStringContainsString('handle /terminal/', $this->rt->files[$this->cfg->terminalCaddyRoutesPath] ?? '');
        $spawned = false;
        foreach ($this->rt->execLog as $entry) {
            $cmd = implode(' ', $entry['command'] ?? []);
            if (str_contains($cmd, '/usr/bin/systemd-run') && str_contains($cmd, 'az-terminal-')) {
                $this->assertStringContainsString('-w /data/www/shop.example.com', $cmd);
                $spawned = true;
                break;
            }
        }
        $this->assertTrue($spawned, 'expected systemd-run ttyd spawn');
    }

    public function test_rejects_readonly_vhost_terminal(): void
    {
        [$code, $json] = $this->capture(['broker', 'terminal.session.start', 'projob.az'], [
            'admin_user_id' => '1',
            'source_ip' => '127.0.0.1',
        ]);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('read-only', strtolower((string) $json['error']));
    }

    public function test_vhost_user_name_is_derived_from_domain(): void
    {
        $this->assertSame('az-vh-shop-example-com', VhostUser::username('shop.example.com'));
    }

    public function test_stop_removes_session_metadata(): void
    {
        [$code] = $this->capture(['broker', 'terminal.session.start', 'shop.example.com'], [
            'admin_user_id' => '1',
            'source_ip' => '127.0.0.1',
        ]);
        $this->assertSame(0, $code);
        $raw = json_decode($this->rt->readFile($this->cfg->terminalSessionsPath), true);
        $id = (string) array_key_first($raw['sessions'] ?? []);
        [$stopCode] = $this->capture(['broker', 'terminal.session.stop', $id]);
        $this->assertSame(0, $stopCode);
        $after = json_decode($this->rt->readFile($this->cfg->terminalSessionsPath), true);
        $this->assertSame([], $after['sessions'] ?? []);
    }
}
