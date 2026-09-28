<?php

use App\Console\Commands\ConvergePanelIdentity;
use App\Console\Commands\ConvergeProgramIdentity;
use App\Console\Commands\ConvergeSitePhpPools;
use App\Console\Commands\ConvergeVhostIsolation;
use App\Console\Commands\DeployScheduled;
use App\Console\Commands\EvaluateAlerts;
use App\Console\Commands\PanelMaintenance;
use App\Console\Commands\RefreshPanelFpmBinary;
use App\Console\Commands\RunScheduledBackup;
use App\Console\Commands\SampleMetrics;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(SampleMetrics::class)->everyMinute()->withoutOverlapping();
Schedule::command(EvaluateAlerts::class)->everyMinute()->withoutOverlapping();
Schedule::command(RunScheduledBackup::class)->hourly()->withoutOverlapping();

// Reap operations whose worker died mid-run (they would otherwise wedge the
// Components page forever) and prune historical records. Every ten minutes keeps
// the wedge window short without adding meaningful load.
Schedule::command(PanelMaintenance::class)->everyTenMinutes()->withoutOverlapping();

// A39 Part A: finish moving the panel onto its own account after a self-update
// deploys the release that introduces it. A no-op broker call once migrated.
Schedule::command(ConvergePanelIdentity::class)->everyFiveMinutes()->withoutOverlapping();

// A49: move every vhost identity off the shared azerioid-vhosts group after a
// self-update deploys the release that introduces it. A no-op once isolated.
Schedule::command(ConvergeVhostIsolation::class)->everyFiveMinutes()->withoutOverlapping();

// A55: move every PHP site onto a pool of its own, and take out pools no site uses.
// A no-op broker call once every site is served by its own pool.
Schedule::command(ConvergeSitePhpPools::class)->everyFiveMinutes()->withoutOverlapping();

// A56: run every site-bound program (Octane, PM2, operator programs) as its own site.
Schedule::command(ConvergeProgramIdentity::class)->everyFiveMinutes()->withoutOverlapping();

// B8 / A41: scheduled git deploys (manual and schedule only, no webhooks).
Schedule::command(DeployScheduled::class)->everyFiveMinutes()->withoutOverlapping();

// KI-3: an EL panel runs a private php-fpm copy (SELinux, A3). Keep it on the distro's
// patched binary. A cmp and nothing else unless it has changed.
Schedule::command(RefreshPanelFpmBinary::class)->hourly()->withoutOverlapping();

Schedule::call(function () {
    app(\App\Services\Broker\BrokerClient::class)->call('terminal.session.cleanup', [], [], null, false);
})->everyMinute()->name('terminal-session-cleanup')->withoutOverlapping();
