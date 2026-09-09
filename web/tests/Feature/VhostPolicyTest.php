<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class VhostPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_readonly_vhosts_cannot_be_deleted_from_the_ui(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('delete', 'projob.az')
            ->assertSet('error', 'This vhost is managed externally and cannot be deleted by the panel.');

        $domains = array_column($fake->vhosts, 'domain');
        $this->assertContains('projob.az', $domains);
    }

    public function test_invalid_domain_is_rejected(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('domain', 'not a domain')
            ->set('root', '/data/www/evil')
            ->set('type', 'php')
            ->set('php_version', '8.4')
            ->call('create')
            ->assertSet('error', 'Invalid domain name.');
    }

    public function test_path_traversal_root_is_rejected(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('domain', 'trav.example.com')
            ->set('root', '/data/www/../etc/passwd')
            ->set('type', 'php')
            ->set('php_version', '8.4')
            ->call('create');
        $this->assertTrue(
            collect($this->app->make(FakeBroker::class)->vhosts)->every(fn ($v) => $v['domain'] !== 'trav.example.com')
        );
    }

    public function test_valid_vhost_can_be_added(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('domain', 'newshop.example.com')
            ->set('root', '/data/www/newshop.example.com')
            ->set('type', 'php')
            ->set('php_version', '8.4')
            ->call('create')
            ->assertSet('error', null);

        $domains = array_column($this->app->make(FakeBroker::class)->vhosts, 'domain');
        $this->assertContains('newshop.example.com', $domains);

        $row = AuditLog::query()->where('action', 'vhost.add')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->ok);
        $this->assertSame('ui', $row->args['origin'] ?? null);
    }

    public function test_vhost_engine_is_stored_on_create_and_edit(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('domain', 'apache-engine.example.com')
            ->set('root', '/data/www/apache-engine.example.com')
            ->set('type', 'php')
            ->set('php_version', '8.4')
            ->set('engine', 'apache')
            ->call('create')
            ->assertSet('error', null)
            ->assertSee('apache');

        $fake = $this->app->make(FakeBroker::class);
        $row = collect($fake->vhosts)->firstWhere('domain', 'apache-engine.example.com');
        $this->assertSame('apache', $row['engine'] ?? null);

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('startEdit', 'apache-engine.example.com')
            ->set('editEngine', 'nginx')
            ->call('saveEdit')
            ->assertSet('error', null);

        $row = collect($this->app->make(FakeBroker::class)->vhosts)->firstWhere('domain', 'apache-engine.example.com');
        $this->assertSame('nginx', $row['engine'] ?? null);
    }

    public function test_failed_caddy_validate_does_not_keep_the_vhost(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);
        $fake->failNextValidate = true;
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('domain', 'bad.example.com')
            ->set('root', '/data/www/bad.example.com')
            ->set('type', 'php')
            ->set('php_version', '8.4')
            ->call('create');
        $this->assertNotContains('bad.example.com', array_column($fake->vhosts, 'domain'));
        $this->assertContains('projob.az', array_column($fake->vhosts, 'domain'));
    }

    public function test_mutations_are_not_get_routes(): void
    {
        $this->actingAs($this->admin());
        $this->get('/vhosts')->assertOk();
        $this->get('/vhosts/delete/projob.az')->assertNotFound();
    }

    public function test_duplicate_create_is_a_clean_already_exists_error(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('domain', 'projob.az')
            ->set('root', '/data/www/projob.az')
            ->set('type', 'php')
            ->set('php_version', '8.4')
            ->call('create')
            ->assertSet('error', 'projob.az is managed externally and can\'t be edited.');
    }

    public function test_non_loopback_proxy_upstream_is_rejected(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('domain', 'proxy.example.com')
            ->set('root', '/data/www/proxy.example.com')
            ->set('type', 'proxy')
            ->set('upstream', '8.8.8.8:443')
            ->call('create')
            ->assertSet('error', 'Upstream must be 127.0.0.1:<port>.');
        $this->assertNotContains('proxy.example.com', array_column($this->app->make(FakeBroker::class)->vhosts, 'domain'));
    }

    public function test_uninstalled_php_version_is_rejected(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('domain', 'shop.example.com')
            ->set('root', '/data/www/shop.example.com')
            ->set('type', 'php')
            ->set('php_version', '9.9')
            ->call('create')
            ->assertSet('error', 'PHP version is not installed.');
    }

    public function test_web_root_outside_www_is_rejected(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('domain', 'outside.example.com')
            ->set('root', '/etc/passwd')
            ->set('type', 'php')
            ->set('php_version', '8.4')
            ->call('create');
        $this->assertNotContains('outside.example.com', array_column($this->app->make(FakeBroker::class)->vhosts, 'domain'));
    }

    public function test_editable_vhost_can_be_updated_from_ui(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);
        // shop.example.com is seeded editable — edit it directly
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('startEdit', 'shop.example.com')
            ->assertSet('editingDomain', 'shop.example.com')
            ->set('editRoot', '/data/www/shop-moved.example.com')
            ->set('editTlsMode', 'auto')
            ->call('saveEdit')
            ->assertSet('error', null)
            ->assertSet('flash', 'Updated shop.example.com.');

        $row = collect($fake->vhosts)->firstWhere('domain', 'shop.example.com');
        $this->assertSame('/data/www/shop-moved.example.com', $row['root']);
        $this->assertTrue($row['tls']);
    }

    public function test_readonly_vhost_cannot_be_edited_from_ui(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('startEdit', 'projob.az')
            ->assertSet('editingDomain', null)
            ->assertSet('error', "projob.az is managed externally and can't be edited.");
    }

    public function test_failed_edit_validate_does_not_mutate_vhost(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('domain', 'shop.example.com')
            ->set('root', '/data/www/shop.example.com')
            ->set('type', 'php')
            ->set('php_version', '8.4')
            ->call('create');

        $fake->failNextValidate = true;
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('startEdit', 'shop.example.com')
            ->set('editRoot', '/data/www/evil-mutate.example.com')
            ->call('saveEdit')
            ->assertSet('error', 'Caddy rejected the edit; the file was rolled back.');

        $row = collect($fake->vhosts)->firstWhere('domain', 'shop.example.com');
        $this->assertSame('/data/www/shop.example.com', $row['root']);
    }

    public function test_sql_injection_db_name_rejected(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\DatabasesPage::class)
            ->set('name', "a'; DROP TABLE users;--")
            ->call('create');
        $names = array_column($this->app->make(FakeBroker::class)->databases, 'name');
        $this->assertNotContains("a'; DROP TABLE users;--", $names);
    }

    public function test_failed_db_create_does_not_reveal_password(): void
    {
        $this->actingAs($this->admin());
        $this->app->make(FakeBroker::class)->failNextDbAdd = true;
        Livewire::test(\App\Livewire\DatabasesPage::class)
            ->set('name', 'shopdb')
            ->set('user', 'shopuser')
            ->call('create')
            ->assertSet('revealedPassword', null)
            ->assertSet('error', 'Database already exists.');
        $this->assertNotContains('shopdb', array_column($this->app->make(FakeBroker::class)->databases, 'name'));
    }

    public function test_successful_db_create_reveals_password_once(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\DatabasesPage::class)
            ->set('name', 'shopdb')
            ->set('user', 'shopuser')
            ->call('create')
            ->assertSet('error', null)
            ->assertNotSet('revealedPassword', null);
        $this->assertContains('shopdb', array_column($this->app->make(FakeBroker::class)->databases, 'name'));
    }

    public function test_database_access_rejects_injected_ip_and_requires_global_confirm(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);

        Livewire::test(\App\Livewire\DatabasesPage::class)
            ->call('startAccess', 'projob')
            ->set('accessMode', 'specific')
            ->set('accessIpsText', '1.2.3.4; DROP TABLE x')
            ->call('requestAccessSave')
            ->assertSet('error', 'Invalid IP or CIDR.');
        $this->assertArrayNotHasKey('projob', $fake->dbAccess['mariadb'] ?? []);

        Livewire::test(\App\Livewire\DatabasesPage::class)
            ->call('startAccess', 'projob')
            ->set('accessMode', 'global')
            ->call('requestAccessSave')
            ->assertSet('accessShowGlobalModal', true)
            ->call('confirmGlobalAccess')
            ->assertSet('error', 'Type the database name and confirm you understand the risk before enabling Global access.');
        $this->assertArrayNotHasKey('projob', $fake->dbAccess['mariadb'] ?? []);

        Livewire::test(\App\Livewire\DatabasesPage::class)
            ->call('startAccess', 'projob')
            ->set('accessMode', 'global')
            ->call('requestAccessSave')
            ->set('accessUnderstand', true)
            ->set('accessConfirmName', 'projob')
            ->call('confirmGlobalAccess')
            ->assertSet('error', null)
            ->assertSet('accessName', null);
        $this->assertSame('global', $fake->dbAccess['mariadb']['projob']['mode'] ?? null);
    }

    public function test_database_access_specific_ip_and_mongo_caveat(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);

        Livewire::test(\App\Livewire\DatabasesPage::class)
            ->call('startAccess', 'projob')
            ->set('accessMode', 'specific')
            ->set('accessIpsText', '203.0.113.5')
            ->call('requestAccessSave')
            ->assertSet('error', null);
        $this->assertSame(['203.0.113.5'], $fake->dbAccess['mariadb']['projob']['ips'] ?? null);

        $fake->mongodbConfigured = true;
        Livewire::test(\App\Livewire\DatabasesPage::class)
            ->set('selectedEngine', 'mongodb')
            ->assertSee('no per-database host authentication', false)
            ->call('startAccess', 'shop')
            ->set('accessMode', 'specific')
            ->set('accessIpsText', '203.0.113.5')
            ->call('requestAccessSave')
            ->assertSet('error', null);
        $this->assertSame('specific', $fake->dbAccess['mongodb']['shop']['mode'] ?? null);
        $this->assertSame(['203.0.113.5'], $fake->dbAccess['mongodb']['shop']['ips'] ?? null);
    }

    public function test_specific_ip_input_renders_when_mode_selected(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);

        Livewire::test(\App\Livewire\DatabasesPage::class)
            ->call('startAccess', 'projob')
            ->assertDontSee('Allowed IPs or CIDRs')
            ->set('accessMode', 'specific')
            ->assertSee('Allowed IPs or CIDRs')
            ->assertSee('203.0.113.5, 198.51.100.0/24')
            ->set('accessIpsText', '203.0.113.5')
            ->call('requestAccessSave')
            ->assertSet('error', null)
            ->call('startAccess', 'projob')
            ->assertSet('accessMode', 'specific')
            ->assertSet('accessIpsText', '203.0.113.5')
            ->assertSee('Allowed IPs or CIDRs');
        $this->assertSame(['203.0.113.5'], $fake->dbAccess['mariadb']['projob']['ips'] ?? null);

        $fake->postgresqlConfigured = true;
        Livewire::test(\App\Livewire\DatabasesPage::class)
            ->set('selectedEngine', 'postgresql')
            ->call('startAccess', 'projob')
            ->set('accessMode', 'specific')
            ->assertSee('Allowed IPs or CIDRs')
            ->set('accessMode', 'global')
            ->assertDontSee('Allowed IPs or CIDRs');
    }

    public function test_access_form_prefills_saved_mode_and_ips_across_engines(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);
        $fake->postgresqlConfigured = true;
        $fake->mongodbConfigured = true;

        foreach (['mariadb' => 'projob', 'postgresql' => 'projob', 'mongodb' => 'shop'] as $engine => $name) {
            $fake->dbAccess[$engine][$name] = ['mode' => 'specific', 'ips' => ['198.51.100.10', '203.0.113.5']];
            $html = Livewire::test(\App\Livewire\DatabasesPage::class)
                ->set('selectedEngine', $engine)
                ->call('startAccess', $name)
                ->assertSet('accessMode', 'specific')
                ->assertSet('accessIpsText', '198.51.100.10, 203.0.113.5')
                ->assertSee('Allowed IPs or CIDRs')
                ->html();
            $this->assertStringContainsString('value="198.51.100.10, 203.0.113.5"', $html);
            $this->assertStringContainsString('placeholder="IP or CIDR, comma-separated"', $html);

            Livewire::test(\App\Livewire\DatabasesPage::class)
                ->set('selectedEngine', $engine)
                ->call('startAccess', $name)
                ->set('accessIpsText', '198.51.100.10')
                ->call('requestAccessSave')
                ->assertSet('error', null)
                ->call('startAccess', $name)
                ->assertSet('accessMode', 'specific')
                ->assertSet('accessIpsText', '198.51.100.10');
            $this->assertSame(['198.51.100.10'], $fake->dbAccess[$engine][$name]['ips'] ?? null);

            $fake->dbAccess[$engine][$name] = ['mode' => 'global', 'ips' => []];
            Livewire::test(\App\Livewire\DatabasesPage::class)
                ->set('selectedEngine', $engine)
                ->call('startAccess', $name)
                ->assertSet('accessMode', 'global')
                ->assertSet('accessIpsText', '')
                ->assertDontSee('Allowed IPs or CIDRs');

            $fake->dbAccess[$engine][$name] = ['mode' => 'localhost', 'ips' => ['198.51.100.10']];
            Livewire::test(\App\Livewire\DatabasesPage::class)
                ->set('selectedEngine', $engine)
                ->call('startAccess', $name)
                ->assertSet('accessMode', 'localhost')
                ->assertSet('accessIpsText', '')
                ->assertDontSee('Allowed IPs or CIDRs');
        }

        unset($fake->dbAccess['mariadb']['projob']);
        $html = Livewire::test(\App\Livewire\DatabasesPage::class)
            ->call('startAccess', 'projob')
            ->assertSet('accessMode', 'localhost')
            ->assertSet('accessIpsText', '')
            ->set('accessMode', 'specific')
            ->assertSet('accessIpsText', '')
            ->html();
        $this->assertStringContainsString('placeholder="IP or CIDR, comma-separated"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*aria-label="Allowed IPs or CIDRs"[^>]*value="[^"]+"/',
            $html
        );
    }

    public function test_mongodb_create_reveals_password_and_lists_database(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);
        $fake->mongodbConfigured = true;

        Livewire::test(\App\Livewire\DatabasesPage::class)
            ->set('selectedEngine', 'mongodb')
            ->assertSee('Add database')
            ->assertSee('no per-database host authentication', false)
            ->set('name', 'appdata')
            ->set('user', 'appuser')
            ->call('create')
            ->assertSet('error', null)
            ->assertNotSet('revealedPassword', null)
            ->assertSee('appdata')
            ->assertSee('no per-database host authentication', false);

        $this->assertContains('appdata', array_column($fake->mongoDatabases, 'name'));
        $this->assertNotContains('appdata', array_column($fake->databases, 'name'));
    }

    private function admin(): User
    {
        $totp = new TotpService();
        $secret = $totp->generateSecret();
        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
