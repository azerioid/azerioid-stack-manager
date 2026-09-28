<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Supervisor\ProgramIdentity;
use AzerioidPanel\Broker\Supervisor\ProgramIdentityMigrator;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use PHPUnit\Framework\TestCase;

/**
 * ADR A56 part 1: a program bound to a site runs as that site's identity, not as the shared
 * azerioid-supervised account that is a member of every site's group.
 */
final class ProgramIdentityTest extends TestCase
{
    private const CONF = '/etc/supervisor/conf.d/azerioid-worker.conf';
    private const META = '/var/lib/azerioid-panel/supervised-programs.json';

    private FakeRuntime $rt;
    private Config $cfg;
    private Kernel $kernel;

    /** Supervisor state of the program, by the account its config names. */
    private string $stateAsSite = 'RUNNING';

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->cfg->wwwRoot = '/data/www';
        $this->cfg->managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';
        $this->rt->dirs['/data/www'] = true;
        $this->rt->dirs['/data/www/shop.example.com'] = true;
        $this->rt->dirs['/var/lib/azerioid-supervised/apps'] = true;
        $this->rt->dirs['/etc/supervisor/conf.d'] = true;
        $this->rt->files[$this->cfg->managedComponentsPath] = json_encode(['components' => ['supervisor' => ['unit' => 'supervisor']]]);
        $this->rt->execFn = function (array $c, ?string $stdin): ?ExecResult {
            if (($c[0] ?? '') === '/usr/bin/supervisorctl' && ($c[1] ?? '') === 'status') {
                $conf = $this->rt->files[self::CONF] ?? '';
                $state = str_contains($conf, 'user=az-vh-') ? $this->stateAsSite : 'RUNNING';

                return new ExecResult($c, 0, ($c[2] ?? '') . " {$state} pid 42", '');
            }

            return null;
        };
        $this->kernel = new Kernel($this->cfg, $this->rt);
    }

    public function test_a_program_bound_to_a_site_runs_as_the_site(): void
    {
        [$code, $json] = $this->broker(['supervisor.program.create'], $this->worker());

        $this->assertSame(0, $code, json_encode($json));
        $this->assertStringContainsString("user=az-vh-shop-example-com\n", $this->rt->files[self::CONF]);
        $this->assertSame('az-vh-shop-example-com', $json['data']['program']['user']);
        foreach ($this->rt->execLog as $e) {
            $this->assertNotSame('/usr/bin/setfacl', $e['command'][0] ?? '', 'no ACL for the shared account on the site');
        }
    }

    public function test_a_program_bound_to_no_site_stays_on_the_supervised_account(): void
    {
        $this->broker(['supervisor.program.create'], [
            'name' => 'worker', 'command' => '/usr/bin/node app.js', 'directory' => '/var/lib/azerioid-supervised/apps',
        ]);

        $this->assertStringContainsString('user=' . SupervisedUser::USERNAME . "\n", $this->rt->files[self::CONF]);
    }

    public function test_the_run_as_account_cannot_be_chosen(): void
    {
        foreach (['azerioid-supervised', 'az-vh-other-example-com', 'root'] as $user) {
            [$code] = $this->broker(['supervisor.program.create'], $this->worker() + ['user' => $user]);
            $this->assertSame(3, $code, $user);
        }
        $this->assertArrayNotHasKey(self::CONF, $this->rt->files);
    }

    public function test_docker_programs_wait_for_their_own_daemon(): void
    {
        $this->assertSame(SupervisedUser::USERNAME, ProgramIdentity::userFor($this->rt, 'shop.example.com', 'docker-shop-example-com'));
        $this->assertSame('az-vh-shop-example-com', ProgramIdentity::userFor($this->rt, 'shop.example.com', 'worker'));
    }

    public function test_the_migration_moves_an_existing_program_to_its_site(): void
    {
        $this->legacyWorker();
        $migrator = new ProgramIdentityMigrator($this->rt, $this->cfg, 0);
        $this->assertSame(['worker'], $migrator->status()['pending']);

        $out = $migrator->converge(true);

        $this->assertSame('ok', $out['result']);
        $this->assertSame('isolated', $out['programs']['worker']['result']);
        $this->assertStringContainsString("user=az-vh-shop-example-com\n", $this->rt->files[self::CONF]);
        $this->assertContains(['/usr/bin/chown', '-R', '-h', 'az-vh-shop-example-com:az-vh-shop-example-com', '/data/www/shop.example.com'], $this->commands());
        $this->assertTrue($migrator->status()['migrated']);
        $this->assertSame(['started' => false, 'reason' => 'nothing to do'], $migrator->converge(false));
    }

    public function test_a_program_that_stops_working_as_the_site_is_put_back(): void
    {
        $this->legacyWorker();
        $this->stateAsSite = 'FATAL';
        $migrator = new ProgramIdentityMigrator($this->rt, $this->cfg, 0);

        $out = $migrator->converge(true);

        $this->assertSame('partial', $out['result']);
        $this->assertSame('shared', $out['programs']['worker']['result']);
        $this->assertStringContainsString('RUNNING before the move, FATAL after', $out['programs']['worker']['reason']);
        $this->assertStringContainsString('user=' . SupervisedUser::USERNAME . "\n", $this->rt->files[self::CONF]);
        $status = $migrator->status();
        $this->assertFalse($status['migrated']);
        $this->assertFalse($status['auto_eligible'], 'not retried automatically');

        $this->stateAsSite = 'RUNNING';
        $retry = $migrator->apply('ISOLATE-PROGRAMS');
        $this->assertSame('isolated', $retry['programs']['worker']['result']);
        $this->assertNull(ProgramIdentity::sharedReason($this->rt, 'shop.example.com'));
        $this->assertTrue($migrator->status()['migrated']);
    }

    public function test_the_operator_retry_needs_the_typed_confirm(): void
    {
        [$code] = $this->broker(['program.identity.apply'], ['confirm' => 'yes']);

        $this->assertSame(3, $code);
    }

    /** @return array<string, mixed> */
    private function worker(): array
    {
        return [
            'name' => 'worker', 'command' => '/usr/bin/php artisan queue:work',
            'directory' => '/data/www/shop.example.com', 'vhost_domain' => 'shop.example.com',
        ];
    }

    /** A program created by a release before A56: running as azerioid-supervised. */
    private function legacyWorker(): void
    {
        $this->rt->uid = 1000;
        $this->broker(['supervisor.program.create'], $this->worker());
        $this->rt->uid = 0;
        $this->assertStringContainsString('user=' . SupervisedUser::USERNAME . "\n", $this->rt->files[self::CONF]);
        $this->rt->execLog = [];
    }

    /** @return array{0:int, 1:array<string,mixed>} */
    private function broker(array $argv, array $input = []): array
    {
        ob_start();
        $code = $this->kernel->run(array_merge(['broker'], $argv), $input);
        $out = (string) ob_get_clean();

        return [$code, (array) json_decode($out, true)];
    }

    /** @return list<list<string>> */
    private function commands(): array
    {
        return array_map(static fn (array $e): array => $e['command'], $this->rt->execLog);
    }
}
