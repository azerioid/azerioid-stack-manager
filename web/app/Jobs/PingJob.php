<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::info('AZERIOID Stack Manager PingJob processed — queue worker is healthy.');
    }
}
