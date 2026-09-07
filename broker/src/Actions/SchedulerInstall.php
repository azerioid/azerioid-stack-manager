<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

final class SchedulerInstall
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $artisan = $config->artisanPath;
        $underPanel = $runtime->resolveUnderBase($artisan, $config->panelRoot);
        $underWww = $runtime->resolveUnderBase($artisan, $config->wwwRoot);
        if (($underPanel === null && $underWww === null) || !$runtime->fileExists($artisan)) {
            throw new BrokerException('Artisan path is missing or outside the panel/www root.', 3);
        }
        $user = $config->webUser;
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user)) {
            throw new BrokerException('Invalid web user.', 2);
        }
        $body = "# AZERIOID Stack Manager — Laravel scheduler (idempotent)\n"
            . "SHELL=/bin/sh\n"
            . "PATH=/usr/sbin:/usr/bin:/sbin:/bin\n"
            . "* * * * * {$user} /usr/bin/php {$artisan} schedule:run >/dev/null 2>&1\n";
        $runtime->writeFile($config->cronDPath, $body, 0644);
        return [
            'path' => $config->cronDPath,
            'artisan' => $artisan,
            'user' => $user,
        ];
    }
}
