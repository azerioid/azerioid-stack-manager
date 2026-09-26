<?php

namespace Tests\Feature;

use App\Models\AlertIncident;
use App\Models\Setting;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Broker\FakeBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * B2 / A47 — a scheduled job that fails now says so.
 *
 * The point of recording exit codes was never the log file; it was this. Until now a
 * cron job could fail every night and the panel was silent, because cron mails its
 * output to a local mailbox nobody opens.
 */
class CronAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        // Only the cron rule, so nothing else can fire.
        Setting::put('alert.rules', [
            'service_down' => false, 'observed_down' => false, 'reboot_required' => false,
            'tls' => false, 'backup_stale' => false, 'ssh' => false, 'cron_failed' => true,
            'disk_percent' => 99, 'ram_percent' => 99, 'load' => 100,
        ]);
    }

    private function job(array $overrides = []): string
    {
        $fake = app(FakeBroker::class);
        $fake->cronJobs[] = array_merge([
            'id' => 'job-aaaaaaaaaa',
            'owner' => 'shop.example.com',
            'schedule' => '*/5 * * * *',
            'command' => '/usr/bin/php artisan schedule:run',
            'enabled' => true,
            'note' => '',
            'created_at' => now()->toIso8601String(),
            'runs_as' => 'az-vh-shop-example-com',
        ], $overrides);

        return (string) ($fake->cronJobs[count($fake->cronJobs) - 1]['id']);
    }

    private function ran(string $id, int $exitCode): void
    {
        app(FakeBroker::class)->cronLogs[$id][] = now()->toIso8601String() . ' EXIT ' . $exitCode;
    }

    private function evaluate(): void
    {
        app(AlertEvaluator::class)->run();
    }

    private function incident(): ?AlertIncident
    {
        return AlertIncident::query()->where('rule_key', 'cron.failed')->first();
    }

    public function test_a_job_whose_last_run_failed_raises_an_alert(): void
    {
        $id = $this->job();
        $this->ran($id, 2);

        $this->evaluate();

        $this->assertNotNull($this->incident(), 'this is the case that used to be silent');
        $this->assertSame($id, $this->incident()->subject);
    }

    /** The alert has to be actionable without opening a terminal. */
    public function test_the_alert_names_the_job_the_owner_and_the_exit_code(): void
    {
        $id = $this->job();
        $this->ran($id, 137);

        $this->evaluate();

        $message = (string) $this->incident()->message;
        $this->assertStringContainsString($id, $message);
        $this->assertStringContainsString('shop.example.com', $message);
        $this->assertStringContainsString('137', $message);
    }

    public function test_a_successful_job_raises_nothing(): void
    {
        $this->ran($this->job(), 0);

        $this->evaluate();

        $this->assertNull($this->incident());
    }

    /** A job added a minute ago has not run, and is not broken. */
    public function test_a_job_that_has_never_run_raises_nothing(): void
    {
        $this->job();

        $this->evaluate();

        $this->assertNull($this->incident());
    }

    /** The operator turned it off, and its last failure is probably why. */
    public function test_a_disabled_job_is_skipped(): void
    {
        $id = $this->job(['enabled' => false]);
        $this->ran($id, 1);

        $this->evaluate();

        $this->assertNull($this->incident());
    }

    public function test_the_latest_run_is_what_counts(): void
    {
        $id = $this->job();
        $this->ran($id, 1);
        $this->ran($id, 0);

        $this->evaluate();

        $this->assertNull($this->incident(), 'a job that has recovered is not failing');
    }

    /** Two failing jobs are two problems; fixing one must resolve one. */
    public function test_each_job_gets_its_own_incident(): void
    {
        $a = $this->job();
        $b = $this->job(['id' => 'job-bbbbbbbbbb', 'owner' => 'blog.example.com']);
        $this->ran($a, 1);
        $this->ran($b, 1);

        $this->evaluate();

        $this->assertSame(2, AlertIncident::query()->where('rule_key', 'cron.failed')->count());
    }

    public function test_an_incident_resolves_once_the_job_works_again(): void
    {
        $id = $this->job();
        $this->ran($id, 1);
        $this->evaluate();
        $this->assertSame('open', $this->incident()->status);

        $this->ran($id, 0);
        $this->evaluate();

        $this->assertSame('resolved', $this->incident()->status);
    }

    public function test_the_rule_can_be_turned_off(): void
    {
        $rules = Setting::get('alert.rules', []);
        $rules['cron_failed'] = false;
        Setting::put('alert.rules', $rules);
        $this->ran($this->job(), 1);

        $this->evaluate();

        $this->assertNull($this->incident());
    }
}
