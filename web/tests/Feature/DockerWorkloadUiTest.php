<?php

namespace Tests\Feature;

use App\Jobs\RunOperationJob;
use App\Livewire\VhostsPage;
use App\Models\Operation;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B4 / A50 in the panel: service choice, container environment, volumes, restart policy and
 * registries — and the environment never travelling inside a queued job.
 */
class DockerWorkloadUiTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'box.example.com';

    private function admin(): User
    {
        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString((new TotpService())->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function fake(string $runtime = 'static'): FakeBroker
    {
        $fake = app(FakeBroker::class);
        $fake->vhosts[] = [
            'domain' => self::DOMAIN, 'domains' => [self::DOMAIN], 'root' => '/data/www/'.self::DOMAIN,
            'type' => $runtime === 'docker' ? 'proxy' : 'static', 'engine' => 'caddy', 'runtime' => $runtime,
            'docker_mode' => $runtime === 'docker' ? 'image' : null, 'docker_port' => $runtime === 'docker' ? 37000 : null,
            'readonly' => false, 'enabled' => true, 'tls' => true, 'tls_mode' => 'auto',
        ];

        return $fake;
    }

    public function test_enable_saves_the_environment_on_its_own_and_queues_without_it(): void
    {
        Queue::fake();
        $fake = $this->fake();

        Livewire::actingAs($this->admin())->test(VhostsPage::class)
            ->call('askDocker', self::DOMAIN)
            ->set('dockerMode', 'image')
            ->set('dockerImage', 'nginx:alpine')
            ->set('dockerInternalPort', '8080')
            ->set('dockerRestart', 'on-failure')
            ->set('dockerVolumesText', "data:/data\ncache:/cache:ro")
            ->set('dockerEnvText', "DB_PASSWORD=hunter2\n# comment\nMODE=prod")
            ->call('enableDocker')
            ->assertSet('error', null);

        $this->assertSame(['DB_PASSWORD' => 'hunter2', 'MODE' => 'prod'], $fake->dockerEnv[self::DOMAIN]);
        $op = Operation::query()->sole();
        $this->assertStringNotContainsString('hunter2', json_encode($op->toArray()));
        Queue::assertPushed(RunOperationJob::class, function ($job): bool {
            $this->assertStringNotContainsString('hunter2', serialize($job));
            $this->assertSame('on-failure', $job->stdin['restart'] ?? null);
            $this->assertCount(2, $job->stdin['volumes'] ?? []);

            return true;
        });
    }

    public function test_compose_services_are_offered_and_the_choice_is_sent(): void
    {
        Queue::fake();
        $fake = $this->fake();
        $fake->dockerServices[self::DOMAIN] = ['db', 'web'];

        Livewire::actingAs($this->admin())->test(VhostsPage::class)
            ->call('askDocker', self::DOMAIN)
            ->set('dockerMode', 'compose')
            ->set('dockerInternalPort', '80')
            ->call('loadDockerServices')
            ->assertSet('dockerServices', ['db', 'web'])
            ->set('dockerService', 'web')
            ->call('enableDocker')
            ->assertSet('error', null);

        Queue::assertPushed(RunOperationJob::class, fn ($job): bool => ($job->stdin['service'] ?? null) === 'web');
    }

    public function test_container_settings_load_and_save_for_an_enabled_vhost(): void
    {
        $fake = $this->fake('docker');
        $fake->dockerEnv[self::DOMAIN] = ['A' => '1'];
        $fake->dockerSettings[self::DOMAIN] = ['service' => null, 'restart' => 'never', 'volumes' => [['host' => 'd', 'container' => '/d', 'readonly' => false]], 'registry' => null];

        Livewire::actingAs($this->admin())->test(VhostsPage::class)
            ->call('askDockerSettings', self::DOMAIN)
            ->assertSet('dockerRestart', 'never')
            ->assertSet('dockerVolumesText', 'd:/d')
            ->assertSet('dockerEnvText', 'A=1')
            ->set('dockerEnvText', "A=2\nB=3")
            ->set('dockerRestart', 'always')
            ->call('saveDockerSettings')
            ->assertSet('error', null)
            ->assertSet('dockerSettingsTarget', null);

        $this->assertSame(['A' => '2', 'B' => '3'], $fake->dockerEnv[self::DOMAIN]);
        $this->assertSame('always', $fake->dockerSettings[self::DOMAIN]['restart']);
    }

    public function test_a_malformed_volume_line_is_refused_before_anything_is_saved(): void
    {
        $fake = $this->fake('docker');

        Livewire::actingAs($this->admin())->test(VhostsPage::class)
            ->call('askDockerSettings', self::DOMAIN)
            ->set('dockerVolumesText', 'just-one-part')
            ->call('saveDockerSettings')
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'host:container'));

        $this->assertArrayNotHasKey(self::DOMAIN, $fake->dockerSettings);
    }

    public function test_registries_are_saved_listed_and_the_password_is_not_kept(): void
    {
        $fake = $this->fake('docker');

        Livewire::actingAs($this->admin())->test(VhostsPage::class)
            ->call('askDockerSettings', self::DOMAIN)
            ->set('registryName', 'ghcr')
            ->set('registryHost', 'ghcr.io')
            ->set('registryUsername', 'bot')
            ->set('registryPassword', 'ghp_x')
            ->call('addRegistry')
            ->assertSet('error', null)
            ->assertSet('registryPassword', '')
            ->assertSet('dockerRegistries', [['name' => 'ghcr', 'host' => 'ghcr.io', 'username' => 'bot']]);

        $this->assertSame('ghp_x', $fake->dockerRegistries['ghcr']['password']);
    }

    public function test_cli_registry_add_never_takes_the_password_as_an_argument(): void
    {
        $fake = $this->fake();
        putenv('AZERIOID_REGISTRY_PASSWORD=tok');
        try {
            $this->assertSame(0, Artisan::call('azerioid:docker', ['action' => 'registry', 'op' => 'add', 'name' => 'hub', '--username' => 'me']));
        } finally {
            putenv('AZERIOID_REGISTRY_PASSWORD');
        }

        $this->assertSame('tok', $fake->dockerRegistries['hub']['password']);
        $this->assertSame('docker.io', $fake->dockerRegistries['hub']['host']);
    }

    public function test_cli_env_hides_values_unless_revealed(): void
    {
        $fake = $this->fake('docker');
        $fake->dockerEnv[self::DOMAIN] = ['SECRET' => 'v4lue'];

        Artisan::call('azerioid:vhost', ['action' => 'docker', 'filesOp' => 'env', '--domain' => self::DOMAIN]);
        $this->assertStringNotContainsString('v4lue', Artisan::output());

        Artisan::call('azerioid:vhost', ['action' => 'docker', 'filesOp' => 'env', '--domain' => self::DOMAIN, '--reveal' => true]);
        $this->assertStringContainsString('SECRET=v4lue', Artisan::output());
    }
}
