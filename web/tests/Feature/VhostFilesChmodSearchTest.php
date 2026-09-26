<?php

namespace Tests\Feature;

use App\Livewire\VhostFilesPage;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B3 / request #10 — permissions and search in the File Manager.
 *
 * The broker tests cover the refusals; these cover what the page does with them, and the one
 * decision that is the page's own: presets only, no free-text mode field.
 */
class VhostFilesChmodSearchTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'shop.example.com';

    private function admin(): User
    {
        $totp = new TotpService();

        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($totp->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function seedTree(): void
    {
        app(FakeBroker::class)->vhostFiles[self::DOMAIN] = [
            '' => ['type' => 'dir', 'mtime' => time()],
            'index.php' => ['type' => 'file', 'size' => 12, 'mtime' => time(), 'content' => "<?php // entry\n"],
            'public' => ['type' => 'dir', 'mtime' => time()],
            'public/app.css' => ['type' => 'file', 'size' => 9, 'mtime' => time(), 'content' => "body{color:red}\n"],
            'storage' => ['type' => 'dir', 'mtime' => time()],
            'storage/laravel.log' => ['type' => 'file', 'size' => 7, 'mtime' => time(), 'content' => "production.ERROR: boom\n"],
        ];
    }

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        $this->seedTree();

        return Livewire::actingAs($this->admin())->test(VhostFilesPage::class, ['domain' => self::DOMAIN]);
    }

    private function modeOf(string $rel): ?string
    {
        return app(FakeBroker::class)->vhostFiles[self::DOMAIN][$rel]['mode'] ?? null;
    }

    // ------------------------------------------------------------------ chmod

    public function test_the_default_preset_differs_for_a_file_and_a_directory(): void
    {
        $this->page()->call('startChmod', 'index.php', false)->call('applyChmod', 'default');
        $this->assertSame('0644', $this->modeOf('index.php'));

        $this->page()->call('startChmod', 'public', true)->call('applyChmod', 'default');
        $this->assertSame('0755', $this->modeOf('public'));
    }

    public function test_private_and_executable_presets_apply(): void
    {
        $this->page()->call('startChmod', 'index.php', false)->call('applyChmod', 'private');
        $this->assertSame('0600', $this->modeOf('index.php'));

        $this->page()->call('startChmod', 'index.php', false)->call('applyChmod', 'executable');
        $this->assertSame('0755', $this->modeOf('index.php'));
    }

    /** The dialog offers three named choices and no place to type a number. */
    public function test_the_dialog_offers_presets_and_no_free_text_mode(): void
    {
        $page = $this->page()->call('startChmod', 'index.php', false);

        $page->assertSee('Default (644)')->assertSee('Private (600)')->assertSee('Executable (755)');
        $this->assertStringNotContainsString('wire:model="chmodMode"', $page->html());
    }

    public function test_a_directory_is_not_offered_the_executable_preset(): void
    {
        $this->page()->call('startChmod', 'public', true)->assertDontSee('Executable (755)');
    }

    /** A mode the broker refuses must not appear to have been applied. */
    public function test_a_refused_mode_leaves_the_file_alone(): void
    {
        $this->page()->call('startChmod', 'index.php', false)->call('applyChmod', '777');

        $this->assertNull($this->modeOf('index.php'));
    }

    public function test_chmod_without_choosing_a_target_is_a_noop(): void
    {
        $this->page()->call('applyChmod', 'private');

        $this->assertNull($this->modeOf('index.php'));
    }

    public function test_cancel_closes_the_dialog(): void
    {
        $this->page()
            ->call('startChmod', 'index.php', false)
            ->assertSet('chmodTarget', 'index.php')
            ->call('cancelChmod')
            ->assertSet('chmodTarget', null);
    }

    // ----------------------------------------------------------------- search

    public function test_a_name_search_shows_matches_instead_of_the_listing(): void
    {
        $this->page()->set('searchQuery', 'app')->call('search')->assertSee('public/app.css');
    }

    public function test_a_content_search_finds_the_file(): void
    {
        $this->page()
            ->set('searchContains', 'production.ERROR')
            ->call('search')
            ->assertSee('storage/laravel.log');
    }

    public function test_nothing_matching_says_so(): void
    {
        $this->page()->set('searchQuery', 'zzzz')->call('search')->assertSee('Nothing matched');
    }

    public function test_clearing_returns_to_the_listing(): void
    {
        $this->page()
            ->set('searchQuery', 'app')
            ->call('search')
            ->assertSet('searchResult', fn ($r) => is_array($r))
            ->call('clearSearch')
            ->assertSet('searchResult', null)
            ->assertSet('searchQuery', '');
    }

    public function test_an_empty_search_just_clears(): void
    {
        $this->page()
            ->set('searchQuery', '')
            ->set('searchContains', '')
            ->call('search')
            ->assertSet('searchResult', null)
            ->assertSet('error', null);
    }
}
