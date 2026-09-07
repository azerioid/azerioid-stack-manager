<?php

namespace App\Console\Commands\Azerioid;

use Illuminate\Console\Command;

class ServiceCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:service
        {name : systemd unit name (e.g. redis-server, mariadb)}
        {action : start|stop|restart|status}
        {--json : JSON output (status)}';

    protected $description = 'Control a single systemd service via broker service.* actions';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $action = strtolower(trim((string) $this->argument('action')));
        if ($name === '' || ! in_array($action, ['start', 'stop', 'restart', 'status'], true)) {
            $this->error('Usage: azerioid service <name> start|stop|restart|status');

            return self::INVALID;
        }

        try {
            if ($action === 'status') {
                $data = $this->brokerData('service.status', [$name], [], null, false);
                if ($this->wantsJson()) {
                    return $this->emitData($data);
                }
                foreach ($data as $k => $v) {
                    if (is_scalar($v) || $v === null) {
                        $this->line(sprintf('%s: %s', $k, $v === null ? '' : $v));
                    }
                }

                return self::SUCCESS;
            }

            $brokerAction = 'service.' . $action;
            $res = $this->brokerCall($brokerAction, [$name]);
            if (! $res->ok) {
                throw new \RuntimeException((string) $res->error);
            }
            $this->info(ucfirst($action) . " issued for {$name}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }
}
