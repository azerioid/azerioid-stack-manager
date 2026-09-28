<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Vhost\DockerManager;
use AzerioidPanel\Broker\Vhost\DockerRegistries;
use AzerioidPanel\Broker\Vhost\DockerSettings;
use PHPUnit\Framework\TestCase;

/**
 * B4 / ADR A50: compose service selection (G8), environment, data volumes and restart policy
 * (G7), and private-registry credentials that never touch the shared account's config (#6).
 */
final class DockerWorkloadTest extends TestCase
{
    private const DOMAIN = 'box.example.com';
    private const ROOT = '/data/www/box.example.com';
    private const CONF = '/etc/caddy/conf.d/box.example.com.conf';

    private FakeRuntime $rt;
    private Config $cfg;
    private Kernel $kernel;

    /** @var list<string> services `docker compose config --services` reports */
    private array $services = ['db', 'web'];

    /** @var list<array{shell:string, stdin:?string}> commands run as azerioid-supervised */
    private array $supervised = [];

    private bool $loginFails = false;

    /** @var list<int>|null groups of the running rootless dockerd; null = not running */
    private ?array $daemonGroups = null;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->cfg->managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';
        $this->rt->files['/etc/caddy/Caddyfile'] = "{\n    admin 127.0.0.1:2019\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $this->rt->files[self::CONF] = "# azerioid-managed engine=caddy type=static root=" . self::ROOT . "\n"
            . self::DOMAIN . " {\n    root * " . self::ROOT . "\n    file_server\n}\n";
        $this->rt->dirs[self::ROOT] = true;
        $this->rt->files[self::ROOT . '/docker-compose.yml'] = "services:\n  db:\n    image: postgres\n  web:\n    image: nginx\n";
        $this->rt->files[$this->cfg->managedComponentsPath] = json_encode(['components' => [
            'supervisor' => ['unit' => 'supervisor', 'installed_at' => '2026-01-01'],
            'docker' => ['unit' => '', 'installed_at' => '2026-01-01'],
        ]], JSON_THROW_ON_ERROR);
        $this->rt->dirs['/etc/supervisor/conf.d'] = true;
        $this->rt->dirs['/var/lib/azerioid-supervised'] = true;
        $this->rt->dirs['/var/log/azerioid-supervised'] = true;
        $this->rt->files['/usr/sbin/runuser'] = '';
        $this->rt->files['/usr/bin/docker'] = '';
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $program = 'azerioid-' . DockerManager::programName(self::DOMAIN);
        $this->rt->script(['/usr/bin/supervisorctl', 'status', $program], 0, "{$program} RUNNING pid 42, uptime 0:01:00");

