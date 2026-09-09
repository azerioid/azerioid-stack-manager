<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AzerioidCliTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMockingConsoleOutput();
    }

    public function test_status_json_uses_broker_status_all(): void
    {
        $code = Artisan::call('azerioid:status', ['--json' => true]);
        $this->assertSame(0, $code);
        $out = Artisan::output();
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('controlled', $decoded);
        $this->assertArrayHasKey('components', $decoded);
    }

    public function test_vhost_list_json_includes_tls_status_fields(): void
    {
        $code = Artisan::call('azerioid:vhost', ['action' => 'list', '--json' => true]);
        $this->assertSame(0, $code);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded['vhosts'] ?? null);
        $shop = collect($decoded['vhosts'])->firstWhere('domain', 'shop.example.com');
        $this->assertIsArray($shop);
        $this->assertArrayHasKey('tls_status', $shop);
        $this->assertSame('lets_encrypt', $shop['tls_status']['issuer_type'] ?? null);
        $this->assertArrayHasKey('pending', $shop['tls_status']);
        $this->assertArrayHasKey('failed', $shop['tls_status']);
        $this->assertArrayHasKey('label', $shop['tls_status']);
    }

    public function test_vhost_add_with_tls_auto_calls_edit(): void
    {
        $code = Artisan::call('azerioid:vhost', [
            'action' => 'add',
            '--domain' => 'tls-auto.example.com',
            '--type' => 'static',
            '--root' => '/data/www/tls-auto.example.com',
            '--tls' => 'auto',
        ]);
        $this->assertSame(0, $code, Artisan::output());

        $fake = $this->app->make(FakeBroker::class);
        $row = collect($fake->vhosts)->firstWhere('domain', 'tls-auto.example.com');
        $this->assertNotNull($row);
        $this->assertTrue((bool) ($row['tls'] ?? false));
        $this->assertSame('auto', $row['tls_mode'] ?? null);
    }

    public function test_vhost_add_dns_tls_uses_env_token_not_argv(): void
    {
        putenv('AZERIOID_DNS_API_TOKEN=test-dns-token-for-cli-fake');
        try {
            $code = Artisan::call('azerioid:vhost', [
                'action' => 'add',
                '--domain' => 'tls-dns.example.com',
                '--type' => 'static',
                '--root' => '/data/www/tls-dns.example.com',
                '--tls' => 'dns',
                '--dns-provider' => 'cloudflare',
                '--staging' => true,
            ]);
            $this->assertSame(0, $code, Artisan::output());
            $fake = $this->app->make(FakeBroker::class);
            $this->assertTrue($fake->dnsCredentialsPresent['cloudflare'] ?? false);
            $row = collect($fake->vhosts)->firstWhere('domain', 'tls-dns.example.com');
            $this->assertSame('dns01', $row['tls_mode'] ?? null);
        } finally {
            putenv('AZERIOID_DNS_API_TOKEN');
        }
    }

    public function test_help_mentions_tls_modes_and_dns_env(): void
    {
        $code = Artisan::call('azerioid:help');
        $this->assertSame(0, $code);
        $out = Artisan::output();
        $this->assertStringContainsString('--tls=off|auto|internal|dns01', $out);
        $this->assertStringContainsString('--engine=caddy|apache|nginx', $out);
        $this->assertStringContainsString('AZERIOID_DNS_API_TOKEN', $out);
        $this->assertStringContainsString('azerioid panel domain set', $out);
        $this->assertStringContainsString('azerioid service', $out);
        $this->assertStringContainsString('--confirm', $out);
    }

    public function test_vhost_list_json(): void
    {
        $code = Artisan::call('azerioid:vhost', ['action' => 'list', '--json' => true]);
        $this->assertSame(0, $code);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded['vhosts'] ?? null);
    }

    public function test_vhost_add_and_del_go_through_broker(): void
    {
        $code = Artisan::call('azerioid:vhost', [
            'action' => 'add',
            '--domain' => 'cli-test.example.com',
            '--type' => 'static',
            '--root' => '/data/www/cli-test.example.com',
        ]);
        $this->assertSame(0, $code, Artisan::output());

        $fake = $this->app->make(FakeBroker::class);
        $domains = array_column($fake->vhosts, 'domain');
        $this->assertContains('cli-test.example.com', $domains);

        $del = Artisan::call('azerioid:vhost', [
            'action' => 'del',
            '--domain' => 'cli-test.example.com',
        ]);
        $this->assertSame(0, $del);
    }

    public function test_vhost_add_engine_flag_is_persisted(): void
    {
        $code = Artisan::call('azerioid:vhost', [
            'action' => 'add',
            '--domain' => 'cli-apache.example.com',
            '--type' => 'static',
            '--root' => '/data/www/cli-apache.example.com',
            '--engine' => 'apache',
        ]);
        $this->assertSame(0, $code, Artisan::output());
        $fake = $this->app->make(FakeBroker::class);
        $row = collect($fake->vhosts)->firstWhere('domain', 'cli-apache.example.com');
        $this->assertSame('apache', $row['engine'] ?? null);

        $edit = Artisan::call('azerioid:vhost', [
            'action' => 'edit',
            '--domain' => 'cli-apache.example.com',
            '--engine' => 'nginx',
        ]);
        $this->assertSame(0, $edit, Artisan::output());
        $row = collect($fake->vhosts)->firstWhere('domain', 'cli-apache.example.com');
        $this->assertSame('nginx', $row['engine'] ?? null);
    }

    public function test_vhost_del_rejects_readonly_panel_vhost(): void
    {
        $code = Artisan::call('azerioid:vhost', [
            'action' => 'del',
            '--domain' => 'projob.az',
        ]);
        $out = Artisan::output();
        $this->assertNotSame(0, $code, $out);
        $this->assertStringContainsString('managed externally', $out);
    }

    public function test_db_add_reveals_password_once_and_list_has_none(): void
    {
        $fake = $this->app->make(FakeBroker::class);
        $fake->databaseEngine = 'mariadb';

        $code = Artisan::call('azerioid:db', [
            'action' => 'add',
            '--engine' => 'mariadb',
            '--name' => 'cliapp',
            '--user' => 'cliapp',
        ]);
        // Artisan::output() fetches (clears) the buffer — capture once.
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('One-time password', $out);
        $this->assertMatchesRegularExpression('/[A-Za-z0-9_-]{20,}/', $out);

        $list = Artisan::call('azerioid:db', [
            'action' => 'list',
            '--engine' => 'mariadb',
            '--json' => true,
        ]);
        $this->assertSame(0, $list);
        $decoded = json_decode(Artisan::output(), true);
        $json = json_encode($decoded);
        $this->assertStringNotContainsString('One-time password', (string) $json);
        $this->assertDoesNotMatchRegularExpression('/"password"\s*:/', (string) $json);
    }

    public function test_component_install_unknown_id_fails(): void
    {
        $code = Artisan::call('azerioid:component', [
            'action' => 'install',
            'id' => 'not-a-real-component-xyz',
        ]);
        $this->assertNotSame(0, $code);
    }

    public function test_process_create_uses_supervised_user_path(): void
    {
        $fake = $this->app->make(FakeBroker::class);
        $fake->fakeInstalledComponents['supervisor'] = true;

        $code = Artisan::call('azerioid:process', [
            'action' => 'create',
            '--freeform' => true,
            '--name' => 'cli-demo',
            '--command' => 'node app.js',
            '--directory' => '/var/lib/azerioid-supervised/apps',
        ]);
        $this->assertSame(0, $code, Artisan::output());
        $this->assertArrayHasKey('cli-demo', $fake->supervisorPrograms);
        $this->assertSame('azerioid-supervised', $fake->supervisorPrograms['cli-demo']['user'] ?? null);
    }

    public function test_mutating_cli_writes_audit_with_origin_cli(): void
    {
        User::factory()->create();
        $code = Artisan::call('azerioid:vhost', [
            'action' => 'add',
            '--domain' => 'audit-cli.example.com',
            '--type' => 'static',
            '--root' => '/data/www/audit-cli.example.com',
        ]);
        $this->assertSame(0, $code, Artisan::output());

        $row = AuditLog::query()->where('action', 'vhost.add')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->ok);
        $this->assertSame('cli', $row->args['origin'] ?? null);
    }

    public function test_vhost_add_bad_root_fails_cleanly(): void
    {
        $code = Artisan::call('azerioid:vhost', [
            'action' => 'add',
            '--domain' => 'badroot.example.com',
            '--type' => 'static',
            '--root' => '/etc/passwd',
        ]);
        $this->assertNotSame(0, $code);
    }

    public function test_db_access_global_requires_confirm_and_rejects_injection(): void
    {
        $code = Artisan::call('azerioid:db', [
            'action' => 'access',
            'subcommand' => 'set',
            '--engine' => 'mariadb',
            '--name' => 'projob',
            '--mode' => 'global',
        ]);
        $out = Artisan::output();
        $this->assertNotSame(0, $code, $out);
        $this->assertStringContainsString('--confirm', $out);

        $inject = Artisan::call('azerioid:db', [
            'action' => 'access',
            'subcommand' => 'set',
            '--engine' => 'mariadb',
            '--name' => 'projob',
            '--mode' => 'specific',
            '--ip' => '1.2.3.4; DROP TABLE x',
        ]);
        $injectOut = Artisan::output();
        $this->assertNotSame(0, $inject, $injectOut);
        $this->assertStringContainsString('Invalid IP', $injectOut);
        $this->assertArrayNotHasKey('projob', $this->app->make(FakeBroker::class)->dbAccess['mariadb'] ?? []);

        $ok = Artisan::call('azerioid:db', [
            'action' => 'access',
            'subcommand' => 'set',
            '--engine' => 'mariadb',
            '--name' => 'projob',
            '--mode' => 'specific',
            '--ip' => '203.0.113.5',
        ]);
        $this->assertSame(0, $ok, Artisan::output());
        $this->assertSame(['203.0.113.5'], $this->app->make(FakeBroker::class)->dbAccess['mariadb']['projob']['ips']);

        $global = Artisan::call('azerioid:db', [
            'action' => 'access',
            'subcommand' => 'set',
            '--engine' => 'mariadb',
            '--name' => 'projob',
            '--mode' => 'global',
            '--confirm' => true,
        ]);
        $this->assertSame(0, $global, Artisan::output());
        $this->assertSame('global', $this->app->make(FakeBroker::class)->dbAccess['mariadb']['projob']['mode']);

        $row = AuditLog::query()->where('action', 'db.access.set')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->ok);
        $this->assertSame('global', $row->args['mode'] ?? null);
    }

    public function test_panel_domain_show_set_clear(): void
    {
        $show = Artisan::call('azerioid:panel', ['action' => 'domain', 'op' => 'show']);
        $this->assertSame(0, $show);
        $this->assertStringContainsString('not set', Artisan::output());

        $set = Artisan::call('azerioid:panel', [
            'action' => 'domain',
            'op' => 'set',
            '--domain' => 'panel.example.com',
            '--tls' => 'internal',
        ]);
        $this->assertSame(0, $set, Artisan::output());
        $this->assertSame('panel.example.com', $this->app->make(FakeBroker::class)->panelDomain);
        $this->assertSame('internal', $this->app->make(FakeBroker::class)->panelDomainTlsMode);

        $clear = Artisan::call('azerioid:panel', ['action' => 'domain', 'op' => 'clear']);
        $this->assertSame(0, $clear, Artisan::output());
        $this->assertNull($this->app->make(FakeBroker::class)->panelDomain);
    }

    public function test_panel_domain_set_refuses_existing_vhost(): void
    {
        $code = Artisan::call('azerioid:panel', [
            'action' => 'domain',
            'op' => 'set',
            '--domain' => 'shop.example.com',
        ]);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('already a site vhost', Artisan::output());
    }

    public function test_help_lists_updates_backup_totp(): void
    {
        $code = Artisan::call('azerioid:help');
        $this->assertSame(0, $code);
        $out = Artisan::output();
        $this->assertStringContainsString('azerioid updates check', $out);
        $this->assertStringContainsString('azerioid backup create', $out);
        $this->assertStringContainsString('azerioid totp disable', $out);
        $this->assertStringContainsString('AZERIOID_BACKUP_PASSPHRASE', $out);
        $this->assertStringContainsString('AZERIOID_ADMIN_PASSWORD', $out);
        $this->assertStringContainsString('no CLI', $out);
    }

    public function test_updates_check_json(): void
    {
        $code = Artisan::call('azerioid:updates', ['action' => 'check', '--json' => true]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertNotSame('', $out, 'expected JSON stdout, got empty');
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'output was: '.$out);
        $this->assertSame(12, $decoded['total'] ?? null);
        $this->assertSame('os-packages', $decoded['scope'] ?? null);
    }

    public function test_updates_apply_requires_confirm(): void
    {
        $code = Artisan::call('azerioid:updates', ['action' => 'apply']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('--confirm', Artisan::output());
    }

    public function test_updates_apply_with_confirm_reaches_broker(): void
    {
        $code = Artisan::call('azerioid:updates', [
            'action' => 'apply',
            '--confirm' => true,
            '--json' => true,
        ]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, $out);
        $this->assertSame('updates.apply.all', $decoded['action'] ?? null);
    }

    public function test_backup_create_local_uses_env_passphrase(): void
    {
        putenv('AZERIOID_BACKUP_PASSPHRASE=abcdefghijklmnopqrst');
        try {
            $code = Artisan::call('azerioid:backup', [
                'action' => 'create',
                '--local' => true,
                '--db' => 'all',
                '--json' => true,
            ]);
            $out = Artisan::output();
            $this->assertSame(0, $code, $out);
            $decoded = json_decode($out, true);
            $this->assertIsArray($decoded, $out);
            $this->assertSame('local', $decoded['destination'] ?? null);
            $this->assertStringContainsString('/var/lib/azerioid-panel/backups/', (string) ($decoded['key'] ?? ''));
        } finally {
            putenv('AZERIOID_BACKUP_PASSPHRASE');
        }
    }

    public function test_backup_restore_requires_confirm(): void
    {
        putenv('AZERIOID_BACKUP_PASSPHRASE=abcdefghijklmnopqrst');
        try {
            $code = Artisan::call('azerioid:backup', [
                'action' => 'restore',
                '--local' => true,
                '--file' => '/var/lib/azerioid-panel/backups/db/all/fixture.bin',
                '--target' => 'projob_restore_1',
            ]);
            $this->assertNotSame(0, $code);
            $this->assertStringContainsString('--confirm', Artisan::output());
        } finally {
            putenv('AZERIOID_BACKUP_PASSPHRASE');
        }
    }

    public function test_totp_disable_blocked_when_required(): void
    {
        config(['azerioid.require_totp' => true]);
        $totp = new \App\Services\TotpService();
        $secret = $totp->generateSecret();
        $user = User::factory()->create([
            'email' => 'cli-totp@example.com',
            'password' => 'password',
            'two_factor_secret' => \Illuminate\Support\Facades\Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);
        putenv('AZERIOID_ADMIN_PASSWORD=password');
        putenv('AZERIOID_TOTP_CODE='.(new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($secret));
        try {
            $code = Artisan::call('azerioid:totp', [
                'action' => 'disable',
                '--email' => $user->email,
            ]);
            $this->assertNotSame(0, $code);
            $this->assertStringContainsString('PANEL_REQUIRE_TOTP', Artisan::output());
            $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        } finally {
            putenv('AZERIOID_ADMIN_PASSWORD');
            putenv('AZERIOID_TOTP_CODE');
        }
    }

    public function test_totp_disable_and_reset_via_env_reauth(): void
    {
        config(['azerioid.require_totp' => false]);
        $totp = new \App\Services\TotpService();
        $secret = $totp->generateSecret();
        $user = User::factory()->create([
            'email' => 'cli-totp2@example.com',
            'password' => 'password',
            'two_factor_secret' => \Illuminate\Support\Facades\Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);
        $codeNow = (new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($secret);
        putenv('AZERIOID_ADMIN_PASSWORD=password');
        putenv('AZERIOID_TOTP_CODE='.$codeNow);
        try {
            $code = Artisan::call('azerioid:totp', [
                'action' => 'disable',
                '--email' => $user->email,
            ]);
            $out = Artisan::output();
            $this->assertSame(0, $code, $out);
            $this->assertFalse($user->fresh()->hasTwoFactorEnabled());

            $code = Artisan::call('azerioid:totp', [
                'action' => 'reset',
                '--email' => $user->email,
            ]);
            $out = Artisan::output();
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString('One-time secret', $out);
            $user = $user->fresh();
            $this->assertFalse($user->hasTwoFactorEnabled());
            $this->assertNotNull($user->plainTwoFactorSecret());

            $newSecret = $user->plainTwoFactorSecret();
            putenv('AZERIOID_TOTP_CODE='.(new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($newSecret));
            $code = Artisan::call('azerioid:totp', [
                'action' => 'confirm',
                '--email' => $user->email,
            ]);
            $out = Artisan::output();
            $this->assertSame(0, $code, $out);
            $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        } finally {
            putenv('AZERIOID_ADMIN_PASSWORD');
            putenv('AZERIOID_TOTP_CODE');
        }
    }
}
