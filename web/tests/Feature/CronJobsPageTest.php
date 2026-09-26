<?php

namespace Tests\Feature;

use App\Livewire\CronPage;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B2 / request #4 — the scheduled jobs page.
 *
 * The broker tests cover rendering and identity; these cover what the operator is
 * shown: that a site job is not a root job, that root is never the quiet default, and
 * that a failed run says so instead of reporting success.
 */
class CronJobsPageTest extends TestCase
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
        return Livewire::actingAs($this->admin())->test(CronPage::class);
    }

    private function fake(): FakeBroker
    {
        return app(FakeBroker::class);
    }

    private function addJob(string $owner = 'shop.example.com', array $extra = []): string
    {
        $page = $this->page()
            ->set('owner', $owner)
            ->set('schedule', '*/5 * * * *')
            ->set('command', '/usr/bin/php /data/www/shop.example.com/artisan schedule:run');
        foreach ($extra as $key => $value) {
            $page->set($key, $value);
        }
        $page->call('add');

        return (string) ($this->fake()->cronJobs[0]['id'] ?? '');
    }

    public function test_a_site_job_is_added_and_shown_running_as_the_site(): void
    {
        $this->addJob();

        $this->assertSame('az-vh-shop-example-com', $this->fake()->cronJobs[0]['runs_as']);
        $this->page()->assertSee('az-vh-shop-example-com')->assertSee('*/5 * * * *');
    }

    public function test_the_page_says_which_identity_a_new_job_will_run_as(): void
    {
        $this->page()
            ->set('owner', 'shop.example.com')
            ->set('command', '/bin/true')
            ->call('add')
            ->assertSet('flash', 'Job added; it will run as az-vh-shop-example-com.');
    }

    public function test_a_root_job_needs_the_typed_confirmation(): void
    {
        $this->page()
            ->set('owner', 'root')
            ->set('command', '/usr/local/bin/tidy.sh')
            ->call('add')
            ->assertSet('error', 'Confirmation phrase did not match.');

        $this->assertSame([], $this->fake()->cronJobs);
    }

    public function test_choosing_root_warns_before_anything_is_typed(): void
    {
        $this->page()
            ->set('owner', 'root')
            ->assertSee('RUN-AS-ROOT')
            ->assertSee('does not need root to run that site');
    }

    public function test_a_root_job_is_accepted_with_the_confirmation(): void
    {
        $this->page()
            ->set('owner', 'root')
            ->set('command', '/usr/local/bin/tidy.sh')
            ->set('confirm', 'RUN-AS-ROOT')
            ->call('add')
            ->assertSet('error', null);

        $this->assertSame('root', $this->fake()->cronJobs[0]['runs_as']);
    }

    public function test_a_job_for_a_vhost_with_no_identity_is_refused_with_the_reason(): void
    {
        $this->page()
            ->set('owner', 'missing.example.com')
            ->set('command', '/bin/true')
            ->call('add');

        $this->assertSame([], $this->fake()->cronJobs);
    }

    public function test_reboot_is_refused_from_the_page_too(): void
    {
        $this->page()
            ->set('owner', 'shop.example.com')
            ->set('schedule', '@reboot')
            ->set('command', '/bin/true')
            ->call('add');

        $this->assertSame([], $this->fake()->cronJobs);
    }

    public function test_disable_and_enable_change_only_that_job(): void
    {
        $id = $this->addJob();

        $this->page()->call('toggle', $id, false);
        $this->assertFalse($this->fake()->cronJobs[0]['enabled']);

        $this->page()->call('toggle', $id, true);
        $this->assertTrue($this->fake()->cronJobs[0]['enabled']);
    }

    public function test_removing_a_job_removes_it(): void
    {
        $id = $this->addJob();

        $this->page()->call('delete', $id);

        $this->assertSame([], $this->fake()->cronJobs);
    }

    public function test_running_a_job_now_reports_the_identity_and_the_exit_code(): void
    {
        $id = $this->addJob();

        $this->page()->call('runNow', $id)
            ->assertSet('flash', 'Ran as az-vh-shop-example-com and exited 0.');
    }

    /** A job that failed must not be reported as having worked. */
    public function test_a_failing_run_is_reported_as_a_failure_with_its_output(): void
    {
        $id = $this->addJob();
        $this->fake()->cronNextExitCode = 2;

        $this->page()->call('runNow', $id)
            ->assertSet('flash', null)
            ->assertSet('error', 'Exited 2: fake job failed');
    }

    public function test_the_log_opens_and_closes(): void
    {
        $id = $this->addJob();
        $this->fake()->cronLogs[$id] = ['2026-09-26T03:00:00+00:00 EXIT 0'];

        $this->page()->call('showLog', $id)->assertSee('EXIT 0');
    }

    public function test_a_job_that_has_never_run_says_so_rather_than_showing_an_empty_box(): void
    {
        $id = $this->addJob();

        $this->page()->call('showLog', $id)->assertSee('has not run since it was added');
    }

    /** Silently taking over the operator's own cron lines is how a host loses jobs. */
    public function test_root_lines_the_panel_does_not_manage_are_shown_as_such(): void
    {
        $this->page()
            ->assertSee('does not manage')
            ->assertSee('/usr/local/bin/nightly-backup.sh');
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get('/cron')->assertRedirect();
    }
}
