<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

final class VhostListUxTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $totp = new TotpService();

        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($totp->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_search_and_filters_narrow_the_list(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);
        $fake->fakeInstalledComponents['docker'] = true;
        $fake->fakeInstalledComponents['supervisor'] = true;
        $fake->handle('vhost.docker.enable', ['node.example.com'], [
            'mode' => 'image',
            'image' => 'nginx:alpine',
            'internal_port' => 80,
        ]);

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('listSearch', 'node.example')
            ->assertSee('node.example.com')
            ->assertDontSee('shop.example.com')
            ->set('listSearch', '')
            ->set('filterRuntime', 'docker')
            ->assertSee('node.example.com')
            ->assertDontSee('shop.example.com')
            ->call('clearListFilters')
            ->assertSee('shop.example.com');
    }

    public function test_action_menu_scopes_items_per_runtime_and_readonly(): void
    {
        $this->actingAs($this->admin());
        $page = Livewire::test(\App\Livewire\VhostsPage::class)->instance();

        $php = collect($page->vhosts)->firstWhere('domain', 'shop.example.com');
        $this->assertIsArray($php);
        $labels = $this->labels($page->vhostActionGroups($php));
        $this->assertContains('Files', $labels);
        $this->assertContains('Terminal', $labels);
        $this->assertContains('Edit', $labels);
        $this->assertContains('Delete', $labels);
        $this->assertNotContains('Container shell', $labels);
        $this->assertNotContains('Reload application', $labels);

        $readonly = collect($page->vhosts)->firstWhere('domain', 'projob.az');
        $this->assertIsArray($readonly);
        $roLabels = $this->labels($page->vhostActionGroups($readonly));
        $this->assertNotContains('Delete', $roLabels);
        $this->assertNotContains('Edit', $roLabels);
        $this->assertNotContains('Files', $roLabels);
    }

    public function test_docker_runtime_exposes_container_and_runtime_actions(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);
        $fake->fakeInstalledComponents['docker'] = true;
        $fake->fakeInstalledComponents['supervisor'] = true;
        $fake->handle('vhost.docker.enable', ['node.example.com'], [
            'mode' => 'image',
            'image' => 'nginx:alpine',
            'internal_port' => 80,
        ]);

        $page = Livewire::test(\App\Livewire\VhostsPage::class)->instance();
        $row = collect($page->vhosts)->firstWhere('domain', 'node.example.com');
        $this->assertIsArray($row);
        $labels = $this->labels($page->vhostActionGroups($row));
        $this->assertContains('Container shell', $labels);
        $this->assertContains('Container logs', $labels);
        $this->assertContains('Rebuild', $labels);
        $this->assertContains('Restart', $labels);
        $this->assertContains('Switch off Docker', $labels);
        $this->assertNotContains('Enable Docker', $labels);
        $this->assertNotContains('Enable PM2', $labels);
    }

    public function test_failed_tls_maps_to_failed_status_badge_separate_from_tls(): void
    {
        $this->actingAs($this->admin());
        $page = Livewire::test(\App\Livewire\VhostsPage::class)->instance();
        $status = $page->vhostStatus([
            'domain' => 'raww.az',
            'tls_status' => [
                'failed' => true,
                'pending' => false,
                'ok' => false,
                'error' => 'Failed — domain does not resolve',
                'label' => 'failed · Failed — domain does not resolve',
            ],
        ]);
        $this->assertSame('failed', $status['state']);
        $this->assertSame('domain does not resolve', $status['detail']);

        $tls = $page->vhostTlsDisplay([
            'tls' => true,
            'tls_mode' => 'auto',
            'tls_status' => [
                'enabled' => true,
                'issuer_type' => 'failed',
                'failed' => true,
                'ok' => false,
                'error' => 'Failed — domain does not resolve',
                'label' => 'failed · Failed — domain does not resolve',
            ],
        ]);
        $this->assertSame('AUTO', $tls['primary']);
        $this->assertNull($tls['secondary']);
    }

    public function test_menu_actions_still_invoke_existing_livewire_methods(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('askDelete', 'shop.example.com')
            ->assertSet('confirmDelete', 'shop.example.com')
            ->call('startEdit', 'shop.example.com')
            ->assertSet('editingDomain', 'shop.example.com');
    }

    public function test_vhosts_page_renders_menu_trigger_not_inline_action_links(): void
    {
        $this->actingAs($this->admin());
        $html = $this->get('/vhosts')->assertOk()->getContent();
        $this->assertStringContainsString('aria-haspopup="menu"', $html);
        $this->assertStringContainsString('role="menu"', $html);
        $this->assertStringContainsString('role="menuitem"', $html);
        $this->assertStringNotContainsString('class="px-4 py-3 text-right space-x-3"', $html);
        $this->assertStringContainsString('Healthy', $html);
        $this->assertStringContainsString('Filter by domain', $html);
    }

    /** @param list<array{items?: list<array{label?: string}>}> $groups */
    private function labels(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            foreach ($group['items'] ?? [] as $item) {
                if (! empty($item['label'])) {
                    $out[] = $item['label'];
                }
            }
        }

        return $out;
    }
}
