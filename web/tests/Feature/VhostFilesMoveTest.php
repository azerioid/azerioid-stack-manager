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
 * B1 / G11 — `vhost.files.move` existed in the broker since the File Manager
 * shipped, registered in the Kernel and validating both source and destination
 * through VhostPath, but was never wired to the UI. Files could be renamed in
 * place and never relocated.
 *
 * Asserts the observable effect (where the file ends up) rather than call
 * bookkeeping, so the tests would catch a move that is dispatched but wrong.
 */
class VhostFilesMoveTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'shop.example.com';

    private const ROOT = '/data/www/shop.example.com';

    private function admin(): User
    {
        $totp = new TotpService();

        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($totp->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** @param list<string> $files */
    private function seedFiles(array $files, array $dirs = []): void
    {
        $fake = app(FakeBroker::class);
        $tree = ['' => ['type' => 'dir', 'mtime' => time()]];
        foreach ($dirs as $d) {
            $tree[$d] = ['type' => 'dir', 'mtime' => time()];
        }
        foreach ($files as $f) {
            $tree[$f] = ['type' => 'file', 'size' => 12, 'mtime' => time(), 'content' => 'hello world!'];
        }
        $fake->vhostFiles[self::DOMAIN] = $tree;
    }

    /** @return array<string,mixed> */
    private function tree(): array
    {
        return app(FakeBroker::class)->vhostFiles[self::DOMAIN] ?? [];
    }

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->admin())
            ->test(VhostFilesPage::class, ['domain' => self::DOMAIN]);
    }

    // --------------------------------------------------------------- happy path

    public function test_move_relocates_the_file_into_another_directory(): void
    {
        $this->seedFiles(['index.php'], ['public', 'public/assets']);

        $this->page()
            ->call('startMove', 'index.php')
            ->set('moveTo', 'public/assets')
            ->call('move');

        $tree = $this->tree();
        $this->assertArrayHasKey('public/assets/index.php', $tree, 'the file must land in the target directory');
        $this->assertArrayNotHasKey('index.php', $tree, 'and must no longer sit at the old path');
    }

    /** Move changes the directory and keeps the name — that is the whole point. */
    public function test_move_keeps_the_original_filename(): void
    {
        $this->seedFiles(['a/b/report.pdf'], ['a', 'a/b', 'archive']);

        $this->page()
            ->call('startMove', 'a/b/report.pdf')
            ->set('moveTo', 'archive')
            ->call('move');

        $this->assertArrayHasKey('archive/report.pdf', $this->tree());
    }

    public function test_empty_destination_means_the_document_root(): void
    {
        $this->seedFiles(['deep/nested/file.txt'], ['deep', 'deep/nested']);

        $this->page()
            ->call('startMove', 'deep/nested/file.txt')
            ->set('moveTo', '')
            ->call('move');

        $this->assertArrayHasKey('file.txt', $this->tree());
    }

    public function test_surrounding_slashes_are_tolerated(): void
    {
        $this->seedFiles(['file.txt'], ['public']);

        $this->page()
            ->call('startMove', 'file.txt')
            ->set('moveTo', '/public/')
            ->call('move');

        $this->assertArrayHasKey('public/file.txt', $this->tree());
    }

    // ------------------------------------------------------------- refusals

    public function test_moving_a_file_onto_itself_is_refused(): void
    {
        $this->seedFiles(['index.php']);

        $this->page()
            ->call('startMove', 'index.php')
            ->set('moveTo', '')
            ->call('move')
            ->assertSet('moveFrom', 'index.php');

        $this->assertArrayHasKey('index.php', $this->tree(), 'the file must be untouched');
    }

    /**
     * Containment is the broker's job (VhostPath), not the UI's — but a climbing
     * path must not succeed either way.
     */
    public function test_a_traversing_destination_does_not_move_the_file(): void
    {
        $this->seedFiles(['index.php']);

        $this->page()
            ->call('startMove', 'index.php')
            ->set('moveTo', '../../etc')
            ->call('move');

        $this->assertArrayHasKey('index.php', $this->tree(), 'the file must stay where it was');
        $this->assertArrayNotHasKey('../../etc/index.php', $this->tree());
    }

    // ------------------------------------------------------------ dialog state

    public function test_cancel_clears_the_dialog(): void
    {
        $this->seedFiles(['index.php']);

        $this->page()
            ->call('startMove', 'index.php')
            ->assertSet('moveFrom', 'index.php')
            ->call('cancelMove')
            ->assertSet('moveFrom', null)
            ->assertSet('moveTo', '');

        $this->assertArrayHasKey('index.php', $this->tree());
    }

    public function test_start_move_defaults_to_the_current_directory(): void
    {
        $this->seedFiles(['public/a.txt'], ['public']);

        $this->page()
            ->call('openDir', 'public')
            ->call('startMove', 'public/a.txt')
            ->assertSet('moveTo', 'public');
    }

    public function test_move_without_starting_is_a_noop(): void
    {
        $this->seedFiles(['index.php']);

        $this->page()->call('move');

        $this->assertArrayHasKey('index.php', $this->tree());
    }
}
