<?php

namespace App\Http\Controllers;

use App\Services\Broker\BrokerClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A78: push-to-deploy. GitHub/GitLab POST here when a repo is pushed. This route
 * is UNAUTHENTICATED (a webhook has no panel session) and is gated instead by an
 * HMAC signature the broker verifies against the site's stored secret — the
 * secret never reaches this layer. The controller is a thin relay: cap the body,
 * read the signature header, hand {token, provider, signature, body} to the
 * broker, and translate its verdict to a status code. It leaks nothing about
 * whether the token matched a site.
 */
class DeployWebhookController
{
    private const MAX_BODY_BYTES = 1048576; // 1 MiB — push payloads are a few KB

    public function __invoke(Request $request, string $token, BrokerClient $broker): Response
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return response('not found', 404);
        }

        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return response('payload too large', 413);
        }

        $gitlabToken = (string) $request->header('X-Gitlab-Token', '');
        if ($gitlabToken !== '') {
            $provider = 'gitlab';
            $signature = $gitlabToken;
        } else {
            $provider = 'github';
            $signature = (string) $request->header('X-Hub-Signature-256', '');
        }

        $res = $broker->call('deploy.webhook', [], [
            'token' => $token,
            'provider' => $provider,
            'signature' => $signature,
            'body' => $body,
        ], 30, audit: false);

        if (! $res->ok) {
            // Generic — never reveal whether the token or the signature failed.
            return response('rejected', 401);
        }

        $data = $res->data ?? [];
        if (($data['accepted'] ?? false) === true) {
            return response()->json(['status' => 'queued', 'domain' => $data['domain'] ?? null], 202);
        }

        // Valid signature but the pushed branch is not the deploy branch.
        return response()->json(['status' => 'ignored', 'reason' => $data['reason'] ?? 'no match'], 200);
    }
}
