<?php

namespace Tests\Feature;

use App\Livewire\FirewallPage;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B2 / request #2 — the firewall page.
 *
 * The broker tests prove the refusals; these prove the page cannot get round them and
 * that the pending-change state is visible, because a revert window nobody notices is
 * a change that silently undoes itself.
 */
class FirewallPageTest extends TestCase
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
        return Livewire::actingAs($this->admin())->test(FirewallPage::class);
    }

    private function fake(): FakeBroker
    {
        return app(FakeBroker::class);
    }

    /** @return list<string> */
    private function rules(): array
    {
        return array_map(
            static fn (array $r): string => $r['action'] . ' ' . $r['port'] . '/' . $r['protocol']
                . ' from ' . ($r['source'] ?? 'any'),
            $this->fake()->firewallRules
        );
    }

    public function test_the_page_lists_rules_and_says_which_backend_they_are_on(): void
    {
        $this->page()
            ->assertSee('22/tcp')
            ->assertSee('8080/tcp')
            ->assertSee('ufw');
    }

    public function test_a_rule_the_operator_wrote_is_distinguished_from_a_panel_rule(): void
    {
        $this->page()
            ->assertSee('yours (not panel-managed)')
            ->assertSee('azerioid-db-mariadb');
    }

    public function test_adding_a_rule_applies_it_and_arms_a_revert_window(): void
    {
        $this->page()
            ->set('action', 'allow')
            ->set('port', '8443')
            ->set('source', '10.1.0.0/16')
            ->set('note', 'office')
            ->call('add')
            ->assertSet('error', null);

        $this->assertContains('allow 8443/tcp from 10.1.0.0/16', $this->rules());
        $this->assertTrue($this->fake()->firewallRevertState['armed']);
    }

    /** The operator has to see that something is waiting on them. */
    public function test_a_pending_change_is_shown_with_both_ways_out(): void
    {
        $page = $this->page()->set('port', '8443')->call('add');

        $page->assertSee('A change is waiting on you')
            ->assertSee('Confirm, keep the change')
            ->assertSee('Revert it now');
    }

    public function test_confirming_keeps_the_rule(): void
    {
        $page = $this->page()->set('port', '8443')->call('add');

        $page->call('confirmChange')->assertSet('error', null);

        $this->assertContains('allow 8443/tcp from any', $this->rules());
        $this->assertFalse($this->fake()->firewallRevertState['armed']);
    }

    public function test_reverting_takes_the_rule_back_out(): void
    {
        $page = $this->page()->set('port', '8443')->call('add');

        $page->call('revertChange')->assertSet('error', null);

        $this->assertNotContains('allow 8443/tcp from any', $this->rules());
    }

    public function test_the_page_surfaces_the_brokers_refusal_rather_than_its_own(): void
    {
        $this->page()
            ->set('action', 'deny')
            ->set('port', '22')
            ->call('add')
            ->assertSet('error', 'Refusing to deny port 22: this host accepts SSH on it, and closing it '
                . 'locks you out with no way back in from the network. Use the provider firewall if you '
                . 'genuinely mean to close it.');

        $this->assertNotContains('deny 22/tcp from any', $this->rules());
    }

    /** The guard follows sshd, so the page must not hardcode 22 either. */
    public function test_a_moved_ssh_port_is_the_one_protected_in_the_ui(): void
    {
        $this->fake()->fakeSshPort = 2222;
        $this->fake()->firewallRules[] = [
            'action' => 'allow', 'port' => 2222, 'protocol' => 'tcp', 'source' => null, 'comment' => '',
        ];

        $this->page()->assertSee('protected');

        $this->page()->call('remove', 'allow', 2222, 'tcp', null)
            ->assertSet('error', 'Refusing to remove the rule allowing port 2222: this host accepts SSH on it, '
                . 'and closing it locks you out with no way back in from the network. With a default-deny '
                . 'policy, removing it closes the port.');
    }

    public function test_removing_an_operator_rule_works(): void
    {
        $this->page()->call('remove', 'allow', 8080, 'tcp', null)->assertSet('error', null);

        $this->assertNotContains('allow 8080/tcp from any', $this->rules());
    }

    public function test_a_rule_owned_by_another_feature_cannot_be_removed_here(): void
    {
        $this->page()->call('remove', 'allow', 3306, 'tcp', '10.0.0.5');

        $this->assertContains('allow 3306/tcp from 10.0.0.5', $this->rules());
    }

    public function test_declining_the_window_needs_the_typed_confirmation(): void
    {
        $page = $this->page()
            ->set('port', '8443')
            ->set('withoutRevert', true)
            ->call('add');

        $page->assertSee('I-HAVE-CONSOLE-ACCESS');
        $this->assertNotContains('allow 8443/tcp from any', $this->rules());

        $page->set('confirm', 'I-HAVE-CONSOLE-ACCESS')->call('add')->assertSet('error', null);
        $this->assertContains('allow 8443/tcp from any', $this->rules());
        $this->assertFalse($this->fake()->firewallRevertState['armed']);
    }

    public function test_a_second_change_is_refused_while_one_is_pending(): void
    {
        $page = $this->page()->set('port', '8443')->call('add');

        $page->set('port', '8444')->call('add');

        $this->assertNotContains('allow 8444/tcp from any', $this->rules());
    }

    public function test_an_inactive_firewall_explains_itself_instead_of_showing_an_empty_table(): void
    {
        Livewire::actingAs($this->admin())->test(FirewallPage::class)
            ->set('state', [])
            ->assertSee('adding rules to an inactive firewall');
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get('/firewall')->assertRedirect();
    }
}
