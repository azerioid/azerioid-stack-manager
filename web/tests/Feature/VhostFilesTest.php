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

class VhostFilesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $totp = new TotpService();
        $secret = $totp->generateSecret();

        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_files_link_only_on_eligible_vhosts(): void
    {
        $this->actingAs($this->admin());
        $html = $this->get('/vhosts')->assertOk()->getContent();
        $this->assertStringContainsString('/vhosts/shop.example.com/files', $html);
        $this->assertStringNotContainsString('/vhosts/projob.az/files', $html);
        $this->assertStringNotContainsString('/vhosts/default/files', $html);
    }

    public function test_files_page_lists_shop_and_rejects_readonly(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\VhostFilesPage::class, ['domain' => 'shop.example.com'])
            ->assertSet('error', null)
            ->assertSee('index.php')
            ->assertSee('az-vh-shop-example-com')
            ->assertSee('/data/www/shop.example.com');

        Livewire::test(\App\Livewire\VhostFilesPage::class, ['domain' => 'projob.az'])
            ->assertSet('error', 'File manager is not available for read-only or system vhosts.');
    }

    public function test_happy_path_write_rename_delete_and_audit(): void
    {
        $this->actingAs($this->admin());
        $lw = Livewire::test(\App\Livewire\VhostFilesPage::class, ['domain' => 'shop.example.com']);
        $lw->call('startEdit', 'public/hello.txt')
            ->set('editorContent', "edited\n")
            ->call('saveEdit')
            ->assertSet('error', null);

        $fake = $this->app->make(FakeBroker::class);
        $this->assertSame("edited\n", $fake->vhostFiles['shop.example.com']['public/hello.txt']['content']);

        $lw->call('startRename', 'public/hello.txt')
            ->set('renameTo', 'hola.txt')
            ->call('rename')
            ->assertSet('error', null);
        $this->assertArrayHasKey('public/hola.txt', $fake->vhostFiles['shop.example.com']);
        $this->assertArrayNotHasKey('public/hello.txt', $fake->vhostFiles['shop.example.com']);

        $lw->call('askDelete', 'public/hola.txt', false)
            ->call('delete')
            ->assertSet('error', null);
        $this->assertArrayNotHasKey('public/hola.txt', $fake->vhostFiles['shop.example.com']);

        $write = AuditLog::query()->where('action', 'vhost.files.write')->latest('id')->first();
        $this->assertNotNull($write);
        $this->assertTrue($write->ok);
        $this->assertSame('public/hello.txt', $write->args['path'] ?? null);
        $this->assertSame('[redacted]', $write->args['content_base64'] ?? null);

        $del = AuditLog::query()->where('action', 'vhost.files.delete')->latest('id')->first();
        $this->assertNotNull($del);
        $this->assertSame('public/hola.txt', $del->args['path'] ?? null);
    }

    public function test_traversal_via_ui_and_direct_broker_is_rejected(): void
    {
        $this->actingAs($this->admin());
        $lw = Livewire::test(\App\Livewire\VhostFilesPage::class, ['domain' => 'shop.example.com']);
        $lw->call('startEdit', '../../../etc/passwd');
        $this->assertNotNull($lw->get('error'));
        $this->assertStringContainsString('outside', strtolower((string) $lw->get('error')));

        $fake = $this->app->make(FakeBroker::class);
        $res = $fake->handle('vhost.files.read', ['shop.example.com'], [
            'path' => '../../another-vhost/index.php',
        ]);
        $this->assertFalse($res->ok);
        $this->assertStringContainsString('outside', strtolower((string) $res->error));

        $write = $fake->handle('vhost.files.write', ['shop.example.com'], [
            'path' => '../../../etc/cron.d/x',
            'content_base64' => base64_encode('pwn'),
        ]);
        $this->assertFalse($write->ok);
    }

    public function test_symlink_escape_via_file_manager_is_rejected(): void
    {
        $fake = $this->app->make(FakeBroker::class);
        $fake->vhostFiles['shop.example.com']['etc-link'] = [
            'type' => 'symlink',
            'target' => '/etc/passwd',
            'mtime' => time(),
        ];
        $res = $fake->handle('vhost.files.read', ['shop.example.com'], ['path' => 'etc-link']);
        $this->assertFalse($res->ok);
        $this->assertStringContainsString('outside', strtolower((string) $res->error));

        $list = $fake->handle('vhost.files.list', ['shop.example.com'], ['path' => '']);
        $this->assertTrue($list->ok);
        $escaped = collect($list->data['entries'])->firstWhere('name', 'etc-link');
        $this->assertTrue((bool) ($escaped['escaped'] ?? false));
    }

    public function test_rename_dest_outside_is_rejected(): void
    {
        $fake = $this->app->make(FakeBroker::class);
        $res = $fake->handle('vhost.files.rename', ['shop.example.com'], [
            'path' => 'index.php',
            'dest' => '../../../tmp/stolen.php',
        ]);
        $this->assertFalse($res->ok);
        $this->assertArrayHasKey('index.php', $fake->vhostFiles['shop.example.com']);
    }

    public function test_download_uses_broker_and_rejects_traversal(): void
    {
        $this->actingAs($this->admin());
        $this->get('/vhosts/shop.example.com/files/download?path=public/hello.txt')
            ->assertOk()
            ->assertStreamedContent("hello\n");
        $this->get('/vhosts/shop.example.com/files/download?path=../../../etc/passwd')
            ->assertForbidden();
        $this->get('/vhosts/projob.az/files/download?path=index.php')
            ->assertForbidden();
    }

    public function test_no_extract_action(): void
    {
        $fake = $this->app->make(FakeBroker::class);
        $res = $fake->handle('vhost.files.extract', ['shop.example.com'], ['path' => 'x.zip']);
        $this->assertFalse($res->ok);
        $this->assertStringContainsString('unknown action', strtolower((string) $res->error));
    }

    public function test_cli_list_read_and_same_validation(): void
    {
        $this->artisan('azerioid:vhost', [
            'action' => 'files',
            'filesOp' => 'list',
            '--domain' => 'shop.example.com',
            '--json' => true,
        ])->expectsOutputToContain('index.php')->assertExitCode(0);

        $this->artisan('azerioid:vhost', [
            'action' => 'files',
            'filesOp' => 'read',
            '--domain' => 'shop.example.com',
            '--path' => 'public/hello.txt',
        ])->expectsOutputToContain('hello')->assertExitCode(0);

        $this->artisan('azerioid:vhost', [
            'action' => 'files',
            'filesOp' => 'read',
            '--domain' => 'shop.example.com',
            '--path' => '../../../etc/passwd',
        ])->expectsOutputToContain('outside')->assertExitCode(1);

        $this->artisan('azerioid:vhost', [
            'action' => 'files',
            'filesOp' => 'list',
            '--domain' => 'projob.az',
        ])->expectsOutputToContain('read-only')->assertExitCode(1);
    }

    public function test_help_mentions_vhost_files(): void
    {
        $this->artisan('azerioid:help')
            ->expectsOutputToContain('vhost files')
            ->assertExitCode(0);
    }

    public function test_cli_mkdir_rename_delete(): void
    {
        $this->artisan('azerioid:vhost', [
            'action' => 'files',
            'filesOp' => 'mkdir',
            '--domain' => 'shop.example.com',
            '--path' => 'tmp',
        ])->assertExitCode(0);
        $this->artisan('azerioid:vhost', [
            'action' => 'files',
            'filesOp' => 'rename',
            '--domain' => 'shop.example.com',
            '--path' => 'tmp',
            '--dest' => 'tmp2',
        ])->assertExitCode(0);
        $this->artisan('azerioid:vhost', [
            'action' => 'files',
            'filesOp' => 'delete',
            '--domain' => 'shop.example.com',
            '--path' => 'tmp2',
        ])->assertExitCode(0);
        $fake = $this->app->make(FakeBroker::class);
        $this->assertArrayNotHasKey('tmp2', $fake->vhostFiles['shop.example.com']);
    }

    public function test_ui_mkdir_rejects_slash_names_instead_of_stripping(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);
        Livewire::test(\App\Livewire\VhostFilesPage::class, ['domain' => 'shop.example.com'])
            ->set('newFolder', '../escape')
            ->call('mkdir')
            ->assertSet('error', 'Invalid file name.');
        $this->assertArrayNotHasKey('..escape', $fake->vhostFiles['shop.example.com'] ?? []);
        $this->assertArrayNotHasKey('../escape', $fake->vhostFiles['shop.example.com'] ?? []);
    }

    public function test_ui_rename_rejects_slash_names_instead_of_joining(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\VhostFilesPage::class, ['domain' => 'shop.example.com'])
            ->call('startRename', 'index.php')
            ->set('renameTo', '../escape')
            ->call('rename')
            ->assertSet('error', 'Invalid file name.');
    }

    public function test_nested_directory_navigation_lists_only_that_folder(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\VhostFilesPage::class, ['domain' => 'shop.example.com'])
            ->call('openDir', 'public')
            ->assertSet('path', 'public')
            ->assertSet('error', null)
            ->assertSee('hello.txt')
            ->assertSee('public')
            ->assertDontSee('index.php')
            ->call('openDir', '')
            ->assertSee('index.php')
            ->assertSee('public');
    }

    public function test_bulk_delete_removes_all_selected(): void
    {
        $this->actingAs($this->admin());
        $lw = Livewire::test(\App\Livewire\VhostFilesPage::class, ['domain' => 'shop.example.com']);
        $lw->call('askDeleteMany', ['index.php', 'public/hello.txt'])
            ->call('delete')
            ->assertSet('error', null)
            ->assertSet('confirmDelete', null);

        $fake = $this->app->make(FakeBroker::class);
        $this->assertArrayNotHasKey('index.php', $fake->vhostFiles['shop.example.com']);
        $this->assertArrayNotHasKey('public/hello.txt', $fake->vhostFiles['shop.example.com']);
        $this->assertArrayHasKey('public', $fake->vhostFiles['shop.example.com']);
    }

    public function test_binary_file_is_not_loaded_into_the_editor(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);
        $fake->vhostFiles['shop.example.com']['logo.png'] = [
            'type' => 'file',
            'content' => "\x89PNG\r\n\x1a\n\0binary",
            'mtime' => time(),
        ];

        Livewire::test(\App\Livewire\VhostFilesPage::class, ['domain' => 'shop.example.com'])
            ->call('startEdit', 'logo.png')
            ->assertSet('error', null)
            ->assertSet('editorPath', 'logo.png')
            ->assertSet('editorText', false)
            ->assertSet('editorContent', '')
            ->assertSee('Binary file — use Download.');
    }

    public function test_zip_download_uses_broker_and_rejects_traversal(): void
    {
        $this->actingAs($this->admin());
        $res = $this->post('/vhosts/shop.example.com/files/zip', [
            'paths' => ['index.php', 'public/hello.txt'],
        ]);
        $res->assertOk();
        $this->assertStringContainsString('zip', strtolower((string) $res->headers->get('content-type')));

        $tmp = tempnam(sys_get_temp_dir(), 'azzip');
        file_put_contents($tmp, $res->streamedContent());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $this->assertNotFalse($zip->locateName('index.php'));
        $this->assertNotFalse($zip->locateName('public/hello.txt'));
        $this->assertSame("<?php echo 'shop';\n", $zip->getFromName('index.php'));
        $zip->close();
        @unlink($tmp);

        $this->post('/vhosts/shop.example.com/files/zip', [
            'paths' => ['../../../etc/passwd'],
        ])->assertStatus(422);

        $this->post('/vhosts/projob.az/files/zip', [
            'paths' => ['index.php'],
        ])->assertForbidden();
    }

    public function test_sort_toggles_name_column(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\VhostFilesPage::class, ['domain' => 'shop.example.com'])
            ->call('toggleSort', 'name')
            ->assertSet('sort', 'name')
            ->assertSet('sortDir', 'desc')
            ->call('toggleSort', 'size')
            ->assertSet('sort', 'size')
            ->assertSet('sortDir', 'asc');
    }
}
