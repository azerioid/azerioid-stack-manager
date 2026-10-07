<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Deploy\GitDeploy;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * B8 / ADR A41, A53: git deploy with a root-only key, code checked out and commands run as
 * the site's identity, in place, manual or scheduled, rollback code-only.
 */
final class GitDeployTest extends TestCase
{
    private const DOMAIN = 'app.test';
    private const SHA1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHA2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private FakeRuntime $rt;
    private Config $cfg;
    private string $head = self::SHA1;
    private bool $commandFails = false;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->rt->files['/etc/caddy/conf.d/app.test.conf'] = "# azerioid-managed engine=caddy type=php php=8.4 root=/data/www/app.test/public\napp.test {\n}\n";
        $this->rt->dirs['/data/www/app.test'] = true;
        $this->rt->execFn = function (array $c, ?string $stdin): ?ExecResult {
            if (($c[0] ?? '') === '/usr/bin/ssh-keygen') {
                $key = end($c);
                $this->rt->files[$key] = 'PRIVATE';
                $this->rt->files[$key . '.pub'] = "ssh-ed25519 AAAA azerioid-deploy@app.test\n";

                return new ExecResult($c, 0, '', '');
            }
            if (($c[0] ?? '') === '/usr/bin/git' && in_array('rev-parse', $c, true)) {
                return new ExecResult($c, 0, $this->head . "\n", '');
            }
            if (($c[0] ?? '') === '/usr/bin/git' && in_array('init', $c, true)) {
                $this->rt->dirs[end($c)] = true;

                return new ExecResult($c, 0, '', '');
            }
            if (($c[0] ?? '') === '/usr/sbin/runuser' && $this->commandFails && str_contains((string) end($c), 'composer')) {
                return new ExecResult($c, 1, '', 'composer: missing ext-intl');
            }

            return null;
        };
    }

    /** @return iterable<string, array{0:string}> */
    public static function badRepositories(): iterable
    {
        yield 'ext transport' => ['ext::sh -c touch% /tmp/pwned'];
        yield 'file transport' => ['file:///etc'];
        yield 'local path' => ['/root/repo.git'];
        yield 'credentials in https' => ['https://user:token@github.com/o/r.git'];
        yield 'option injection' => ['--upload-pack=touch /tmp/x'];
        yield 'plain http' => ['http://github.com/o/r.git'];
        yield 'parent path' => ['git@github.com:o/../r.git'];
    }

    /** @dataProvider badRepositories */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRepositories')]
    public function test_dangerous_repository_urls_are_refused(string $url): void
    {
        $this->expectException(BrokerException::class);
        GitDeploy::validateRepository($url);
    }

    public function test_ssh_and_public_https_repositories_are_accepted(): void
    {
        foreach (['git@github.com:acme/shop.git', 'ssh://git@gitlab.example.com:2222/acme/shop.git', 'https://github.com/acme/shop.git'] as $url) {
            $this->assertSame($url, GitDeploy::validateRepository($url));
        }
    }

    public function test_configuring_creates_a_root_only_key_once(): void
    {
        $deploy = new GitDeploy($this->cfg, $this->rt);
        $out = $deploy->configure(self::DOMAIN, ['repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'preset' => 'laravel']);

        $this->assertStringStartsWith('ssh-ed25519 ', $out['public_key']);
        $this->assertSame(0600, $this->rt->modes['/var/lib/azerioid-deploy/app-test/id_ed25519'] ?? null);
        $deploy->configure(self::DOMAIN, ['repository' => 'git@github.com:acme/shop.git', 'branch' => 'prod', 'preset' => 'none']);
        $this->assertCount(1, array_filter($this->rt->execLog, static fn (array $e): bool => ($e['command'][0] ?? '') === '/usr/bin/ssh-keygen'));
    }

    public function test_push_webhook_verifies_hmac_and_launches_deploy(): void
    {
        $deploy = new GitDeploy($this->cfg, $this->rt);
        $cfg = $deploy->configure(self::DOMAIN, ['repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'preset' => 'none']);
        $token = (string) $cfg['webhook_token'];
        $secret = (string) $cfg['webhook_secret'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        $body = '{"ref":"refs/heads/main"}';
        $sig = 'sha256='.hash_hmac('sha256', $body, $secret);

        $out = $deploy->webhook($token, 'github', $sig, $body);

        $this->assertTrue($out['accepted']);
        $this->assertSame(self::DOMAIN, $out['domain']);
        $launched = false;
        foreach ($this->rt->execLog as $e) {
            if (($e['command'][0] ?? '') === '/usr/bin/systemd-run' && in_array('deploy.run', $e['command'], true)) {
                $launched = true;
            }
        }
        $this->assertTrue($launched, 'a verified webhook must launch the deploy out of band');
    }

    public function test_push_webhook_rejects_a_forged_signature(): void
    {
        $deploy = new GitDeploy($this->cfg, $this->rt);
        $cfg = $deploy->configure(self::DOMAIN, ['repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'preset' => 'none']);
        $this->expectException(BrokerException::class);
        $deploy->webhook((string) $cfg['webhook_token'], 'github', 'sha256=deadbeef', '{"ref":"refs/heads/main"}');
    }

    public function test_push_webhook_ignores_a_push_to_another_branch(): void
    {
        $deploy = new GitDeploy($this->cfg, $this->rt);
        $cfg = $deploy->configure(self::DOMAIN, ['repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'preset' => 'none']);
        $body = '{"ref":"refs/heads/feature-x"}';
        $sig = 'sha256='.hash_hmac('sha256', $body, (string) $cfg['webhook_secret']);

        $out = $deploy->webhook((string) $cfg['webhook_token'], 'github', $sig, $body);

        $this->assertFalse($out['accepted']);
        foreach ($this->rt->execLog as $e) {
            $this->assertFalse(($e['command'][0] ?? '') === '/usr/bin/systemd-run' && in_array('deploy.run', $e['command'], true),
                'a push to a non-deploy branch must not launch a deploy');
        }
    }

    public function test_push_webhook_fails_closed_on_a_payload_without_a_branch(): void
    {
        // A signed but non-push payload (e.g. GitHub's "ping", or any body with no
        // ref) must NOT deploy — the branch cannot be confirmed.
        $deploy = new GitDeploy($this->cfg, $this->rt);
        $cfg = $deploy->configure(self::DOMAIN, ['repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'preset' => 'none']);
        $body = '{"zen":"Keep it simple"}';
        $sig = 'sha256='.hash_hmac('sha256', $body, (string) $cfg['webhook_secret']);

        $out = $deploy->webhook((string) $cfg['webhook_token'], 'github', $sig, $body);

        $this->assertFalse($out['accepted']);
        foreach ($this->rt->execLog as $e) {
            $this->assertFalse(($e['command'][0] ?? '') === '/usr/bin/systemd-run' && in_array('deploy.run', $e['command'], true),
                'a payload with no branch must not launch a deploy');
        }
    }

    public function test_a_custom_command_needs_the_typed_confirm(): void
    {
        $this->expectException(BrokerException::class);
        (new GitDeploy($this->cfg, $this->rt))->configure(self::DOMAIN, [
            'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'preset' => 'custom', 'command' => 'make deploy',
        ]);
    }

    public function test_the_key_is_used_by_root_and_never_by_the_site(): void
    {
        $deploy = $this->configured('laravel');

        $deploy->deploy(self::DOMAIN, []);

        $fetch = $this->commands(static fn (array $c): bool => in_array('fetch', $c, true) && ($c[0] ?? '') === '/usr/bin/env');
        $this->assertCount(1, $fetch);
        $this->assertStringContainsString('-i /var/lib/azerioid-deploy/app-test/id_ed25519', $fetch[0][1]);
        $this->assertStringContainsString('UserKnownHostsFile=/var/lib/azerioid-deploy/app-test/known_hosts', $fetch[0][1]);
        foreach ($this->commands(static fn (array $c): bool => ($c[0] ?? '') === '/usr/sbin/runuser') as $c) {
            $this->assertSame('az-vh-app-test', $c[2], 'every site-side step runs as the site');
            $this->assertStringNotContainsString('id_ed25519', implode(' ', $c));
            $this->assertStringStartsWith('umask 007;', (string) end($c));
        }
    }

    public function test_deploy_checks_out_runs_the_command_as_the_site_and_records_history(): void
    {
        $deploy = $this->configured('laravel');

        $out = $deploy->deploy(self::DOMAIN, []);

        $this->assertTrue($out['deployed']);
        $site = array_map(static fn (array $c): string => (string) end($c), $this->commands(static fn (array $c): bool => ($c[0] ?? '') === '/usr/sbin/runuser'));
        $this->assertStringContainsString("cd '/data/www/app.test'", $site[0], 'the whole site directory, not only public/');
        $this->assertStringContainsString('git checkout --quiet --force --detach', $site[0]);
        $this->assertStringContainsString("--upload-pack='git -c safe.directory=/var/lib/azerioid-deploy/app-test/mirror.git upload-pack'", $site[0]);
        $this->assertStringContainsString('php artisan migrate --force', $site[1]);
        $this->assertStringContainsString('/usr/bin/php8.4', $site[1], 'the site\'s own PHP version');
        $state = $deploy->state(self::DOMAIN);
        $this->assertSame(self::SHA1, $state['current']);
        $this->assertSame('ok', $state['history'][0]['status']);
    }

    public function test_a_failing_command_is_recorded_and_reported(): void
    {
        $deploy = $this->configured('composer');
        $this->commandFails = true;

        try {
            $deploy->deploy(self::DOMAIN, []);
            $this->fail('expected failure');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('roll back', $e->getMessage(), 'checked out, then the command failed');
        }
        $state = $deploy->state(self::DOMAIN);
        $this->assertSame('failed', $state['history'][0]['status']);
        $this->assertNull($state['current'] ?? null);
    }

    public function test_a_failed_checkout_says_the_files_were_not_changed(): void
    {
        $deploy = $this->configured('none');
        $previous = $this->rt->execFn;
        $this->rt->execFn = static fn (array $c, ?string $stdin): ?ExecResult => ($c[0] ?? '') === '/usr/sbin/runuser'
            ? new ExecResult($c, 128, '', 'fatal: Could not read from remote repository.')
            : $previous($c, $stdin);

        try {
            $deploy->deploy(self::DOMAIN, []);
            $this->fail('expected failure');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('were not changed', $e->getMessage());
        }
    }

    public function test_a_scheduled_deploy_of_the_same_commit_does_nothing(): void
    {
        $deploy = $this->configured('none');
        $deploy->deploy(self::DOMAIN, []);
        $this->rt->execLog = [];

        $out = $deploy->deploy(self::DOMAIN, ['trigger' => 'schedule']);

        $this->assertFalse($out['deployed']);
        $this->assertSame([], $this->commands(static fn (array $c): bool => ($c[0] ?? '') === '/usr/sbin/runuser'));
    }

    public function test_rollback_returns_to_the_previous_commit(): void
    {
        $deploy = $this->configured('none');
        $deploy->deploy(self::DOMAIN, []);
        $this->head = self::SHA2;
        $deploy->deploy(self::DOMAIN, []);
        $this->assertSame(self::SHA1, $deploy->state(self::DOMAIN)['previous']);

        $out = $deploy->rollback(self::DOMAIN);

        $this->assertSame(self::SHA1, $out['commit']);
        $this->assertSame(self::SHA1, $deploy->state(self::DOMAIN)['current']);
        $this->assertSame('rollback', $deploy->state(self::DOMAIN)['history'][0]['trigger']);
    }

    public function test_listing_finds_configured_sites_with_their_schedule(): void
    {
        $deploy = new GitDeploy($this->cfg, $this->rt);
        $deploy->configure(self::DOMAIN, ['repository' => 'https://github.com/acme/shop.git', 'branch' => 'main', 'schedule' => 'daily@3']);

        $this->assertSame([['domain' => self::DOMAIN, 'schedule' => 'daily@3', 'last_deploy_at' => null, 'current' => null]], $deploy->listAll());
    }

    private function configured(string $preset): GitDeploy
    {
        $deploy = new GitDeploy($this->cfg, $this->rt);
        $deploy->configure(self::DOMAIN, ['repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'preset' => $preset]);
        $this->rt->files['/usr/bin/php8.4'] = '';

        return $deploy;
    }

    /** @return list<list<string>> */
    private function commands(callable $match): array
    {
        $out = [];
        foreach ($this->rt->execLog as $e) {
            if ($match($e['command'])) {
                $out[] = $e['command'];
            }
        }

        return $out;
    }
}
