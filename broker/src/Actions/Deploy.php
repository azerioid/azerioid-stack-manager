<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Deploy\GitDeploy;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

/**
 * Git deploy (B8, ADR A41/A53).
 *
 *  deploy.config         repository, branch, command, schedule, public key, history
 *  deploy.config.set     configure; a custom command needs RUN-AS-SITE; creates the key once
 *  deploy.key.rotate     a new deploy key
 *  deploy.remove         forget the configuration, the key and the mirror (not the site's files)
 *  deploy.list           every configured site with its schedule (the panel scheduler)
 *  deploy.run            fetch, check out as the site, post-deploy command, reload
 *  deploy.rollback       the previous commit, the same way
 */
final class Deploy
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $deploy = new GitDeploy($config, $runtime);
        if ($action === 'deploy.list') {
            return ['sites' => $deploy->listAll()];
        }
        // A78: the webhook is addressed by its public token, not a domain — it
        // is relayed from the unauthenticated /hooks/deploy/{token} route.
        if ($action === 'deploy.webhook') {
            return $deploy->webhook(
                (string) ($input['token'] ?? ''),
                (string) ($input['provider'] ?? 'github'),
                (string) ($input['signature'] ?? ''),
                (string) ($input['body'] ?? '')
            );
        }
        $domain = Validator::domain((string) ($args[0] ?? ($input['domain'] ?? '')));

        return match ($action) {
            'deploy.config' => $deploy->config($domain),
            'deploy.config.set' => $deploy->configure($domain, $input),
            'deploy.key.rotate' => $deploy->rotateKey($domain),
            'deploy.webhook.rotate' => $deploy->rotateWebhook($domain),
            'deploy.remove' => $deploy->remove($domain),
            'deploy.run' => $deploy->deploy($domain, $input),
            'deploy.rollback' => $deploy->rollback($domain),
            default => throw new BrokerException('Unknown deploy action.', 2),
        };
    }
}
