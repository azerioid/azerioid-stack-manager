<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Files\VhostFiles;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

final class VhostFilesActionTest extends TestCase
{
    private FakeRuntime $rt;
    private Config $cfg;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->cfg->wwwRoot = '/data/www';
        $this->cfg->auditLog = '/var/log/azerioid-panel/broker-audit.log';
        $this->cfg->managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';
        $this->rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $this->rt->files['/etc/caddy/conf.d/shop.example.com.conf'] = <<<'CADDY'
shop.example.com {
    root * /data/www/shop.example.com
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server
}
CADDY;
        $this->rt->files['/etc/caddy/conf.d/projob.az.conf'] = file_get_contents(__DIR__ . '/../fixtures/vhost-projob.conf');
        $this->rt->dirs['/data/www/shop.example.com'] = true;
        $this->rt->uid = 1000;
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

    public function test_rejects_readonly_vhost_at_broker(): void
    {
        [$code, $json] = $this->capture(['broker', 'vhost.files.list', 'projob.az'], ['path' => '']);
        $this->assertSame(3, $code, json_encode($json));
        $this->assertStringContainsString('read-only', strtolower((string) ($json['error'] ?? '')));
        $this->assertSame([], $this->rt->execLog);
    }

    public function test_rejects_unknown_vhost(): void
    {
        [$code, $json] = $this->capture(['broker', 'vhost.files.list', 'missing.example.com']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('not found', strtolower((string) ($json['error'] ?? '')));
    }

    public function test_list_invokes_helper_as_vhost_user_and_never_reads_docroot(): void
    {
        $payload = json_encode([
            'ok' => true,
            'data' => [
                'path' => '',
                'entries' => [[
                    'name' => 'index.php',
                    'type' => 'file',
                    'size' => 3,
                    'mtime' => 0,
                    'mode' => '0660',
                    'link' => false,
                    'escaped' => false,
                ]],
            ],
        ], JSON_UNESCAPED_SLASHES);
        $this->rt->script([PHP_BINARY, VhostFiles::HELPER], 0, $payload . "\n");

        [$code, $json] = $this->capture(['broker', 'vhost.files.list', 'shop.example.com'], [
            'path' => '',
            'admin_user_id' => '7',
        ]);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertSame('shop.example.com', $json['data']['domain']);
        $this->assertSame('az-vh-shop-example-com', $json['data']['username']);
        $this->assertCount(1, $this->rt->execLog);
        $stdin = (string) $this->rt->execLog[0]['stdin'];
        $decoded = json_decode($stdin, true);
        $this->assertSame('list', $decoded['op'] ?? null);
        $this->assertSame('az-vh-shop-example-com', $decoded['drop_user'] ?? null);
        $this->assertSame('/data/www/shop.example.com', $decoded['root'] ?? null);
        $this->assertSame([PHP_BINARY, VhostFiles::HELPER], $this->rt->execLog[0]['command']);
    }

    public function test_write_audit_redacts_file_bytes(): void
    {
        $this->rt->script([PHP_BINARY, VhostFiles::HELPER], 0, json_encode([
            'ok' => true,
            'data' => ['path' => 'secret.txt', 'size' => 12, 'written' => true],
        ]) . "\n");
        $secret = base64_encode('super-secret');
        [$code] = $this->capture(['broker', 'vhost.files.write', 'shop.example.com'], [
            'path' => 'secret.txt',
            'content_base64' => $secret,
            'admin_user_id' => '7',
        ]);
        $this->assertSame(0, $code);
        $log = $this->rt->files[$this->cfg->auditLog] ?? '';
        $this->assertStringContainsString('vhost.files.write', $log);
        $this->assertStringContainsString('secret.txt', $log);
        $this->assertStringContainsString('shop.example.com', $log);
        $this->assertStringContainsString('[redacted]', $log);
        $this->assertStringNotContainsString($secret, $log);
        $this->assertStringNotContainsString('super-secret', $log);
    }
}
