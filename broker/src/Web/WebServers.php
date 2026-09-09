<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Web;

use AzerioidPanel\Broker\Config;

final class WebServers
{
    public static function for(Config $config): WebServerDriver
    {
        return new VhostFrontRouter();
    }
}
