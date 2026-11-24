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
        $this->assertMatchesRegularExpression('/^shop-example-com-[0-9a-f]{6}:$/', $conn['prefix']);
        $p = $conn['prefix'];
        $line = array_values(array_filter(explode("\n", $this->rt->files[RedisAcl::ACL_FILE]), static fn (string $l): bool => str_starts_with($l, 'user az-vh-')))[0];
        $this->assertSame(
            'user az-vh-shop-example-com on #' . hash('sha256', $conn['password'])
            . " resetchannels ~{$p}* &{$p}* +@all -@admin -@dangerous -scan -randomkey -pubsub -script|flush -script|kill"
            . ' -client|tracking -client|caching +client|setname +client|getname +client|id -function -fcall -fcall_ro',
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
        $this->rt->files['/etc/redis/redis.conf'] = "bind 127.0.0.1\nprotected-mode yes\nport 6379\n";
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

    /** Review of A111: an instance that already required a password keeps requiring it. */
    public function test_an_existing_requirepass_stays_required_until_enforced(): void
    {
        $this->acl()->enableSite('shop.example.com');
        $this->assertStringContainsString('user default on #' . hash('sha256', 'oldsecret') . " ~* &* +@all\n", $this->rt->files[RedisAcl::ACL_FILE]);
        $this->assertStringNotContainsString('nopass', $this->rt->files[RedisAcl::ACL_FILE]);
    }

    public function test_users_or_an_acl_file_of_the_operators_own_are_not_replaced(): void
    {
        foreach (["user bob on >x ~* +@all\n", "aclfile /etc/redis/users.acl\n"] as $conf) {
            $this->rt->files['/etc/redis/redis.conf'] = $conf;
            try {
                $this->acl()->setup(true);
                $this->fail('expected refusal for ' . $conf);
            } catch (BrokerException $e) {
                $this->assertStringContainsString('will not replace', $e->getMessage());
            }
            $this->assertSame($conf, $this->rt->files['/etc/redis/redis.conf']);
        }
    }

    /** Review of A111: a site re-created under the same name does not get the old site's keys. */
    public function test_a_re_enabled_site_gets_a_new_prefix(): void
    {
        $this->acl()->setup(true);
        $first = $this->acl()->enableSite('shop.example.com')['prefix'];
        $this->acl()->disableSite('shop.example.com');
        $this->assertNotSame($first, $this->acl()->enableSite('shop.example.com')['prefix']);
    }

    /** Redis 6 knows no subcommand denies and no FUNCTION; one unknown rule invalidates the whole file. */
    public function test_redis_6_gets_no_rule_it_does_not_know(): void
    {
        $state = ['enforced' => true, 'admin_password' => 'x', 'redis_major' => 6,
            'sites' => ['a.test' => ['user' => 'az-vh-a-test', 'prefix' => 'a-test-abcdef:', 'password' => 'p']]];
        $six = RedisAcl::renderAcl($state);
        $this->assertStringNotContainsString('function', $six);
        $this->assertDoesNotMatchRegularExpression('/ -[a-z]+\|/', $six, 'no -cmd|sub deny on Redis 6');
        $this->assertStringContainsString('-script -client +script|load +script|exists +client|setname', $six);
        $state['redis_major'] = 7;
        $this->assertStringContainsString('-function -fcall -fcall_ro', RedisAcl::renderAcl($state));
    }

    /** A file Redis rejects is not left on disk, and the change is not recorded. */
    public function test_a_rejected_acl_load_keeps_the_file_and_the_state_as_they_were(): void
    {
        $this->acl()->setup(true);
        $this->acl()->enableSite('shop.example.com');
        $file = $this->rt->files[RedisAcl::ACL_FILE];
        $state = $this->rt->files[RedisAcl::STATE];
        $this->rt->execFn = static fn (array $c): ?ExecResult => ($c[0] ?? '') === '/usr/bin/redis-cli' && in_array('LOAD', $c, true)
            ? new ExecResult($c, 0, "ERR Error in ACL SETUSER modifier\n", '')
            : null;
        try {
            $this->acl()->disableSite('shop.example.com');
            $this->fail('expected failure');
        } catch (BrokerException) {
        }
        $this->assertSame($file, $this->rt->files[RedisAcl::ACL_FILE]);
        $this->assertSame($state, $this->rt->files[RedisAcl::STATE], 'the site stays recorded for a retry');
    }

    public function test_wiring_is_line_by_line_and_idempotent(): void
    {
        $once = RedisAcl::wireAclFile("port 6379\naclfile /etc/redis/azerioid-users.acl\nrequirepass x\n");
        $this->assertSame("port 6379\naclfile " . RedisAcl::ACL_FILE . "\n# requirepass x  # azerioid-panel A111: users live in " . RedisAcl::ACL_FILE . "\n", $once);
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
