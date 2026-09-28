<?php

namespace Tests\Feature;

use App\Livewire\SearchPage;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

/** B7 / A54: the Search page and CLI. */
class SearchPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString((new TotpService())->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_not_installed_explains_the_requirement(): void
    {
        Livewire::actingAs($this->admin())->test(SearchPage::class)
            ->assertSet('installed', false)
            ->assertSet('error', null)
            ->assertSee('2 GB of physical RAM');
    }

    public function test_health_indices_and_a_password_shown_once(): void
    {
        app(FakeBroker::class)->fakeInstalledComponents['elasticsearch'] = true;

        Livewire::actingAs($this->admin())->test(SearchPage::class)
            ->assertSet('installed', true)
            ->assertSee('products')
            ->assertSet('password', null)
            ->call('resetPassword')
            ->assertSet('password', 'fakePassw0rd')
            ->assertSee('Shown once');
    }

    public function test_cli_status(): void
    {
        app(FakeBroker::class)->fakeInstalledComponents['elasticsearch'] = true;

        $this->assertSame(0, Artisan::call('azerioid:search', ['action' => 'status']));
        $this->assertStringContainsString('green', Artisan::output());
    }
}
