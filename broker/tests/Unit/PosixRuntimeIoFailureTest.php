<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\PosixRuntime;
use PHPUnit\Framework\TestCase;

final class PosixRuntimeIoFailureTest extends TestCase
{
    public function test_erofs_message_points_at_fpm_sandbox(): void
    {
        $msg = PosixRuntime::describeIoFailure('write', '/etc/caddy/conf.d/abc.com.conf.lacmp-tmp', [
            'message' => 'file_put_contents(/etc/caddy/conf.d/abc.com.conf.lacmp-tmp): Failed to open stream: Read-only file system',
        ]);
        $this->assertStringContainsString('read-only for the broker context', $msg);
        $this->assertStringContainsString('ProtectSystem', $msg);
        $this->assertStringContainsString('ReadWritePaths', $msg);
    }

    public function test_child_env_has_home_and_path(): void
    {
        $method = new \ReflectionMethod(PosixRuntime::class, 'childEnv');
        $env = $method->invoke(null);
        $this->assertArrayHasKey('PATH', $env);
        $this->assertArrayHasKey('XDG_CONFIG_HOME', $env);
        // A47: HOME for root children is always the root-owned broker home,
        // never an inherited or lower-trust-writable directory such as
        // /var/lib/caddy or sudo's /root.
        $this->assertSame('/var/lib/azerioid-broker', $env['HOME']);
        $this->assertStringStartsWith('/var/lib/azerioid-broker', $env['XDG_CONFIG_HOME']);
        $this->assertStringStartsWith('/var/lib/azerioid-broker', $env['XDG_DATA_HOME']);
        // Per-user tool config is neutralised so root git/curl/gpg cannot load
        // attacker-writable config.
        $this->assertSame('/dev/null', $env['GIT_CONFIG_GLOBAL']);
        $this->assertSame('1', $env['GIT_CONFIG_NOSYSTEM']);
        $this->assertStringNotContainsString('caddy', $env['HOME']);
    }

    public function test_open_basedir_stays_specific(): void
    {
        $msg = PosixRuntime::describeIoFailure('write', '/etc/caddy/conf.d/x.conf', [
            'message' => 'file_put_contents(): open_basedir restriction in effect',
        ]);
        $this->assertStringContainsString('open_basedir', $msg);
    }
}
