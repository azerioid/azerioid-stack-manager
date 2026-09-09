<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\MongoDriver;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

final class MongoDriverTest extends TestCase
{
    public function test_add_sends_create_collection_and_dbowner_user(): void
    {
        $driver = $this->driver();
        $result = $driver->add('appdata', 'appuser', 'abcdefghijklmnopqrst');
        $this->assertSame('appdata', $result['name']);
        $this->assertSame('appuser', $result['user']);
        $this->assertSame(['auth'], $result['hosts']);

        $eval = $this->lastEval($this->runtime);
        $this->assertStringContainsString('createCollection', $eval);
        $this->assertStringContainsString('createUser', $eval);
        $this->assertStringContainsString('dbOwner', $eval);
        $this->assertStringContainsString('"appdata"', $eval);
        $this->assertStringContainsString('"appuser"', $eval);
    }

    public function test_add_refuses_protected_and_panel_admin(): void
    {
        $driver = $this->driver();
        try {
            $driver->add('admin', 'appuser', 'abcdefghijklmnopqrst');
            $this->fail('expected protected database refusal');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('protected', $e->getMessage());
        }

        try {
            $driver->add('appdata', 'azerioid_panel_admin', 'abcdefghijklmnopqrst');
            $this->fail('expected panel admin refusal');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('panel MongoDB admin', $e->getMessage());
        }
    }

    public function test_delete_and_reset_target_tenant_user(): void
    {
        $driver = $this->driver();
        $driver->delete('appdata', 'appuser');
        $this->assertStringContainsString('dropDatabase', $this->lastEval($this->runtime));

        $driver->resetPassword('appuser', 'abcdefghijklmnopqrst');
        $eval = $this->lastEval($this->runtime);
        $this->assertStringContainsString('getUsers', $eval);
        $this->assertStringContainsString('updateUser', $eval);
    }

    public function test_kernel_mongo_add(): void
    {
        $cfg = $this->config();
        [$code, $json] = $this->capture(new Kernel($cfg, new FakeRuntime()), ['broker', 'db.add', 'appdata', 'appuser'], [
            'engine' => 'mongodb',
            'password' => 'abcdefghijklmnopqrst',
        ]);
        $this->assertSame(0, $code, (string) ($json['error'] ?? ''));
        $this->assertSame('appdata', $json['data']['name'] ?? null);
        $this->assertSame('mongodb', $json['data']['engine'] ?? null);
    }

    private FakeRuntime $runtime;

    private function driver(): MongoDriver
    {
        $this->runtime = new FakeRuntime();

        return new MongoDriver($this->config(), $this->runtime);
    }

    private function config(): Config
    {
        $cfg = new Config();
        $cfg->mongodbUser = 'azerioid_panel_admin';
        $cfg->mongodbPassword = 'abcdefghijklmnopqrst';

        return $cfg;
    }

    private function lastEval(FakeRuntime $rt): string
    {
        $this->assertNotEmpty($rt->execLog);
        $cmd = $rt->execLog[array_key_last($rt->execLog)]['command'];
        $i = array_search('--eval', $cmd, true);
        $this->assertNotFalse($i);

        return (string) $cmd[$i + 1];
    }

    /** @return array{0:int,1:array} */
    private function capture(Kernel $kernel, array $argv, array $stdin = []): array
    {
        ob_start();
        $code = $kernel->run($argv, $stdin);
        $out = ob_get_clean();
        $json = json_decode(trim((string) $out), true);

        return [$code, is_array($json) ? $json : []];
    }
}