        $this->rt->execFn = function (array $c, ?string $stdin): ?ExecResult {
            if ($c === ['/usr/bin/id', '-u', 'azerioid-supervised']) {
                return new ExecResult($c, 0, "1001\n", '');
            }
            if ($c === ['/usr/bin/pgrep', '-u', 'azerioid-supervised', '-x', 'dockerd']) {
                return $this->daemonGroups === null ? new ExecResult($c, 1, '', '') : new ExecResult($c, 0, "1077\n", '');
            }
            if ($c === ['/usr/bin/getent', 'group', 'az-vh-box-example-com']) {
                return new ExecResult($c, 0, "az-vh-box-example-com:x:969:caddy,www-data,azerioid-supervised\n", '');
            }
            if (in_array('info', $c, true)) {
                return new ExecResult($c, 0, "27.3.1\n", '');
            }
            if (($c[0] ?? '') !== '/usr/sbin/runuser') {
                return null;
            }
            $shell = (string) end($c);
            $this->supervised[] = ['shell' => $shell, 'stdin' => $stdin];
            if (str_contains($shell, "'config' '--services'")) {
                return new ExecResult($c, 0, implode("\n", $this->services) . "\n", '');
            }
            if (str_contains($shell, "'login'")) {
                return $this->loginFails ? new ExecResult($c, 1, '', 'unauthorized') : new ExecResult($c, 0, 'Login Succeeded', '');
            }

            return new ExecResult($c, 0, '', '');
        };
        $this->kernel = new Kernel($this->cfg, $this->rt);
    }

    // ----------------------------------------------------------------- G8

    public function test_the_operator_chosen_service_gets_the_port_even_when_listed_second(): void
    {
        [$code, $json] = $this->run_('vhost.docker.enable', ['mode' => 'compose', 'internal_port' => 80, 'service' => 'web']);

        $this->assertSame(0, $code, json_encode($json));
        $override = $this->rt->files[DockerSettings::portsOverridePath(self::DOMAIN)];
        $this->assertStringContainsString("services:\n  web:\n    ports:", $override);
        $this->assertStringNotContainsString('db:', $override);
        $this->assertSame('web', DockerSettings::load($this->rt, self::DOMAIN)['service']);
    }

    public function test_several_services_and_no_choice_is_refused_with_the_list(): void
    {
        [$code, $json] = $this->run_('vhost.docker.enable', ['mode' => 'compose', 'internal_port' => 80]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('db, web', (string) $json['error']);
        $this->assertStringContainsString('service=', (string) $json['error']);
    }

    public function test_a_single_service_needs_no_choice(): void
    {
        $this->services = ['app'];

        [$code] = $this->run_('vhost.docker.enable', ['mode' => 'compose', 'internal_port' => 80]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("  app:\n", $this->rt->files[DockerSettings::portsOverridePath(self::DOMAIN)]);
    }

    public function test_a_service_not_in_the_file_is_refused(): void
    {
        [$code, $json] = $this->run_('vhost.docker.enable', ['mode' => 'compose', 'internal_port' => 80, 'service' => 'worker']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('not in', (string) $json['error']);
    }

    public function test_services_are_asked_of_compose_itself(): void
    {
        [$code, $json] = $this->run_('vhost.docker.services', []);

        $this->assertSame(0, $code, json_encode($json));
        $this->assertSame(['db', 'web'], $json['data']['services']);
    }

    public function test_the_override_is_readable_by_the_supervised_account_and_nowhere_near_panel_state(): void
    {
        $this->run_('vhost.docker.enable', ['mode' => 'compose', 'internal_port' => 80, 'service' => 'web']);

        $path = DockerSettings::portsOverridePath(self::DOMAIN);
        $this->assertStringStartsWith('/var/lib/azerioid-docker/', $path);
        $this->assertSame(['root', 'azerioid-supervised'], $this->rt->owners[$path] ?? null);
        $this->assertSame(['root', 'azerioid-supervised'], $this->rt->owners[DockerSettings::dir(self::DOMAIN)] ?? null);
    }

    // ----------------------------------------------------------------- G7

    public function test_env_values_reach_the_container_through_a_file_never_the_command_line(): void
    {
        [$code] = $this->run_('vhost.docker.enable', [
            'mode' => 'image', 'image' => 'nginx:alpine', 'internal_port' => 80,
            'env' => ['DB_PASSWORD' => 's3cret-value', 'MODE' => 'prod'],
        ]);

        $this->assertSame(0, $code);
        $conf = $this->supervisorConf();
        $this->assertStringContainsString('--env-file ' . DockerSettings::envPath(self::DOMAIN), $conf);
        $this->assertStringNotContainsString('s3cret-value', $conf);
        $this->assertSame("DB_PASSWORD=s3cret-value\nMODE=prod\n", $this->rt->files[DockerSettings::envPath(self::DOMAIN)]);
        foreach ($this->supervised as $cmd) {
            $this->assertStringNotContainsString('s3cret-value', $cmd['shell']);
        }
    }

    public function test_env_is_redacted_from_the_audit_log(): void
    {
        $this->run_('vhost.docker.env.set', ['env' => ['API_TOKEN' => 'tok-123']]);

        $audit = implode('', array_filter($this->rt->files, static fn ($v, $k) => str_contains((string) $k, 'audit'), ARRAY_FILTER_USE_BOTH));
        $this->assertStringNotContainsString('tok-123', $audit);
    }

    public function test_compose_env_is_written_for_the_chosen_service_with_dollars_escaped(): void
    {
        $this->run_('vhost.docker.env.set', ['env' => ['PRICE' => 'costs $5 "net"']]);
        $this->run_('vhost.docker.enable', ['mode' => 'compose', 'internal_port' => 80, 'service' => 'web']);

        $this->assertStringContainsString('PRICE: "costs $$5 \\"net\\""', $this->rt->files[DockerSettings::portsOverridePath(self::DOMAIN)]);
    }

    public function test_a_value_with_a_line_break_is_refused(): void
    {
        [$code, $json] = $this->run_('vhost.docker.env.set', ['env' => ['A' => "one\nB=two"]]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('single line', (string) $json['error']);
    }

    public function test_data_volumes_are_bind_mounted_from_inside_the_app(): void
    {
        [$code] = $this->run_('vhost.docker.enable', [
            'mode' => 'image', 'image' => 'postgres:16', 'internal_port' => 5432,
            'volumes' => [['host' => 'data/pg', 'container' => '/var/lib/postgresql/data']],
        ]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('-v ' . self::ROOT . '/data/pg:/var/lib/postgresql/data', $this->supervisorConf());
        $this->assertTrue($this->rt->isDir(self::ROOT . '/data/pg'));
        $this->assertSame(02775, $this->rt->modes[self::ROOT . '/data/pg'] ?? null, 'readable by the container\'s non-root user');
    }

    /** @return iterable<string, array{0:array<string,mixed>}> */
    public static function badVolumes(): iterable
    {
        yield 'escape with ..' => [['host' => '../other.site', 'container' => '/data']];
        yield 'absolute host path' => [['host' => '/etc', 'container' => '/data']];
        yield 'relative container path' => [['host' => 'data', 'container' => 'data']];
        yield 'container root' => [['host' => 'data', 'container' => '/']];
        yield 'space in path' => [['host' => 'my data', 'container' => '/data']];
    }

    /** @dataProvider badVolumes */
    #[\PHPUnit\Framework\Attributes\DataProvider('badVolumes')]
    public function test_volumes_outside_the_app_are_refused(array $volume): void
    {
        [$code] = $this->run_('vhost.docker.enable', [
            'mode' => 'image', 'image' => 'nginx:alpine', 'internal_port' => 80, 'volumes' => [$volume],
        ]);

        $this->assertNotSame(0, $code);
    }

    public function test_restart_on_failure_maps_to_supervisors_unexpected(): void
    {
        $this->run_('vhost.docker.enable', ['mode' => 'image', 'image' => 'nginx:alpine', 'internal_port' => 80, 'restart' => 'on-failure']);

        $this->assertStringContainsString("autorestart=unexpected\n", $this->supervisorConf());
    }

    public function test_default_restart_policy_is_always(): void
    {
        $this->run_('vhost.docker.enable', ['mode' => 'image', 'image' => 'nginx:alpine', 'internal_port' => 80]);

        $this->assertStringContainsString("autorestart=true\n", $this->supervisorConf());
    }

    // ------------------------------------------------ daemon group list (A49 × A50)

    public function test_a_daemon_started_before_the_vhost_group_existed_is_restarted_once(): void
    {
        $this->daemonGroups = [984, 986];
        $this->rt->files['/proc/1077/status'] = "Name:\tdockerd\nGroups:\t984 986 \n";

        [$code, $json] = $this->run_('vhost.docker.enable', ['mode' => 'image', 'image' => 'nginx:alpine', 'internal_port' => 80]);

        $this->assertSame(0, $code, json_encode($json));
        $restarts = array_filter($this->rt->execLog, static fn (array $e): bool => $e['command'] === ['/usr/bin/systemctl', 'restart', 'user@1001.service']);
        $this->assertCount(1, $restarts, 'the user manager restarts, not only docker.service: dockerd inherits its groups');
    }

    public function test_a_daemon_that_already_holds_the_group_is_left_alone(): void
    {
        $this->daemonGroups = [984, 969];
        $this->rt->files['/proc/1077/status'] = "Name:\tdockerd\nGroups:\t984 969\n";

        $this->run_('vhost.docker.enable', ['mode' => 'image', 'image' => 'nginx:alpine', 'internal_port' => 80]);

        $this->assertSame([], array_filter($this->rt->execLog, static fn (array $e): bool => ($e['command'][1] ?? '') === 'restart'
            && str_starts_with((string) ($e['command'][2] ?? ''), 'user@')));
    }

    // ----------------------------------------------------------------- #6

    public function test_a_private_pull_logs_in_through_stdin_into_a_throwaway_config(): void
    {
        $this->run_('docker.registry.set', ['name' => 'ghcr', 'host' => 'ghcr.io', 'username' => 'bot', 'password' => 'ghp_TOKEN']);

        [$code, $json] = $this->run_('vhost.docker.enable', [
            'mode' => 'image', 'image' => 'ghcr.io/acme/app:1', 'internal_port' => 80, 'registry' => 'ghcr',
        ]);

        $this->assertSame(0, $code, json_encode($json));
        $login = array_values(array_filter($this->supervised, static fn (array $c): bool => str_contains($c['shell'], "'login'")));
        $this->assertCount(1, $login);
        $this->assertSame("ghp_TOKEN\n", $login[0]['stdin']);
        foreach ($this->supervised as $cmd) {
            $this->assertStringNotContainsString('ghp_TOKEN', $cmd['shell']);
        }
        $pull = array_values(array_filter($this->supervised, static fn (array $c): bool => str_contains($c['shell'], "'pull'")))[0]['shell'];
        $this->assertMatchesRegularExpression("#'DOCKER_CONFIG=/var/lib/azerioid-docker/\\.auth-[0-9a-f]{16}'#", $pull);
        $this->assertStringNotContainsString('/var/lib/azerioid-supervised/.docker', $pull);
        $this->assertNotEmpty(array_filter($this->supervised, static fn (array $c): bool => str_contains($c['shell'], "'logout'")));
        $removed = array_filter($this->rt->execLog, static fn (array $e): bool => ($e['command'][0] ?? '') === '/bin/rm'
            && str_contains((string) end($e['command']), '/.auth-'));
        $this->assertCount(1, $removed, 'the throwaway config is deleted');
        $this->assertStringNotContainsString('ghp_TOKEN', $this->supervisorConf());
    }

    public function test_the_throwaway_config_is_deleted_even_when_login_fails(): void
    {
        $this->run_('docker.registry.set', ['name' => 'ghcr', 'host' => 'ghcr.io', 'username' => 'bot', 'password' => 'ghp_TOKEN']);
        $this->loginFails = true;

        [$code, $json] = $this->run_('vhost.docker.enable', [
            'mode' => 'image', 'image' => 'ghcr.io/acme/app:1', 'internal_port' => 80, 'registry' => 'ghcr',
        ]);

        $this->assertNotSame(0, $code);
        $this->assertStringNotContainsString('ghp_TOKEN', (string) $json['error']);
        $this->assertNotEmpty(array_filter($this->rt->execLog, static fn (array $e): bool => ($e['command'][0] ?? '') === '/bin/rm'));
    }

    public function test_registry_list_never_returns_the_password(): void
    {
        $this->run_('docker.registry.set', ['name' => 'hub', 'host' => '', 'username' => 'me', 'password' => 'dckr_pat_X']);

        [, $json] = $this->run_('docker.registry.list', []);

        $this->assertSame([['name' => 'hub', 'host' => 'docker.io', 'username' => 'me']], $json['data']['registries']);
        $this->assertStringNotContainsString('dckr_pat_X', json_encode($json));
    }

    public function test_ecr_is_refused(): void
    {
        [$code, $json] = $this->run_('docker.registry.set', [
            'name' => 'ecr', 'host' => '123456789012.dkr.ecr.eu-central-1.amazonaws.com', 'username' => 'AWS', 'password' => 'x',
        ]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('ECR', (string) $json['error']);
    }

    public function test_a_registry_in_use_cannot_be_deleted(): void
    {
        $this->run_('docker.registry.set', ['name' => 'ghcr', 'host' => 'ghcr.io', 'username' => 'bot', 'password' => 'p']);
        $this->run_('vhost.docker.settings.set', ['registry' => 'ghcr']);

        [$code, $json] = $this->run_('docker.registry.delete', ['name' => 'ghcr']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString(self::DOMAIN, (string) $json['error']);
        $this->assertTrue(DockerRegistries::exists($this->rt, 'ghcr'));
    }

    public function test_the_credential_file_is_root_only(): void
    {
        $this->run_('docker.registry.set', ['name' => 'ghcr', 'host' => 'ghcr.io', 'username' => 'bot', 'password' => 'p']);

        $this->assertSame(0600, $this->rt->modes[DockerRegistries::DIR . '/ghcr.json'] ?? null);
        $this->assertSame(0700, $this->rt->modes[DockerRegistries::DIR] ?? null);
    }

    // ------------------------------------------------------------- helpers

    /** @return array{0:int, 1:array<string,mixed>} */
    private function run_(string $action, array $stdin): array
    {
        $argv = str_starts_with($action, 'docker.registry') ? ['broker', $action] : ['broker', $action, self::DOMAIN];
        ob_start();
        $code = $this->kernel->run($argv, $stdin);
        $out = ob_get_clean();

        return [$code, json_decode(trim((string) $out), true) ?? []];
    }

    private function supervisorConf(): string
    {
        foreach ($this->rt->files as $path => $body) {
            if (str_starts_with($path, '/etc/supervisor/conf.d/') && str_contains($path, 'docker-box')) {
                return $body;
            }
        }
        $this->fail('no supervisor program written');
    }
}
