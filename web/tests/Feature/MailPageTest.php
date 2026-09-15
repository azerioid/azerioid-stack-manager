<?php

namespace Tests\Feature;

use App\Livewire\ComponentsPage;
use App\Livewire\MailPage;
use App\Livewire\SettingsPage;
use App\Livewire\VhostsPage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class MailPageTest extends TestCase
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

    private function broker(): FakeBroker
    {
        return app(FakeBroker::class);
    }

    private function installedMailBroker(): FakeBroker
    {
        $broker = $this->broker();
        $broker->fakeInstalledComponents['mail'] = true;

        return $broker;
    }

    public function test_page_explains_how_to_install_when_mail_is_absent(): void
    {
        $this->actingAs($this->admin());

        $this->get('/mail')
            ->assertOk()
            ->assertSee('Mail', false)
            ->assertSee('Components', false);
    }

    public function test_health_strip_shows_the_blocked_wording_and_a_relay_cta(): void
    {
        $this->actingAs($this->admin());
        $this->installedMailBroker();

        $this->get('/mail')
            ->assertOk()
            ->assertSee('Direct mail delivery: blocked by your provider — configure a relay to send mail', false)
            ->assertSee('Configure relay', false);
    }

    public function test_health_strip_shows_the_available_wording_when_25_is_open(): void
    {
        $this->actingAs($this->admin());
        $broker = $this->installedMailBroker();
        $broker->mailOutbound25Open = true;

        $this->get('/mail')
            ->assertOk()
            ->assertSee('Direct mail delivery: available', false);
    }

    public function test_domain_cannot_be_enabled_before_a_mail_hostname_exists(): void
    {
        $this->actingAs($this->admin());
        $this->installedMailBroker();

        Livewire::test(MailPage::class)
            ->set('enableDomain', 'raww.az')
            ->call('enableDomainMail')
            ->assertSet('flash', null)
            ->assertSet('error', 'Set the mail hostname before enabling mail for a domain.');
    }

    public function test_hostname_then_domain_then_mailbox_shows_the_password_once(): void
    {
        $this->actingAs($this->admin());
        $this->installedMailBroker();

        $page = Livewire::test(MailPage::class)
            ->set('hostname', 'mail.raww.az')
            ->call('saveHostname')
            ->assertSet('error', null)
            ->set('enableDomain', 'raww.az')
            ->call('enableDomainMail')
            ->assertSet('error', null)
            ->set('newMailboxAddress', 'zaur@raww.az')
            ->call('addMailbox')
            ->assertSet('error', null)
            ->assertSet('revealedFor', 'zaur@raww.az');

        // The password is generated panel-side and shown exactly once.
        $this->assertNotEmpty($page->get('revealedPassword'));
    }

    public function test_components_page_requires_replace_mta_before_queueing_mail(): void
    {
        $this->actingAs($this->admin());
        $broker = $this->broker();
        $broker->fakeForeignMtaPresent = true;

        Livewire::test(ComponentsPage::class)
            ->call('askInstall', 'mail')
            ->assertSet('pendingInstall', 'mail')
            ->assertSee('REPLACE-MTA')
            ->set('replaceMtaConfirm', 'replace mta')
            ->call('confirmInstall')
            ->assertSet('error', 'Type REPLACE-MTA exactly to replace the existing mail transport.')
            // The modal stays open so the operator can retype rather than start over.
            ->assertSet('pendingInstall', 'mail');

        $this->assertDatabaseMissing('component_operations', ['component_id' => 'mail']);
    }

    public function test_typed_replace_mta_queues_the_install_with_the_confirmation(): void
    {
        $this->actingAs($this->admin());
        $broker = $this->broker();
        $broker->fakeForeignMtaPresent = true;

        Livewire::test(ComponentsPage::class)
            ->call('askInstall', 'mail')
            ->set('replaceMtaConfirm', 'REPLACE-MTA')
            ->call('confirmInstall')
            ->assertSet('flash', 'Queued install for mail.');

        $operation = \App\Models\ComponentOperation::query()->where('component_id', 'mail')->first();
        $this->assertNotNull($operation);
        $this->assertSame('REPLACE-MTA', $operation->options['confirm'] ?? null);
        $this->assertSame('completed', $operation->status);
    }

    public function test_mail_install_without_a_foreign_mta_still_shows_the_opt_in_step(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ComponentsPage::class)
            ->call('askInstall', 'mail')
            ->assertSet('pendingInstall', 'mail')
            ->assertSet('foreignMtaReasons', [])
            ->call('confirmInstall')
            ->assertSet('flash', 'Queued install for mail.');
    }

    public function test_panel_alerts_over_local_mail_are_off_by_default_and_need_the_component(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(SettingsPage::class)
            ->assertSet('mailAlertsViaLocalMail', false)
            ->set('mailAlertsViaLocalMail', true)
            ->call('saveMailAlerts')
            ->assertSet('mailAlertsViaLocalMail', false)
            ->assertSet('mailError', 'Install the mail component before routing panel alerts through it.');

        $this->assertFalse((bool) Setting::get('alerts_via_local_mail', false));
    }

    public function test_panel_alerts_can_be_opted_into_once_mail_is_installed(): void
    {
        $this->actingAs($this->admin());
        $this->installedMailBroker();

        Livewire::test(SettingsPage::class)
            ->set('mailAlertsViaLocalMail', true)
            ->call('saveMailAlerts')
            ->assertSet('mailError', null);

        $this->assertTrue((bool) Setting::get('alerts_via_local_mail', false));
    }

    public function test_vhost_delete_surfaces_mailboxes_before_destroying_them(): void
    {
        $this->actingAs($this->admin());
        $broker = $this->installedMailBroker();
        $broker->mail['hostname'] = 'mail.raww.az';
        $broker->mail['domains']['shop.example.com'] = ['enabled' => true, 'dkim_selector' => 'azerioid'];
        $broker->mail['mailboxes']['zaur@shop.example.com'] = [
            'domain' => 'shop.example.com',
            'local_part' => 'zaur',
            'disabled' => false,
        ];

        $page = Livewire::test(VhostsPage::class)->call('askDelete', 'shop.example.com');

        $this->assertSame(1, $page->get('mailDomains')['shop.example.com'] ?? null);
        $page->assertSee('Also delete mailboxes and stored mail for this domain', false)
            ->assertSet('dropMailOnDelete', false);
    }
}
