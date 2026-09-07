<?php

namespace App\Console\Commands\Azerioid;

use Illuminate\Console\Command;

class AuditCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:audit
        {action=tail : tail}
        {--lines=50 : Number of lines}
        {--follow : Follow new entries}
        {--json : JSON output}';

    protected $description = 'Tail the broker audit log (panel-audit)';

    public function handle(): int
    {
        if ($this->argument('action') !== 'tail') {
            $this->error('Unknown audit action. Use: tail');

            return self::INVALID;
        }

        try {
            $lines = max(1, (int) $this->option('lines'));
            $follow = (bool) $this->option('follow');
            $seen = 0;

            do {
                $data = $this->brokerData('logs.tail', ['panel-audit', (string) $lines], [], null, false);
                $body = $data['lines'] ?? [];
                if ($this->wantsJson() && ! $follow) {
                    return $this->emitData($data);
                }
                if ($follow) {
                    $slice = array_slice($body, $seen);
                    $seen = count($body);
                    foreach ($slice as $line) {
                        $this->line((string) $line);
                    }
                    sleep(2);
                    // grow window slowly so we don't miss entries
                    $lines = min(500, $lines + 10);
                } else {
                    foreach ($body as $line) {
                        $this->line((string) $line);
                    }

                    return self::SUCCESS;
                }
            } while ($follow);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }
}
