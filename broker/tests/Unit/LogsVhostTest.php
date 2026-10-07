<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * A82: per-vhost access log — path derived from a validated domain, tail and search.
 */
final class LogsVhostTest extends TestCase
{
    private FakeRuntime $rt;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->kernel = new Kernel(new Config(), $this->rt);
        $this->rt->files['/var/log/caddy/access_shop.test.log'] = "GET / 200\nGET /health 200\nGET /admin 403\n";
        $this->rt->execFn = function (array $c): ?ExecResult {
            if (($c[0] ?? '') === '/usr/bin/tail') {
                $path = (string) end($c);

                return new ExecResult($c, 0, $this->rt->files[$path] ?? '', '');
            }
            if (($c[0] ?? '') === '/usr/bin/grep') {
                return new ExecResult($c, 0, "3:GET /admin 403\n", '');
            }

            return null;
        };
    }

    /** @return array{0:int,1:array} */
    private function kernelRun(array $argv, array $input = []): array
    {
        ob_start();
        $code = $this->kernel->run($argv, $input);

        return [$code, json_decode(trim((string) ob_get_clean()), true) ?: []];
    }

    public function test_tails_the_access_log_for_a_domain(): void
    {
        [$code, $json] = $this->kernelRun(['broker', 'logs.vhost', 'shop.test']);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertSame('/var/log/caddy/access_shop.test.log', $json['data']['path']);
        $this->assertFalse($json['data']['missing']);
        $this->assertContains('GET /health 200', $json['data']['lines']);
    }

    public function test_searches_the_access_log(): void
    {
        [$code, $json] = $this->kernelRun(['broker', 'logs.vhost', 'shop.test'], ['needle' => 'admin']);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertSame('admin', $json['data']['needle']);
        $this->assertSame(['3:GET /admin 403'], $json['data']['lines']);
    }

    public function test_missing_log_is_reported_not_an_error(): void
    {
        [$code, $json] = $this->kernelRun(['broker', 'logs.vhost', 'absent.test']);
        $this->assertSame(0, $code);
        $this->assertTrue($json['data']['missing']);
        $this->assertSame([], $json['data']['lines']);
    }

    public function test_only_the_access_type_is_allowed(): void
    {
        [$code, $json] = $this->kernelRun(['broker', 'logs.vhost', 'shop.test', 'app']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('access log', strtolower((string) ($json['error'] ?? '')));
    }

    public function test_a_bad_domain_is_rejected(): void
    {
        [$code] = $this->kernelRun(['broker', 'logs.vhost', '../etc/passwd']);
        $this->assertNotSame(0, $code);
    }
}
