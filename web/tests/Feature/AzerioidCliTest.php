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
}
