<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Redis\RedisAcl;
use AzerioidPanel\Broker\Runtime;

/**
 * Per-site Redis users (A111). Every guarantee lives in RedisAcl.
 */
final class RedisManage
{
    /**
     * @param  list<string>  $args
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $acl = new RedisAcl($runtime, $config);
        $domain = (string) ($args[0] ?? $input['domain'] ?? '');

        return match ($action) {
            'redis.status' => $acl->status(),
            'redis.site.enable' => $acl->enableSite($domain),
            'redis.site.show' => $acl->showSite($domain),
            'redis.site.disable' => $acl->disableSite($domain),
            'redis.enforce' => $acl->enforce((string) ($input['confirm'] ?? '')),
            default => throw new BrokerException('Unsupported redis action: ' . $action, 2),
        };
    }
}
