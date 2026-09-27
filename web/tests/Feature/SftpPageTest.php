<?php

namespace Tests\Feature;

use App\Livewire\SftpPage;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A48 — the SFTP page.
 *
 * The broker suite covers the sshd guarantees. These cover what the operator is shown, and the one
 * thing most likely to go wrong in practice: enabling access and not realising a key is still
 * needed, so nobody can connect and nothing says why.
 */
class SftpPageTest extends TestCase
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

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->admin())->test(SftpPage::class);
    }

    private function fake(): FakeBroker
    {
        return app(FakeBroker::class);
    }

    private function key(string $comment = 'operator@workstation'): string
    {
        $type = 'ssh-ed25519';

        return $type . ' ' . base64_encode(pack('N', strlen($type)) . $type . pack('N', 32) . str_repeat("\x2a", 32))
            . ' ' . $comment;
    }

    private function domain(): string
    {
        return (string) ($this->fake()->vhosts[0]['domain'] ?? '');
    }

    public function test_the_page_shows_sshd_is_not_configured_yet(): void
    {
        $this->page()->assertSee('Not configured yet');
    }

    public function test_configuring_reports_that_admin_access_is_unchanged(): void
    {
        $this->page()->call('configure')
            ->assertSet('error', null)
            ->assertSet('flash', 'SFTP configured; sshd reloaded. Your own SSH access is unchanged.');

        $this->assertTrue($this->fake()->sftpConfigured);
    }

    public function test_enabling_a_site_adds_it_and_says_a_key_is_still_needed(): void
    {
        $domain = $this->domain();

        $page = $this->page()->call('toggle', $domain, true);

        $this->assertContains($domain, $this->fake()->sftpSites);
        $this->assertStringContainsString('nobody can connect until you do', (string) $page->get('flash'));
    }

    /** The trap this page exists to avoid: access granted that nobody can use. */
    public function test_a_site_with_no_keys_says_so_rather_than_looking_ready(): void
    {
        $domain = $this->domain();

        $this->page()
            ->call('toggle', $domain, true)
            ->assertSee('No keys installed')
            ->assertSee('There is no password to set');
    }

    public function test_disabling_removes_it(): void
    {
        $domain = $this->domain();
        $page = $this->page()->call('toggle', $domain, true);

        $page->call('toggle', $domain, false);

        $this->assertNotContains($domain, $this->fake()->sftpSites);
    }

    public function test_a_key_is_installed_and_listed_by_fingerprint(): void
    {
        $domain = $this->domain();
        $page = $this->page()->call('toggle', $domain, true);

        $page->set('newKey', $this->key())->call('addKey');

        $this->assertCount(1, $this->fake()->sftpKeys[$domain] ?? []);
        $page->assertSee('SHA256:')->assertSee('operator@workstation');
    }

    /** The key material must not come back to the browser once stored. */
    public function test_the_key_body_is_never_rendered_back(): void
    {
        $domain = $this->domain();
        $key = $this->key();
        $body = explode(' ', $key)[1];

        $page = $this->page()->call('toggle', $domain, true)->set('newKey', $key)->call('addKey');

        $this->assertStringNotContainsString($body, $page->html());
    }

    public function test_an_option_bearing_key_is_refused_with_the_brokers_reason(): void
    {
        $domain = $this->domain();

        $page = $this->page()
            ->call('toggle', $domain, true)
            ->set('newKey', 'command="/bin/sh" ' . $this->key())
            ->call('addKey');

        $this->assertStringContainsString('option-bearing', (string) $page->get('error'));
        $this->assertSame([], $this->fake()->sftpKeys[$domain] ?? []);
    }

    public function test_a_pasted_private_key_is_refused_and_called_out(): void
    {
        $domain = $this->domain();

        $page = $this->page()
            ->call('toggle', $domain, true)
            ->set('newKey', '-----BEGIN OPENSSH PRIVATE KEY----- b3BlbnNzaA==')
            ->call('addKey');

        $this->assertStringContainsString('compromised', (string) $page->get('error'));
    }

    public function test_a_key_can_be_removed(): void
    {
        $domain = $this->domain();
        $page = $this->page()->call('toggle', $domain, true)->set('newKey', $this->key())->call('addKey');
        $fingerprint = $this->fake()->sftpKeys[$domain][0]['fingerprint'];

        // addKey already leaves the key panel open for this site; calling showKeys again would
        // toggle it shut and removeKey would no-op.
        $page->call('removeKey', $fingerprint);

        $this->assertSame([], $this->fake()->sftpKeys[$domain]);
    }

    public function test_removing_the_drop_in_is_offered_once_configured(): void
    {
        $this->page()->call('configure')->assertSee('Remove');
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get('/sftp')->assertRedirect();
    }
}
