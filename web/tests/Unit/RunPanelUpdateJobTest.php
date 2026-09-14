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
