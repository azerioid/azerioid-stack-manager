<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Component\MongoProvisioner;
use AzerioidPanel\Broker\Component\OperationLogger;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\DatabaseProvisioner;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * A109: an engine's panel admin password never reaches a child's argv
 * (/proc/<pid>/cmdline is readable by every local account, sites included).
 */
final class DatabaseProvisionerArgvTest extends TestCase
{
    private const BROKER_JSON = '/etc/azerioid-panel/broker.json';

    private function runtime(): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/os-release'] = "ID=ubuntu\nVERSION_ID=\"24.04\"\nVERSION_CODENAME=noble\n";
        $rt->files['/usr/sbin/runuser'] = '';
        $rt->files['/etc/mongod.conf'] = "net:\n  port: 27017\n  bindIp: 127.0.0.1\n";
        $rt->execFn = static function (array $c, ?string $stdin): ?ExecResult {
            if (($c[0] ?? '') === '/usr/bin/mongosh') {
                // Authenticated scripts and the admin create succeed; the anonymous
                // listDatabases probe is refused, as with authorization on.
                if (in_array('--eval', $c, true)) {
                    return new ExecResult($c, 1, '', 'MongoServerError: requires authentication');
                }

                return str_contains((string) $stdin, 'system.users')
                    ? new ExecResult($c, 0, (str_contains((string) $stdin, '.auth(') ? '["azerioid_panel_admin"]' : '[]') . "\n", '')
                    : new ExecResult($c, 0, "{ ok: 1 }\n", '');
            }

            return null;
        };

        return $rt;
    }

    private function password(FakeRuntime $rt, string ...$path): string
    {
        $json = json_decode($rt->files[self::BROKER_JSON] ?? '{}', true);
        foreach ($path as $key) {
            $json = $json[$key] ?? null;
        }
        $this->assertIsString($json, 'the generated password was written to broker.json');

        return $json;
    }

    private function assertNotOnArgv(FakeRuntime $rt, string $secret): void
    {
        $this->assertGreaterThan(8, strlen($secret));
        $carried = false;
        foreach ($rt->execLog as $e) {
            foreach ($e['command'] as $arg) {
                $this->assertStringNotContainsString($secret, (string) $arg, 'secret on argv: ' . implode(' ', $e['command']));
            }
            $carried = $carried || str_contains((string) $e['stdin'], $secret) || in_array($secret, (array) $e['env'], true);
        }
        $this->assertTrue($carried, 'the secret still reaches the client, on stdin or in its environment');
    }

    public function test_mariadb_admin_password_goes_in_on_stdin(): void
    {
        $rt = $this->runtime();
        (new DatabaseProvisioner(new Config(), $rt))->adopt('mariadb');
        $this->assertNotOnArgv($rt, $this->password($rt, 'database', 'mariadb', 'password'));
    }

    public function test_postgresql_admin_password_goes_in_on_stdin(): void
    {
        $rt = $this->runtime();
        (new DatabaseProvisioner(new Config(), $rt))->adopt('postgresql');
        $this->assertNotOnArgv($rt, $this->password($rt, 'database', 'postgresql', 'password'));
        $psql = array_values(array_filter(array_column($rt->execLog, 'command'), static fn (array $c): bool => in_array('/usr/bin/psql', $c, true) && in_array('-f', $c, true)));
        $this->assertNotSame([], $psql, 'the role script is read from stdin (-f -)');
    }

    public function test_mongodb_admin_password_goes_in_through_the_environment(): void
    {
        $rt = $this->runtime();
        (new MongoProvisioner(new Config(), $rt))->provision(new OperationLogger($rt, '/tmp/op.log'));
        $secret = $this->password($rt, 'mongodb', 'password');
        $this->assertNotOnArgv($rt, $secret);
        foreach ($rt->execLog as $e) {
            $this->assertStringNotContainsString($secret, (string) $e['stdin'], 'not even as a script literal');
        }
    }
}
