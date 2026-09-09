<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Network\SiteHttpFirewall;
use AzerioidPanel\Broker\Php\SitePhpTimeouts;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Web\VhostEngine;
use AzerioidPanel\Broker\Web\WebServers;

final class VhostAdd
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $domain = Validator::domain($args[0] ?? ($input['domain'] ?? ''));
        $root = Validator::webRoot((string) ($args[1] ?? ($input['root'] ?? '')), $config->wwwRoot, $runtime);
        $type = Validator::vhostType((string) ($args[2] ?? ($input['type'] ?? 'php')));

        $phpVersion = null;
        $upstream = null;
        if ($type === 'php') {
            $phpVersion = Validator::phpVersion((string) ($args[3] ?? ($input['php_version'] ?? '')), $runtime->phpVersions());
        }
        if ($type === 'proxy') {
            $upstream = Validator::localUpstream((string) ($args[3] ?? ($input['upstream'] ?? '')));
        }

        $blocked = array_map('strtolower', $config->readonlyVhosts);
        if (in_array($domain, $blocked, true) || $domain === 'default' || $domain === 'azerioid-panel') {
            throw new BrokerException("{$domain} is managed externally and can't be edited.", 3);
        }

        (new SiteHttpFirewall($runtime))->ensure();
        (new SitePhpTimeouts())->ensureSitePools($runtime, $config);

        $engine = Validator::vhostEngine((string) ($input['engine'] ?? VhostEngine::CADDY));
        if ($type === 'proxy') {
            $engine = VhostEngine::CADDY;
        }

        return WebServers::for($config)->addVhost($runtime, $config, [
            'domain' => $domain,
            'root' => $root,
            'type' => $type,
            'php_version' => $phpVersion,
            'upstream' => $upstream,
            'engine' => $engine,
        ]);
    }
}
