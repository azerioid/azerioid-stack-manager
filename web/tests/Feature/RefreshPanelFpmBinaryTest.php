<?php

namespace Tests\Feature;

use App\Models\PanelUpdateOperation;
use App\Services\Broker\FakeBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** KI-3: the scheduled refresh restarts the panel's FPM master, so it waits for quiet. */
class RefreshPanelFpmBinaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_calls_the_broker_when_idle(): void
    {
        $fake = $this->app->make(FakeBroker::class);

        $this->assertSame(0, Artisan::call('azerioid:panel-fpm-refresh'));
        $this->assertContains('panel.fpm.refresh', $fake->callLog);
    }

    public function test_waits_while_a_self_update_is_running(): void
    {
        $fake = $this->app->make(FakeBroker::class);
        PanelUpdateOperation::query()->create(['status' => 'running']);

        $this->assertSame(0, Artisan::call('azerioid:panel-fpm-refresh'));
        $this->assertNotContains('panel.fpm.refresh', $fake->callLog);
    }

    public function test_is_scheduled_hourly(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'azerioid:panel-fpm-refresh'));

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
    }
}
