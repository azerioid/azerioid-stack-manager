<?php

namespace Tests\Feature;

use App\Services\Broker\FakeBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * A56: the scheduler moves site-bound programs to their site's account; `azerioid process
 * identity` is the status check and the operator retry.
 */
class ProgramIdentityCliTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_starts_the_migration_while_programs_are_pending(): void
    {
        $fake = $this->app->make(FakeBroker::class);

        $this->assertSame(0, Artisan::call('azerioid:program-identity-converge'));
        $this->assertSame(1, $fake->programIdentityConvergeStarts);

        $fake->programsIsolated = true;
        Artisan::call('azerioid:program-identity-converge');
        $this->assertSame(1, $fake->programIdentityConvergeStarts);
    }

    public function test_status_is_nonzero_until_every_program_runs_as_its_site(): void
    {
        $fake = $this->app->make(FakeBroker::class);

        $this->assertSame(1, Artisan::call('azerioid:process', ['action' => 'identity']));
        $this->assertStringContainsString('octane-shop-example-com', Artisan::output());

        $fake->programsIsolated = true;
        $this->assertSame(0, Artisan::call('azerioid:process', ['action' => 'identity', 'name' => 'status']));
    }

    public function test_apply_requires_confirm(): void
    {
        $fake = $this->app->make(FakeBroker::class);

        $this->assertSame(2, Artisan::call('azerioid:process', ['action' => 'identity', 'name' => 'apply']));
        $this->assertFalse($fake->programsIsolated);

        $this->assertSame(0, Artisan::call('azerioid:process', ['action' => 'identity', 'name' => 'apply', '--confirm' => true]));
        $this->assertTrue($fake->programsIsolated);
        $this->assertSame('ISOLATE-PROGRAMS', $fake->stdinLog['program.identity.apply']['confirm']);
    }
}
