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
 * B3 / request #10 — copy in the File Manager.
 *
 * Asserts where the file ends up rather than which call was made, so a copy that is
 * dispatched but wrong would still fail. The broker tests cover containment; these cover
 * the one behaviour that is the page's own decision — what happens when the destination
 * folder is the one the file is already in.
 */
class VhostFilesCopyTest extends TestCase
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

    public function test_copy_duplicates_the_file_into_another_directory(): void
    {
        $this->seedFiles(['index.php'], ['public']);

        $this->page()->call('startCopy', 'index.php')->set('copyTo', 'public')->call('copy');

        $tree = $this->tree();
        $this->assertArrayHasKey('public/index.php', $tree);
        $this->assertArrayHasKey('index.php', $tree, 'the original must still be there');
    }

    /**
     * The common reason to copy a file is to keep the original and edit the duplicate, so
     * the same folder is allowed — unlike move, which refuses it as a no-op.
     */
    public function test_copying_into_the_same_folder_gets_a_suffix(): void
    {
        $this->seedFiles(['public/app.css'], ['public']);

        $this->page()->call('startCopy', 'public/app.css')->set('copyTo', 'public')->call('copy');

        $this->assertArrayHasKey('public/app-copy.css', $this->tree());
        $this->assertArrayHasKey('public/app.css', $this->tree());
    }

    /** A copy that stops being a stylesheet is a surprise. */
    public function test_the_suffix_goes_before_the_extension(): void
    {
        $this->seedFiles(['app.blade.php']);

        $this->page()->call('startCopy', 'app.blade.php')->set('copyTo', '')->call('copy');

        $this->assertArrayHasKey('app.blade-copy.php', $this->tree());
    }

    public function test_a_file_with_no_extension_still_gets_a_suffix(): void
    {
        $this->seedFiles(['Makefile']);

        $this->page()->call('startCopy', 'Makefile')->set('copyTo', '')->call('copy');

        $this->assertArrayHasKey('Makefile-copy', $this->tree());
    }

    public function test_copying_a_directory_is_refused(): void
    {
        $this->seedFiles(['public/a.txt'], ['public']);

        $this->page()->call('startCopy', 'public')->set('copyTo', '')->call('copy');

        $this->assertArrayNotHasKey('public-copy', $this->tree());
    }

    public function test_an_existing_destination_is_not_overwritten(): void
    {
        $this->seedFiles(['index.php', 'public/index.php'], ['public']);
        $before = $this->tree()['public/index.php'];

        $this->page()->call('startCopy', 'index.php')->set('copyTo', 'public')->call('copy');

        $this->assertSame($before, $this->tree()['public/index.php']);
    }

    public function test_a_traversing_destination_does_not_copy_the_file(): void
    {
        $this->seedFiles(['index.php']);

        $this->page()->call('startCopy', 'index.php')->set('copyTo', '../../etc')->call('copy');

        $this->assertArrayNotHasKey('../../etc/index.php', $this->tree());
    }

    public function test_cancel_clears_the_dialog(): void
    {
        $this->seedFiles(['index.php']);

        $this->page()
            ->call('startCopy', 'index.php')
            ->assertSet('copyFrom', 'index.php')
            ->call('cancelCopy')
            ->assertSet('copyFrom', null)
            ->assertSet('copyTo', '');
    }

    public function test_copy_without_starting_is_a_noop(): void
    {
        $this->seedFiles(['index.php']);

        $this->page()->call('copy');

        $this->assertSame(['', 'index.php'], array_keys($this->tree()));
    }
}
