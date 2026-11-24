<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Redis\RedisAcl;
use PHPUnit\Framework\TestCase;

/** A111 — per-site Redis users. */
final class RedisAclTest extends TestCase
{
    private const CONF = "bind 127.0.0.1\nprotected-mode yes\n# requirepass foobared\nrequirepass oldsecret\nport 6379\n";

    private FakeRuntime $rt;

    private Config $cfg;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->rt->files['/etc/os-release'] = "ID=ubuntu\nVERSION_ID=\"24.04\"\nVERSION_CODENAME=noble\n";
        $this->rt->files['/etc/redis/redis.conf'] = self::CONF;
        $this->rt->files['/etc/caddy/Caddyfile'] = "import /etc/caddy/conf.d/*.conf\n";
        $this->rt->files['/etc/caddy/conf.d/shop.example.com.conf'] = "shop.example.com {\n    root * /data/www/shop.example.com/public\n    file_server\n}\n";
        $this->rt->execFn = static function (array $c): ?ExecResult {
            if (($c[0] ?? '') === '/usr/bin/redis-cli') {
                $authed = in_array('--user', $c, true);

                return match (true) {
                    in_array('PING', $c, true) => new ExecResult($c, 0, $authed ? "PONG\n" : "NOAUTH Authentication required.\n", ''),
                    in_array('LOAD', $c, true) => new ExecResult($c, 0, "OK\n", ''),
                    default => null,
                };
            }

            return null;
        };
    }

    private function acl(): RedisAcl
    {
        return new RedisAcl($this->rt, $this->cfg);
    }

    private function state(): array
    {
        return json_decode($this->rt->files[RedisAcl::STATE], true);
    }

    public function test_a_new_install_allows_per_site_users_only(): void
    {
        $this->acl()->setup(true);

        $file = $this->rt->files[RedisAcl::ACL_FILE];
        $this->assertStringContainsString("user default off\n", $file);
        $this->assertStringContainsString('user azerioid-admin on #' . hash('sha256', $this->state()['admin_password']), $file);
        $this->assertStringNotContainsString($this->state()['admin_password'], $file, 'hashes only');
        $conf = $this->rt->files['/etc/redis/redis.conf'];
        $this->assertStringContainsString("\naclfile " . RedisAcl::ACL_FILE . "\n", $conf);
        $this->assertStringContainsString('# requirepass oldsecret', $conf, 'a requirepass would conflict with the ACL file');
        $this->assertSame(0600, $this->rt->modes[RedisAcl::STATE] ?? null);
        $this->assertContains(['/usr/bin/chown', 'root:redis', RedisAcl::ACL_FILE], array_column($this->rt->execLog, 'command'));
    }

    public function test_a_site_user_reaches_only_its_own_prefix_and_no_instance_commands(): void
    {
        $this->acl()->setup(true);
        $conn = $this->acl()->enableSite('shop.example.com');

        $this->assertSame('az-vh-shop-example-com', $conn['user']);
        $this->assertSame('shop-example-com:', $conn['prefix']);
        $line = array_values(array_filter(explode("\n", $this->rt->files[RedisAcl::ACL_FILE]), static fn (string $l): bool => str_starts_with($l, 'user az-vh-')))[0];
        $this->assertSame(
            'user az-vh-shop-example-com on #' . hash('sha256', $conn['password'])
            . ' resetchannels ~shop-example-com:* &shop-example-com:* +@all -@admin -@dangerous -scan -randomkey -pubsub +client|setname +client|getname +client|id',
            $line
        );
        $this->assertSame($conn, $this->acl()->showSite('shop.example.com'));
    }

    public function test_the_admin_password_never_reaches_argv(): void
    {
        $this->acl()->setup(true);
        $this->acl()->enableSite('shop.example.com');
        $secret = $this->state()['admin_password'];
        foreach ($this->rt->execLog as $e) {
            $this->assertStringNotContainsString($secret, implode(' ', $e['command']));
        }
        $loads = array_values(array_filter($this->rt->execLog, static fn (array $e): bool => in_array('LOAD', $e['command'], true)));
        $this->assertSame(['REDISCLI_AUTH' => $secret], $loads[0]['env']);
    }

    public function test_an_existing_instance_stays_open_until_enforced(): void
    {
        $this->acl()->enableSite('shop.example.com');
        $this->assertStringContainsString("user default on nopass ~* &* +@all\n", $this->rt->files[RedisAcl::ACL_FILE]);
        $this->assertFalse($this->acl()->status()['enforced']);

        try {
            $this->acl()->enforce('yes');
            $this->fail('typed confirm required');
        } catch (BrokerException) {
        }
        $status = $this->acl()->enforce(RedisAcl::CONFIRM_ENFORCE);
        $this->assertTrue($status['enforced']);
        $this->assertStringContainsString("user default off\n", $this->rt->files[RedisAcl::ACL_FILE]);
    }

    public function test_enforcing_fails_if_redis_still_answers_without_credentials(): void
    {
        $this->rt->execFn = static fn (array $c): ?ExecResult => ($c[0] ?? '') === '/usr/bin/redis-cli'
            ? new ExecResult($c, 0, in_array('LOAD', $c, true) ? "OK\n" : "PONG\n", '')
            : null;
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessage('still answers a client without credentials');
        $this->acl()->enforce(RedisAcl::CONFIRM_ENFORCE);
    }

    public function test_redis_conf_is_put_back_when_redis_will_not_start_with_the_acl_file(): void
    {
        $this->rt->execFn = static fn (array $c): ?ExecResult => ($c[0] ?? '') === '/usr/bin/redis-cli'
            ? new ExecResult($c, 1, '', 'Connection refused')
            : null;
        try {
            $this->acl()->setup(true);
            $this->fail('expected failure');
        } catch (BrokerException) {
        }
        $this->assertSame(self::CONF, $this->rt->files['/etc/redis/redis.conf']);
    }

    public function test_only_vhosts_get_users_and_deleting_a_vhost_removes_its_user(): void
    {
        $this->acl()->setup(true);
        try {
            $this->acl()->enableSite('nope.example.com');
            $this->fail('expected refusal');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('No vhost', $e->getMessage());
        }
        $this->acl()->enableSite('shop.example.com');
        $this->assertSame(['domain' => 'shop.example.com', 'removed' => true], $this->acl()->disableSite('shop.example.com'));
        $this->assertStringNotContainsString('az-vh-shop-example-com', $this->rt->files[RedisAcl::ACL_FILE]);
    }

    public function test_wiring_is_line_by_line_and_idempotent(): void
    {
        $once = RedisAcl::wireAclFile("port 6379\naclfile /etc/redis/users.acl\nuser bob on >x ~* +@all\n");
        $this->assertSame("port 6379\naclfile " . RedisAcl::ACL_FILE . "\n# user bob on >x ~* +@all  # azerioid-panel A111: users live in " . RedisAcl::ACL_FILE . "\n", $once);
        $this->assertSame($once, RedisAcl::wireAclFile($once));
    }

    public function test_kernel_exposes_the_actions(): void
    {
        ob_start();
        $code = (new Kernel($this->cfg, $this->rt))->run(['broker', 'redis.status'], []);
        $json = json_decode((string) ob_get_clean(), true);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertTrue($json['data']['installed']);
    }
}
