<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Component\MongoProvisioner;
use AzerioidPanel\Broker\Component\OperationLogger;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use PHPUnit\Framework\TestCase;

/** A110: provisioning or adopting MongoDB never runs it with authorization off on loopback TCP. */
final class MongoProvisionerTest extends TestCase
{
    private const CONF = '/etc/mongod.conf';

    /** @var list<string> mongod.conf as it was at every restart */
    private array $atRestart = [];

    /**
     * @param  list<string>  $usersBefore  users an unauthenticated listing sees (auth off)
     * @param  list<string>  $usersAfter   users the admin's listing sees at the end
     */
    private function runtime(string $conf, bool $exceptionOpen, array $usersBefore = [], array $usersAfter = ['azerioid_panel_admin']): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/os-release'] = "ID=ubuntu\nVERSION_ID=\"24.04\"\nVERSION_CODENAME=noble\n";
        $rt->files[self::CONF] = $conf;
        $rt->execFn = function (array $c, ?string $stdin) use ($rt, $exceptionOpen, $usersBefore, $usersAfter): ?ExecResult {
            if ($c === ['/usr/bin/systemctl', 'restart', 'mongod']) {
                $this->atRestart[] = $rt->files[self::CONF];

                return new ExecResult($c, 0, '', '');
            }
            if (($c[0] ?? '') !== '/usr/bin/mongosh') {
                return null;
            }
            if (in_array('--eval', $c, true)) {
                return new ExecResult($c, 1, '', 'MongoServerError: requires authentication');
            }
            $authOff = str_contains(end($this->atRestart) ?: '', 'authorization: disabled');
            $stdin = (string) $stdin;
            if (str_contains($stdin, 'system.users')) {
                return new ExecResult($c, 0, json_encode(str_contains($stdin, '.auth(') ? $usersAfter : $usersBefore) . "\n", '');
            }
            if (str_contains($stdin, 'createUser') && !$exceptionOpen && !$authOff) {
                return new ExecResult($c, 1, '', 'MongoServerError: command createUser requires authentication');
            }

            return new ExecResult($c, 0, "{ ok: 1 }\n", '');
        };

        return $rt;
    }

    private function provision(FakeRuntime $rt): void
    {
        (new MongoProvisioner(new Config(), $rt))->provision(new OperationLogger($rt, '/tmp/op.log'));
    }

    public function test_a_fresh_instance_is_never_started_with_authorization_off(): void
    {
        $rt = $this->runtime("net:\n  port: 27017\n  bindIp: 0.0.0.0\n", exceptionOpen: true);
        $this->provision($rt);

        $this->assertNotSame([], $this->atRestart);
        foreach ($this->atRestart as $conf) {
            $this->assertStringNotContainsString('authorization: disabled', $conf);
            $this->assertStringContainsString('authorization: enabled', $conf);
            $this->assertStringContainsString('bindIp: 127.0.0.1', $conf);
        }
        $this->assertArrayHasKey('/etc/azerioid-panel/broker.json', $rt->files);
    }

    public function test_an_instance_that_enforces_authorization_is_not_opened_to_adopt_it(): void
    {
        $rt = $this->runtime("net:\n  bindIp: 127.0.0.1\nsecurity:\n  authorization: enabled\n", exceptionOpen: false);
        try {
            $this->provision($rt);
            $this->fail('expected refusal');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('does not switch authorization off', $e->getMessage());
        }
        foreach ($this->atRestart as $conf) {
            $this->assertStringNotContainsString('authorization: disabled', $conf);
        }
        $this->assertStringNotContainsString('authorization: disabled', $rt->files[self::CONF]);
        $this->assertArrayNotHasKey('/etc/azerioid-panel/broker.json', $rt->files);
    }

    public function test_a_user_nobody_expected_fails_the_provisioning(): void
    {
        $rt = $this->runtime("net:\n  bindIp: 127.0.0.1\n", exceptionOpen: true, usersAfter: ['azerioid_panel_admin', 'intruder']);
        try {
            $this->provision($rt);
            $this->fail('expected refusal');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('intruder', $e->getMessage());
        }
        $this->assertArrayNotHasKey('/etc/azerioid-panel/broker.json', $rt->files);
    }

    public function test_an_already_open_instance_with_users_keeps_them_and_ends_enforced(): void
    {
        $rt = $this->runtime("net:\n  bindIp: 127.0.0.1\n", exceptionOpen: false, usersBefore: ['app@shop'], usersAfter: ['app@shop', 'azerioid_panel_admin']);
        $this->provision($rt);
        $this->assertStringContainsString('authorization: enabled', $rt->files[self::CONF]);
        $this->assertStringContainsString('authorization: enabled', $this->atRestart[0], 'closed before anything else');
        $this->assertStringContainsString('authorization: enabled', end($this->atRestart));
    }
}
