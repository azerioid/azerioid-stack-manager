<?php

namespace Tests\Feature;

use App\Livewire\RedisPage;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

/** A111 — the Redis page: per-site users, their .env lines, and enforcing. */
class RedisPageTest extends TestCase
{
    use RefreshDatabase;

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        $user = User::factory()->create([
            'two_factor_secret' => Crypt::encryptString((new TotpService())->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);

        return Livewire::actingAs($user)->test(RedisPage::class);
    }

    private function fake(): FakeBroker
    {
        return app(FakeBroker::class);
    }

    private function domain(): string
    {
        return (string) ($this->fake()->vhosts[0]['domain'] ?? '');
    }

    public function test_an_open_instance_says_every_site_can_read_every_other(): void
    {
        $this->page()->assertSee('Redis still answers clients without a password')->assertSee('REDIS-PER-SITE');
    }

    public function test_enabling_a_site_shows_its_env_lines_once_created(): void
    {
        $domain = $this->domain();
        $page = $this->page()->call('enable', $domain)->assertSet('error', null);

        $this->assertArrayHasKey($domain, $this->fake()->redisSites);
        $site = $this->fake()->redisSites[$domain];
        $page->assertSee('REDIS_USERNAME=' . $site['user'])
            ->assertSee('REDIS_PASSWORD=' . $site['password'])
            ->assertSee('REDIS_PREFIX=' . $site['prefix']);
    }

    public function test_disabling_removes_the_user_and_hides_its_password(): void
    {
        $domain = $this->domain();
        $page = $this->page()->call('enable', $domain);
        $password = $this->fake()->redisSites[$domain]['password'];

        $page->call('disable', $domain)->assertDontSee($password);
        $this->assertArrayNotHasKey($domain, $this->fake()->redisSites);
    }

    public function test_enforcing_needs_the_typed_confirmation(): void
    {
        $this->page()->set('confirm', 'yes')->call('enforce')->assertSet('flash', null);
        $this->assertFalse($this->fake()->redisEnforced);

        $this->page()->set('confirm', 'REDIS-PER-SITE')->call('enforce')->assertSet('error', null);
        $this->assertTrue($this->fake()->redisEnforced);
    }

    public function test_the_password_is_not_stored_in_the_audit_log(): void
    {
        $domain = $this->domain();
        $this->page()->call('enable', $domain);
        $password = $this->fake()->redisSites[$domain]['password'];

        foreach (\App\Models\AuditLog::query()->get() as $row) {
            $this->assertStringNotContainsString($password, json_encode($row->toArray()));
        }
    }
}
