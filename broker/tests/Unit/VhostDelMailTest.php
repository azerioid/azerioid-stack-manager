<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Mail\MailState;
use PHPUnit\Framework\TestCase;

/**
 * A36 §9.1 scopes mailboxes to a vhost, so `vhost.del` can silently destroy real
 * mail. It must refuse until the operator asks for that by name.
 */
final class VhostDelMailTest extends TestCase
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
        $this->rt->files['/etc/os-release'] = "ID=ubuntu\nVERSION_ID=\"24.04\"\n";
        $this->rt->dirs['/data/www/site2.example.com'] = true;
        $this->rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $this->rt->files['/etc/caddy/conf.d/site2.example.com.conf'] = "site2.example.com {\n    root * /data/www/site2.example.com\n}\n";
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $this->rt->script(['/usr/bin/systemctl', 'reload', 'caddy'], 0);
        $this->rt->files[$this->cfg->managedComponentsPath] = json_encode([
            'components' => ['mail' => ['unit' => 'postfix', 'installed_at' => '2026-01-01']],
        ], JSON_THROW_ON_ERROR);
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

    private function withMailState(): void
    {
        $this->rt->dirs[MailState::DIR] = true;
        $this->rt->files[MailState::STATE_PATH] = json_encode([
            'hostname' => 'mail.site2.example.com',
            'domains' => ['site2.example.com' => ['enabled' => true, 'dkim_selector' => 'azerioid']],
            'mailboxes' => ['zaur@site2.example.com' => ['domain' => 'site2.example.com', 'local_part' => 'zaur']],
            'aliases' => ['postmaster@site2.example.com' => ['domain' => 'site2.example.com', 'destination' => 'zaur@site2.example.com']],
        ], JSON_THROW_ON_ERROR);
        $this->rt->files[MailState::PASSDB_PATH] = json_encode(
            ['zaur@site2.example.com' => '{BLF-CRYPT}$2y$05$hash'],
            JSON_THROW_ON_ERROR
        );
    }

    public function test_delete_refuses_when_the_domain_has_mailboxes(): void
    {
        $this->withMailState();

        [$code, $json] = $this->capture(['broker', 'vhost.del', 'site2.example.com']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('mail enabled', (string) $json['error']);
        $this->assertStringContainsString('DROP-MAIL', (string) $json['error']);
        $this->assertArrayHasKey('/etc/caddy/conf.d/site2.example.com.conf', $this->rt->files, 'The vhost must survive a refused delete.');
        $this->assertArrayHasKey(MailState::STATE_PATH, $this->rt->files);
    }

    public function test_drop_mail_without_the_typed_confirmation_is_refused(): void
    {
        $this->withMailState();

        [$code] = $this->capture(['broker', 'vhost.del', 'site2.example.com'], ['drop_mail' => true]);

        $this->assertNotSame(0, $code);
        $this->assertArrayHasKey(MailState::STATE_PATH, $this->rt->files);
    }

    public function test_confirmed_drop_mail_deletes_the_vhost_and_purges_the_domain(): void
    {
        $this->withMailState();

        [$code, $json] = $this->capture(
            ['broker', 'vhost.del', 'site2.example.com'],
            ['drop_mail' => true, 'confirm' => 'DROP-MAIL']
        );

        $this->assertSame(0, $code, (string) json_encode($json));
        $this->assertArrayNotHasKey('/etc/caddy/conf.d/site2.example.com.conf', $this->rt->files);

        $state = json_decode($this->rt->files[MailState::STATE_PATH], true);
        $this->assertSame([], $state['domains']);
        $this->assertSame([], $state['mailboxes']);
        $this->assertSame([], $state['aliases']);
        $this->assertSame([], json_decode($this->rt->files[MailState::PASSDB_PATH], true));
    }

    public function test_domain_without_mail_deletes_normally(): void
    {
        [$code] = $this->capture(['broker', 'vhost.del', 'site2.example.com']);

        $this->assertSame(0, $code);
        $this->assertArrayNotHasKey('/etc/caddy/conf.d/site2.example.com.conf', $this->rt->files);
    }

    public function test_mail_on_a_different_domain_does_not_block_this_delete(): void
    {
        $this->withMailState();
        $this->rt->dirs['/data/www/site6.example.com'] = true;
        $this->rt->files['/etc/caddy/conf.d/site6.example.com.conf'] = "site6.example.com {\n    root * /data/www/site6.example.com\n}\n";

        [$code] = $this->capture(['broker', 'vhost.del', 'site6.example.com']);

        $this->assertSame(0, $code);
        $this->assertArrayHasKey(MailState::STATE_PATH, $this->rt->files);
        $this->assertStringContainsString('zaur@site2.example.com', $this->rt->files[MailState::STATE_PATH]);
    }
}
