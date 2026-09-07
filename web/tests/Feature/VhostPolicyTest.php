<?php

namespace Tests\Feature;

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
            ->assertSet('error', 'This vhost cannot be edited.');
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
