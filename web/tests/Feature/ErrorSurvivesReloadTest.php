<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A refusal from the broker must reach the operator.
 *
 * Every page here follows the same shape: perform a write, then reload the listing. If the reload
 * clears `$this->error`, the refusal is replaced by silence — the operator sees nothing happen and
 * no reason why, which is indistinguishable from the action having worked.
 *
 * Found in FirewallPage, then again in VhostFilesPage a day later, then by audit in three more. It
 * is a shape, not an incident, so it gets a test per page rather than a fix per page.
 */
class ErrorSurvivesReloadTest extends TestCase
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

    private function failNext(): void
    {
        app(FakeBroker::class)->failNextCall = true;
    }

    public function test_a_failed_database_drop_reports_why(): void
    {
        $page = Livewire::actingAs($this->admin())->test(\App\Livewire\DatabasesPage::class)
            ->set('confirmDelete', 'projob')
            ->set('confirmTyped', 'projob');

        $this->failNext();
        $page->call('delete');

        $this->assertNotNull($page->get('error'), 'the refusal must survive the reload that follows it');
        $this->assertNull($page->get('flash'));
    }

    public function test_a_failed_process_action_reports_why(): void
    {
        $page = Livewire::actingAs($this->admin())->test(\App\Livewire\ProcessesPage::class);

        $this->failNext();
        $page->set('pendingName', 'demo')->set('pendingAction', 'restart')->call('runControl');

        $this->assertNotNull($page->get('error'));
    }

    /** The two that were already fixed, kept here so the shape is covered in one place. */
    public function test_a_failed_firewall_change_reports_why(): void
    {
        $page = Livewire::actingAs($this->admin())->test(\App\Livewire\FirewallPage::class)
            ->set('action', 'deny')
            ->set('port', '22');

        $page->call('add');

        $this->assertNotNull($page->get('error'));
    }

    /**
     * ComponentsPage and VhostContainerLogsPage also clear the error in reload(), but every write
     * there returns before reaching it, so the refusal does survive. Asserted rather than assumed,
     * because a later edit that removes one of those early returns would reintroduce the bug
     * silently — and this test is the only thing that would notice.
     */
    public function test_a_failed_component_action_reports_why(): void
    {
        $page = Livewire::actingAs($this->admin())->test(\App\Livewire\ComponentsPage::class);

        $this->failNext();
        $page->call('adopt', 'redis');

        $this->assertNotNull($page->get('error'));
    }

    public function test_a_failed_file_operation_reports_why(): void
    {
        app(FakeBroker::class)->vhostFiles['shop.example.com'] = [
            '' => ['type' => 'dir', 'mtime' => time()],
        ];

        $page = Livewire::actingAs($this->admin())
            ->test(\App\Livewire\VhostFilesPage::class, ['domain' => 'shop.example.com'])
            ->call('startExtract', 'missing.zip')
            ->call('extract');

        $this->assertNotNull($page->get('error'));
    }
}
