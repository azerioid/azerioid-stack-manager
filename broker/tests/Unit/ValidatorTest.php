<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private FakeRuntime $rt;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
    }

    public function test_valid_domain(): void
    {
        $this->assertSame('example.com', Validator::domain('Example.COM'));
        $this->assertSame('projob.az', Validator::domain('projob.az'));
        $this->assertSame('api.dev.example.co.uk', Validator::domain('api.dev.example.co.uk'));
    }

    #[DataProvider('invalidDomains')]
    public function test_rejects_invalid_domains(string $domain): void
    {
        $this->expectException(BrokerException::class);
        Validator::domain($domain);
    }

    public static function invalidDomains(): array
    {
        return [
            ['example'],
            ['-bad.com'],
            ['bad-.com'],
            ['foo_bar.com'],
            ['has space.com'],
            ['../../etc/passwd'],
            ['example.com; rm -rf /'],
            ['example.com$(whoami)'],
            ['example.com|id'],
            [''],
            ['a'],
            [str_repeat('a', 250) . '.com'],
        ];
    }

    public function test_db_names(): void
    {
        $this->assertSame('app_prod', Validator::dbName('app_prod'));
        $this->assertSame('User01', Validator::userName('User01'));
    }

    #[DataProvider('invalidDbNames')]
    public function test_rejects_invalid_db_names(string $name): void
    {
        $this->expectException(BrokerException::class);
        Validator::dbName($name);
    }

    public static function invalidDbNames(): array
    {
        return [
            ['has-dash'],
            ['has space'],
            ['semi;colon'],
            ["quote'name"],
            ['mysql'],
            ['lacmp_panel'],
            [''],
            [str_repeat('a', 33)],
            ['root; DROP TABLE users'],
        ];
    }

    public function test_refuses_root_user(): void
    {
        $this->expectException(BrokerException::class);
        Validator::userName('root');
    }

    public function test_php_version_must_be_installed(): void
    {
        $this->assertSame('8.4', Validator::phpVersion('8.4', ['8.3', '8.4']));
        $this->expectException(BrokerException::class);
        Validator::phpVersion('8.9', ['8.3', '8.4']);
    }

    public function test_rejects_free_text_php_version(): void
    {
        $this->expectException(BrokerException::class);
        Validator::phpVersion('8.4; systemctl stop caddy', ['8.4; systemctl stop caddy']);
    }

    public function test_web_root_under_base(): void
    {
        $this->assertSame('/data/www/example.com', Validator::webRoot('/data/www/example.com', '/data/www', $this->rt));
    }

    #[DataProvider('badRoots')]
    public function test_rejects_bad_web_roots(string $path): void
    {
        $this->expectException(BrokerException::class);
        Validator::webRoot($path, '/data/www', $this->rt);
    }

    public static function badRoots(): array
    {
        return [
            ['/etc/passwd'],
            ['/data/www/../etc/passwd'],
            ['/tmp/evil'],
            ['relative/path'],
            ['/data/www/foo/../../etc'],
            ["/data/www/foo\0/bar"],
        ];
    }

    public function test_command_injection_in_service_name(): void
    {
        $this->expectException(BrokerException::class);
        Validator::service('caddy; id', ['caddy']);
    }

    public function test_service_must_be_allowlisted(): void
    {
        $this->expectException(BrokerException::class);
        Validator::service('ssh', ['caddy', 'mariadb']);
    }

    public function test_password_length(): void
    {
        $this->assertSame(str_repeat('a', 16), Validator::password(str_repeat('a', 16)));
        $this->expectException(BrokerException::class);
        Validator::password('short');
    }

    public function test_local_upstream(): void
    {
        $this->assertSame('127.0.0.1:9000', Validator::localUpstream('127.0.0.1:9000'));
        $this->expectException(BrokerException::class);
        Validator::localUpstream('10.0.0.5:3306');
    }

    public function test_typed_confirm_and_object_key(): void
    {
        $this->assertSame('REBOOT', Validator::typedConfirm('REBOOT', 'REBOOT'));
        $this->assertSame('azerioid/db/all/x.bin', Validator::objectKey('azerioid/db/all/x.bin'));
        $this->expectException(BrokerException::class);
        Validator::objectKey('../etc/passwd');
    }
}
