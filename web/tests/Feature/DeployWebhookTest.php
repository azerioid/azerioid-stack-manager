<?php

namespace Tests\Feature;

use App\Services\Broker\FakeBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A78: the unauthenticated push-to-deploy webhook relay. The HMAC is verified in
 * the broker (FakeBroker models it: it accepts a GitHub signature over the fake
 * secret); these assert the controller's relay behaviour and status codes.
 */
class DeployWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'abcdef0123456789abcdef0123456789'; // 32 hex

    private function hook(string $token, string $body, array $server = [])
    {
        return $this->call('POST', '/hooks/deploy/'.$token, [], [], [], $server, $body);
    }

    public function test_a_valid_github_signature_queues_a_deploy(): void
    {
        app(FakeBroker::class);
        $body = '{"ref":"refs/heads/main"}';
        $sig = 'sha256='.hash_hmac('sha256', $body, str_repeat('b', 64));

        $this->hook(self::TOKEN, $body, ['HTTP_X_HUB_SIGNATURE_256' => $sig, 'CONTENT_TYPE' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $this->hook(self::TOKEN, '{"ref":"refs/heads/main"}', ['HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef'])
            ->assertStatus(401);
    }

    public function test_a_malformed_token_is_not_found(): void
    {
        $this->hook('NOT-HEX', '{}')->assertStatus(404);
    }

    public function test_an_oversized_body_is_refused_before_the_broker(): void
    {
        $this->hook(self::TOKEN, str_repeat('a', 1048577), ['HTTP_X_HUB_SIGNATURE_256' => 'sha256=x'])
            ->assertStatus(413);
    }
}
