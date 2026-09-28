<?php

namespace App\Console\Commands;

use App\Services\Broker\BrokerClient;
use App\Services\OperationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Scheduled git deploys (B8, A41: manual and schedule, no webhooks). Queues a deploy for each
 * site whose schedule is due; the broker skips it when the branch has not moved.
 */
class DeployScheduled extends Command
{
    protected $signature = 'azerioid:deploy-scheduled';

    protected $description = 'Queue git deploys whose schedule is due';

    public function handle(BrokerClient $broker, OperationDispatcher $operations): int
    {
        $res = $broker->call('deploy.list', [], [], 60, false);
        if (! $res->ok) {
            $this->error('deploy.list: '.$res->error);

            return self::FAILURE;
        }
        foreach ((array) ($res->data['sites'] ?? []) as $site) {
            if (! self::due((string) $site['schedule'], $site['last_deploy_at'] ?? null, now())) {
                continue;
            }
            $operations->dispatch('deploy.run', [$site['domain']], ['domain' => $site['domain'], 'trigger' => 'schedule']);
            $this->line('Queued deploy of '.$site['domain'].'.');
        }

        return self::SUCCESS;
    }

    public static function due(string $schedule, ?string $lastDeployAt, Carbon $now): bool
    {
        $last = $lastDeployAt !== null ? Carbon::parse($lastDeployAt) : null;
        if ($schedule === 'hourly') {
            return $last === null || $last->lt($now->copy()->subMinutes(55));
        }
        if (preg_match('/^daily@(\d{1,2})$/', $schedule, $m) === 1) {
            return (int) $now->format('G') === (int) $m[1] && ($last === null || ! $last->isSameDay($now));
        }

        return false;
    }
}
