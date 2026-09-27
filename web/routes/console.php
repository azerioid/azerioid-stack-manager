<?php

use App\Console\Commands\ConvergePanelIdentity;
use App\Console\Commands\EvaluateAlerts;
use App\Console\Commands\PanelMaintenance;
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

Schedule::call(function () {
    app(\App\Services\Broker\BrokerClient::class)->call('terminal.session.cleanup', [], [], null, false);
})->everyMinute()->name('terminal-session-cleanup')->withoutOverlapping();
