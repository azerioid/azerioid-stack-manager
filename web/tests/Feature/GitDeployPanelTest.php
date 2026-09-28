<?php

namespace Tests\Feature;

use App\Console\Commands\DeployScheduled;
use App\Jobs\RunOperationJob;
use App\Livewire\VhostDeployPage;
use App\Models\Operation;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B8 / A53 in the panel: configure, deploy (queued), roll back, schedule.
 */
class GitDeployPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString((new TotpService())->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_configure_shows_the_key_and_a_deploy_is_queued(): void
    {
        Queue::fake();
        $fake = app(FakeBroker::class);

        Livewire::actingAs($this->admin())->test(VhostDeployPage::class, ['domain' => 'shop.test'])
            ->set('repository', 'git@github.com:acme/shop.git')
            ->set('preset', 'laravel')
            ->call('save')
            ->assertSet('error', null)
            ->assertSet('configured', true)
            ->assertSet('publicKey', fn ($k) => str_starts_with((string) $k, 'ssh-ed25519 '))
            ->call('deployNow')
            ->assertSet('error', null);

        $this->assertSame('laravel', $fake->deploys['shop.test']['preset']);
        $this->assertSame('deploy.run', Operation::query()->sole()->broker_action);
        Queue::assertPushed(RunOperationJob::class);
    }

    public function test_a_custom_command_without_the_confirm_is_refused(): void
    {
        $fake = app(FakeBroker::class);

        Livewire::actingAs($this->admin())->test(VhostDeployPage::class, ['domain' => 'shop.test'])
            ->set('repository', 'git@github.com:acme/shop.git')
            ->set('preset', 'custom')->set('command', 'make deploy')
            ->call('save')
            ->assertSet('error', fn ($e) => $e !== null);

        $this->assertArrayNotHasKey('shop.test', $fake->deploys);
    }

    public function test_a_dangerous_repository_is_refused(): void
    {
        Livewire::actingAs($this->admin())->test(VhostDeployPage::class, ['domain' => 'shop.test'])
            ->set('repository', 'ext::sh -c id')
            ->call('save')
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'repository must be'));
    }

    public function test_schedule_due_rules(): void
    {
        $now = Carbon::parse('2026-09-28 03:10:00');
        $this->assertTrue(DeployScheduled::due('daily@3', null, $now));
        $this->assertFalse(DeployScheduled::due('daily@3', '2026-09-28T03:05:00Z', $now));
        $this->assertFalse(DeployScheduled::due('daily@4', null, $now));
        $this->assertTrue(DeployScheduled::due('hourly', '2026-09-28T02:00:00Z', $now));
        $this->assertFalse(DeployScheduled::due('hourly', '2026-09-28T03:00:00Z', $now));
        $this->assertFalse(DeployScheduled::due('off', null, $now));
    }

    public function test_the_scheduler_queues_due_deploys_as_scheduled(): void
    {
        Queue::fake();
        $fake = app(FakeBroker::class);
        $fake->deploys['shop.test'] = ['repository' => 'x', 'branch' => 'main', 'preset' => 'none', 'command' => null, 'schedule' => 'hourly', 'state' => []];
        $fake->deploys['blog.test'] = ['repository' => 'x', 'branch' => 'main', 'preset' => 'none', 'command' => null, 'schedule' => 'off', 'state' => []];

        $this->assertSame(0, Artisan::call('azerioid:deploy-scheduled'));

        $op = Operation::query()->sole();
        $this->assertSame('shop.test', $op->subject_id);
        Queue::assertPushed(RunOperationJob::class, fn ($job): bool => $job->stdin['trigger'] === 'schedule');
    }

    public function test_cli_set_and_run(): void
    {
        $fake = app(FakeBroker::class);

        $this->assertSame(0, Artisan::call('azerioid:deploy', ['action' => 'set', 'domain' => 'shop.test', '--repository' => 'https://github.com/acme/shop.git']));
        $this->assertStringContainsString('deploy key : ssh-ed25519', Artisan::output());
        $this->assertSame(0, Artisan::call('azerioid:deploy', ['action' => 'run', 'domain' => 'shop.test']));
        $this->assertSame('manual', $fake->deployRuns[0]['trigger']);
    }
}
