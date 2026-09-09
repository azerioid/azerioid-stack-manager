<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ProcMetrics;
use AzerioidPanel\Broker\Runtime;

final class MariadbBindStatus
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $public = ProcMetrics::mariadbBindFromProcNet($runtime);
        $cnfValue = null;
        $path = $config->resolveMariadbServerCnf($runtime);
        if ($path !== null) {
            $cnf = $runtime->readFile($path);
            if (preg_match('/^\s*bind-address\s*=\s*(\S+)/mi', $cnf, $m)) {
                $cnfValue = $m[1];
            }
        }
        return [
            'listening_public' => $public,
            'bind_address_config' => $cnfValue,
            'config_path' => $path ?? $config->mariadbServerCnf,
        ];
    }
}
