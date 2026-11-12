<?php

namespace Tests\Unit;

use App\Jobs\RunPanelUpdateJob;
use App\Models\PanelUpdateOperation;
use App\Services\Broker\BrokerClient;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class RunPanelUpdateJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_middleware_uses_without_overlapping_with_release_semantics(): void
    {
        $job = new RunPanelUpdateJob(1);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('panel-update', $middleware[0]->key);
        $this->assertSame(15, $middleware[0]->releaseAfter);
        $this->assertSame(960, $middleware[0]->expiresAfter);
    }

    public function test_handle_marks_operation_completed_via_broker(): void
    {
        $operation = PanelUpdateOperation::query()->create([
            'status' => 'queued',
            'target_tag' => 'v1.0.1',
            'from_commit' => 'aaa',
            'to_commit' => 'bbb',
        ]);

        (new RunPanelUpdateJob($operation->id))->handle($this->app->make(BrokerClient::class));

        $operation->refresh();
        $this->assertSame('completed', $operation->status);
        $this->assertNotNull($operation->started_at);
        $this->assertNotNull($operation->finished_at);
        $this->assertNull($operation->error);
        $this->assertSame('v0.2.2', $operation->to_tag);
    }

    /** A104: "latest" is the release the admin was shown; an explicit tag is passed as such. */
    public function test_handle_passes_the_checked_commit_for_a_latest_update(): void
    {
        $commit = str_repeat('b', 40);
        $latest = PanelUpdateOperation::query()->create(['status' => 'queued', 'to_commit' => $commit]);
        $broker = $this->app->make(BrokerClient::class);
        (new RunPanelUpdateJob($latest->id))->handle($broker);
        $explicit = PanelUpdateOperation::query()->create(['status' => 'queued', 'target_tag' => 'v1.0.1', 'to_commit' => $commit]);
        (new RunPanelUpdateJob($explicit->id))->handle($broker);

        $applies = array_values(array_filter($this->app->make(\App\Services\Broker\FakeBroker::class)->calls, static fn (array $c): bool => $c['action'] === 'panel.update.apply'));
        $this->assertCount(2, $applies);
        $this->assertSame($commit, $applies[0]['stdin']['expected_commit'] ?? null);
        $this->assertArrayNotHasKey('tag', $applies[0]['stdin']);
        $this->assertSame('v1.0.1', $applies[1]['stdin']['tag'] ?? null);
        $this->assertArrayNotHasKey('expected_commit', $applies[1]['stdin']);
    }

    public function test_without_overlapping_releases_when_lock_held(): void
    {
        $held = Mockery::mock(Lock::class);
        $held->shouldReceive('get')->once()->andReturn(false);

        $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('lock')->once()->andReturn($held);
        $this->app->instance(\Illuminate\Contracts\Cache\Repository::class, $cache);

        $job = Mockery::mock(RunPanelUpdateJob::class)->makePartial();
        $job->shouldReceive('release')->once()->with(15);

        $middleware = (new RunPanelUpdateJob(1))->middleware()[0];
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware);

        $ran = false;
        $middleware->handle($job, function () use (&$ran) {
            $ran = true;
        });

        $this->assertFalse($ran, 'Overlapping job must not run handle.');
    }

    public function test_cli_refuses_second_apply_while_first_is_active(): void
    {
        PanelUpdateOperation::query()->create([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->withoutMockingConsoleOutput();
        $code = Artisan::call('azerioid:panel', [
            'action' => 'update',
            'op' => 'apply',
            '--confirm' => true,
        ]);
        $out = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('already queued or running', $out);
        $this->assertSame(1, PanelUpdateOperation::query()->count());
    }
}
