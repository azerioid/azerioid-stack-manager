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
 * A40 — extract in the File Manager.
 *
 * The adversarial suite lives in the broker; these tests check the page cannot get round it and
 * that a refusal reaches the operator in words. `FakeBroker` runs real archives through the real
 * ZipExtractGuard, so a hostile archive is refused here for the same reason it would be on a host.
 */
class VhostFilesExtractTest extends TestCase
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

    /** @param array<string,string> $entries */
    private function zipBytes(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'aztest');
        $zip = new \ZipArchive();
        $zip->open((string) $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents((string) $path);
        @unlink((string) $path);

        return $bytes;
    }

    /** @param array<string,string> $entries */
    private function seedArchive(array $entries, array $extra = []): void
    {
        $bytes = $this->zipBytes($entries);
        $tree = [
            '' => ['type' => 'dir', 'mtime' => time()],
            'uploads' => ['type' => 'dir', 'mtime' => time()],
            'uploads/site.zip' => [
                'type' => 'file', 'size' => strlen($bytes), 'mtime' => time(), 'content' => $bytes,
            ],
        ];
        foreach ($extra as $rel => $node) {
            $tree[$rel] = $node;
        }
        app(FakeBroker::class)->vhostFiles[self::DOMAIN] = $tree;
    }

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->admin())
            ->test(VhostFilesPage::class, ['domain' => self::DOMAIN]);
    }

    /** @return array<string,mixed> */
    private function tree(): array
    {
        return app(FakeBroker::class)->vhostFiles[self::DOMAIN] ?? [];
    }

    public function test_an_archive_extracts_beside_itself(): void
    {
        $this->seedArchive(['index.php' => "<?php\n", 'css/app.css' => "body{}\n"]);

        $this->page()
            ->call('startExtract', 'uploads/site.zip')
            ->call('extract')
            ->assertSet('error', null);

        $this->assertArrayHasKey('uploads/index.php', $this->tree());
        $this->assertArrayHasKey('uploads/css/app.css', $this->tree());
    }

    public function test_the_flash_says_how_much_was_extracted_and_where(): void
    {
        $this->seedArchive(['a.txt' => 'a', 'b.txt' => 'b']);

        $this->page()
            ->call('startExtract', 'uploads/site.zip')
            ->call('extract')
            ->assertSet('flash', 'Extracted 2 entries into uploads.');
    }

    public function test_a_destination_can_be_chosen(): void
    {
        $this->seedArchive(['a.txt' => 'a'], ['public' => ['type' => 'dir', 'mtime' => time()]]);

        $this->page()
            ->call('startExtract', 'uploads/site.zip')
            ->set('extractTo', 'public')
            ->call('extract');

        $this->assertArrayHasKey('public/a.txt', $this->tree());
    }

    /** The guard's refusal, not the page's — and it must reach the operator in words. */
    public function test_a_traversing_archive_is_refused_and_writes_nothing(): void
    {
        $this->seedArchive(['../../escaped.php' => 'x']);

        $page = $this->page()->call('startExtract', 'uploads/site.zip')->call('extract');

        $page->assertSet('flash', null);
        $this->assertNotNull($page->get('error'));
        $this->assertArrayNotHasKey('../../escaped.php', $this->tree());
    }

    public function test_an_existing_file_is_not_replaced(): void
    {
        $this->seedArchive(['index.php' => "REPLACED\n"], [
            'uploads/index.php' => ['type' => 'file', 'size' => 9, 'mtime' => time(), 'content' => "ORIGINAL\n"],
        ]);

        $this->page()->call('startExtract', 'uploads/site.zip')->call('extract');

        $this->assertSame("ORIGINAL\n", $this->tree()['uploads/index.php']['content']);
    }

    public function test_a_file_that_is_not_an_archive_is_refused(): void
    {
        app(FakeBroker::class)->vhostFiles[self::DOMAIN] = [
            '' => ['type' => 'dir', 'mtime' => time()],
            'notes.zip' => ['type' => 'file', 'size' => 5, 'mtime' => time(), 'content' => "plain\n"],
        ];

        $page = $this->page()->call('startExtract', 'notes.zip')->call('extract');

        $this->assertNotNull($page->get('error'));
    }

    public function test_the_dialog_warns_that_nothing_is_overwritten(): void
    {
        $this->seedArchive(['a.txt' => 'a']);

        $this->page()
            ->call('startExtract', 'uploads/site.zip')
            ->assertSee('Existing files are never replaced');
    }

    public function test_cancel_clears_the_dialog(): void
    {
        $this->seedArchive(['a.txt' => 'a']);

        $this->page()
            ->call('startExtract', 'uploads/site.zip')
            ->assertSet('extractTarget', 'uploads/site.zip')
            ->call('cancelExtract')
            ->assertSet('extractTarget', null)
            ->assertSet('extractTo', '');
    }

    public function test_extract_without_choosing_a_target_is_a_noop(): void
    {
        $this->seedArchive(['a.txt' => 'a']);

        $this->page()->call('extract');

        $this->assertArrayNotHasKey('uploads/a.txt', $this->tree());
    }
}
