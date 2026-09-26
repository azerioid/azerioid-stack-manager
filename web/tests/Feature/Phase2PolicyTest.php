<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class Phase2PolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_over_projob_is_refused_without_force(): void
    {
        $this->actingAs($this->admin());
        $this->seedBackupSecrets();

        Livewire::test(\App\Livewire\BackupsPage::class)
            ->set('restore_key', 'azerioid/files/projob.az/fixture.bin')
            ->set('restore_site', 'projob.az')
            ->set('restore_force', false)
            ->call('applyFiles')
            ->assertSet('error', 'Refusing to restore over a read-only vhost without force + confirm PROJOB.AZ.');
    }

    public function test_restore_existing_db_refused_without_overwrite(): void
    {
        $this->actingAs($this->admin());
        $this->seedBackupSecrets();

        Livewire::test(\App\Livewire\BackupsPage::class)
            ->set('restore_key', 'azerioid/db/projob/fixture.bin')
            ->set('restore_target', 'projob')
            ->set('restore_overwrite', false)
            ->call('restoreDb')
            ->assertSet('error', 'Target database exists. Restore into a new name, or send overwrite confirm OVERWRITE.');
    }

    public function test_restore_into_new_db_is_allowed(): void
    {
        $this->actingAs($this->admin());
        $this->seedBackupSecrets();

        Livewire::test(\App\Livewire\BackupsPage::class)
            ->set('restore_key', 'azerioid/db/all/fixture.bin')
            ->set('restore_target', 'projob_restore_1')
            ->call('restoreDb')
            ->assertSet('error', null)
            // B5: the restore is queued now; the guards above still refuse inline.
            ->assertSet('flash', 'Restore into projob_restore_1 queued. Progress and output are on the Operations page.');
    }

    public function test_reboot_without_confirm_is_rejected(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\UpdatesPage::class)
            ->set('confirm', '')
            ->call('reboot')
            ->assertSet('error', 'Confirmation phrase did not match.');
    }

    public function test_spaces_secret_is_not_returned_after_save(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\BackupsPage::class)
            ->set('endpoint', 'https://fra1.digitaloceanspaces.com')
            ->set('region', 'fra1')
            ->set('bucket', 'azerioid-backups')
            ->set('access_key', 'DO00TESTKEY')
            ->set('secret', 'supersecretkeyvalue')
            ->set('passphrase', 'abcdefghijklmnopqrst')
            ->call('saveCredentials')
            ->assertSet('access_key', '')
            ->assertSet('secret', '')
            ->assertSet('passphrase', '')
            ->assertDontSee('supersecretkeyvalue', false)
            ->assertDontSee('abcdefghijklmnopqrst', false);

        $this->assertNotNull(Setting::getSecret('spaces.secret'));
        $this->assertStringStartsWith('enc:', (string) Setting::query()->find('spaces.secret')?->value);
    }

    private function seedBackupSecrets(): void
    {
        Setting::put('spaces.endpoint', 'https://fra1.digitaloceanspaces.com');
        Setting::put('spaces.region', 'fra1');
        Setting::put('spaces.bucket', 'azerioid-backups');
        Setting::putSecret('spaces.access_key', 'DO00TESTKEY');
        Setting::putSecret('spaces.secret', 'supersecretkeyvalue');
        Setting::putSecret('backup.passphrase', 'abcdefghijklmnopqrst');
    }

    private function admin(): User
    {
        $totp = new TotpService();
        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($totp->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
