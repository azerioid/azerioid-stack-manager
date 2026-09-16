<?php

namespace App\Livewire;

use App\Services\Broker\BrokerCallException;
use App\Services\Broker\BrokerClient;
use AzerioidPanel\Broker\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Virtual hosts · AZERIOID Stack Manager')]
class VhostsPage extends Component
{
    public array $vhosts = [];
    public array $phpVersions = [];
    public string $domain = '';
    public string $root = '';
    public string $type = 'php';
    public string $php_version = '';
    public string $upstream = '127.0.0.1:9000';
    public string $engine = 'caddy';
    public string $tlsMode = 'off';
    public string $dnsProvider = '';
    public string $dnsToken = '';
    public bool $acmeStaging = false;
    public bool $wildcard = false;
    public string $createRuntime = 'traditional';
    public ?string $error = null;
    public ?string $flash = null;
    public ?string $confirmDelete = null;
    public bool $removeSupervisorOnDelete = false;
    public array $supervisorByVhost = [];
    public array $mailDomains = [];
    public bool $dropMailOnDelete = false;
    public bool $showForm = false;
    public ?string $octaneTarget = null;
    public string $octaneMaxRequests = '500';
    public ?string $pm2Target = null;
    public ?string $pm2ScaleTarget = null;
    public string $pm2Instances = '1';
    public string $pm2Entry = '';
    public ?string $dockerTarget = null;
    public string $dockerMode = 'image';
    public string $dockerImage = '';
    public string $dockerInternalPort = '8080';
    public string $dockerCompose = '';
    public string $dockerDockerfile = '';
    /** @var list<array{repo_name:string}> */
    public array $dockerImageSuggestions = [];
    public ?string $dockerImageError = null;

    public ?string $editingDomain = null;
    public string $editRoot = '';
    public string $editPhpVersion = '';
    public bool $editTls = false;
    public string $editTlsMode = 'off';
    public string $editDnsProvider = '';
    public string $editDnsToken = '';
    public bool $editAcmeStaging = false;
    public bool $editWildcard = false;
    public string $editType = 'php';
    public string $editEngine = 'caddy';
    public array $dnsProviders = [];

    public string $listSearch = '';
    public string $filterType = '';
    public string $filterEngine = '';
    public string $filterRuntime = '';
    public string $filterStatus = '';

    public function mount(BrokerClient $broker): void
    {
        $this->reload($broker);
    }

    public function updatedDomain(): void
    {
        if ($this->root === '' && $this->domain !== '') {
            try {
                $d = Validator::domain($this->domain);
                $this->root = rtrim((string) config('azerioid.www_root'), '/') . '/' . $d;
            } catch (\Throwable) {
            }
        }
    }

    public function updatedType(): void
    {
        if ($this->type === 'php' && in_array($this->createRuntime, ['pm2', 'docker'], true)) {
            $this->createRuntime = 'traditional';
        }
        if ($this->type !== 'php' && $this->createRuntime === 'octane') {
            $this->createRuntime = 'traditional';
        }
        if ($this->createRuntime === 'docker') {
            $this->engine = 'caddy';
        }
    }

    public function updatedCreateRuntime(): void
    {
        if ($this->createRuntime === 'docker') {
            $this->engine = 'caddy';
            if ($this->type === 'php') {
                $this->type = 'static';
            }
        }
        if ($this->createRuntime === 'octane' && $this->type !== 'php') {
            $this->type = 'php';
        }
        if ($this->createRuntime === 'pm2' && ! in_array($this->type, ['proxy', 'static'], true)) {
            $this->type = 'static';
        }
    }

    public function updatedDockerImage(BrokerClient $broker): void
    {
        $this->dockerImageError = null;
        $this->dockerImageSuggestions = [];
        $q = trim($this->dockerImage);
        if (strlen($q) < 2) {
            return;
        }
        try {
            $res = $broker->call('vhost.docker.image.search', [], ['query' => $q], 15, false);
            if ($res->ok && is_array($res->data['suggestions'] ?? null)) {
                $this->dockerImageSuggestions = array_values(array_filter(
                    $res->data['suggestions'],
                    static fn ($row) => is_array($row) && isset($row['repo_name'])
                ));
            }
        } catch (\Throwable) {
            $this->dockerImageSuggestions = [];
        }
    }

    public function pickDockerImage(string $repo): void
    {
        $this->dockerImage = $repo;
        $this->dockerImageSuggestions = [];
        $this->dockerImageError = null;
    }

    public function create(BrokerClient $broker): void
    {
        $this->error = null;
        try {
            $domain = Validator::domain($this->domain);
            $root = Validator::webRoot($this->root, (string) config('azerioid.www_root'), new \AzerioidPanel\Broker\FakeRuntime());
            $type = Validator::vhostType($this->type);
            $createRuntime = $this->normalizeCreateRuntime($this->createRuntime, $type);
            if ($createRuntime === 'docker') {
                $this->engine = 'caddy';
            }
            $args = [$domain, $root, $type];
            if ($type === 'php') {
                $args[] = Validator::phpVersion($this->php_version, $this->phpVersions);
            } elseif ($type === 'proxy') {
                $args[] = Validator::localUpstream($this->upstream);
            }
            $res = $broker->call('vhost.add', $args, [
                'engine' => ($type === 'proxy' || $createRuntime === 'docker')
                    ? 'caddy'
                    : Validator::vhostEngine($this->engine),
            ]);
            if (! $res->ok) {
                $this->error = $this->operatorMessage((string) $res->error);

                return;
            }
            if ($this->tlsMode !== '' && $this->tlsMode !== 'off') {
                $payload = [
                    'domain' => $domain,
                    'tls_mode' => $this->tlsMode,
                    'tls' => true,
                ];
                if ($this->tlsMode === 'dns01') {
                    if ($this->dnsProvider === '') {
                        throw new \RuntimeException('Choose a DNS provider for DNS-01.');
                    }
                    $payload['dns_provider'] = $this->dnsProvider;
                    if (trim($this->dnsToken) !== '') {
                        $store = $broker->call('tls.dns-credential.store', [], [
                            'provider' => $this->dnsProvider,
                            'token' => $this->dnsToken,
                        ]);
                        if (! $store->ok) {
                            throw new \RuntimeException((string) $store->error);
                        }
                    }
                    if ($this->wildcard) {
                        $payload['wildcard'] = true;
                    }
                }
                if ($this->acmeStaging) {
                    $payload['staging'] = true;
                }
                $edit = $broker->call('vhost.edit', [$domain], $payload);
                if (! $edit->ok) {
                    $this->error = $this->operatorMessage('Vhost created, but TLS failed: '.(string) $edit->error);
                    $this->reload($broker);

                    return;
                }
            }
            $enableError = $this->enableCreateRuntime($broker, $domain, $createRuntime);
            if ($enableError !== null) {
                $this->error = $this->operatorMessage(
                    "Created {$domain}, but {$createRuntime} enable failed: {$enableError}. "
                    .'The vhost exists — use the Enable actions on the list to retry.'
                );
                $this->resetCreateForm();
                $this->reload($broker);

                return;
            }
            $this->flash = $createRuntime === 'traditional'
                ? "Created {$domain}."
                : "Created {$domain} and enabled {$createRuntime}.";
            $this->resetCreateForm();
            $this->reload($broker);
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
        }
    }

    private function resetCreateForm(): void
    {
        $this->reset(
            'domain',
            'root',
            'type',
            'upstream',
            'engine',
            'tlsMode',
            'dnsProvider',
            'dnsToken',
            'acmeStaging',
            'wildcard',
            'showForm',
            'createRuntime',
            'octaneMaxRequests',
            'pm2Instances',
            'pm2Entry',
            'dockerMode',
            'dockerImage',
            'dockerInternalPort',
            'dockerCompose',
            'dockerDockerfile',
            'dockerImageSuggestions',
            'dockerImageError'
        );
        $this->type = 'php';
        $this->engine = 'caddy';
        $this->tlsMode = 'off';
        $this->createRuntime = 'traditional';
        $this->octaneMaxRequests = '500';
        $this->pm2Instances = '1';
        $this->dockerMode = 'image';
        $this->dockerInternalPort = '8080';
    }

    private function normalizeCreateRuntime(string $runtime, string $type): string
    {
        $runtime = strtolower(trim($runtime));
        if (! in_array($runtime, ['traditional', 'octane', 'pm2', 'docker'], true)) {
            throw new \RuntimeException('createRuntime must be traditional, octane, pm2, or docker.');
        }
        if ($runtime === 'octane' && $type !== 'php') {
            throw new \RuntimeException('Octane create intent requires type=php.');
        }
        if ($runtime === 'pm2' && ! in_array($type, ['proxy', 'static'], true)) {
            throw new \RuntimeException('PM2 create intent requires type=proxy or static.');
        }
        if ($runtime === 'docker' && ! in_array($type, ['proxy', 'static'], true)) {
            throw new \RuntimeException('Docker create intent requires type=proxy or static.');
        }

        return $runtime;
    }

    private function enableCreateRuntime(BrokerClient $broker, string $domain, string $createRuntime): ?string
    {
        if ($createRuntime === 'traditional') {
            return null;
        }
        if ($createRuntime === 'octane') {
            $res = $broker->call('vhost.octane.enable', [$domain], [
                'max_requests' => trim($this->octaneMaxRequests),
            ], 900);

            return $res->ok ? null : (string) $res->error;
        }
        if ($createRuntime === 'pm2') {
            $input = ['instances' => trim($this->pm2Instances)];
            $entry = trim($this->pm2Entry);
            if ($entry !== '') {
                $input['entry'] = $entry;
            }
            $res = $broker->call('vhost.pm2.enable', [$domain], $input, 900);

            return $res->ok ? null : (string) $res->error;
        }
        if ($createRuntime === 'docker') {
            if (trim($this->dockerMode) === 'image') {
                $image = trim($this->dockerImage);
                $check = $broker->call('vhost.docker.image.validate', [], ['image' => $image], 60, false);
                if ($check->ok && empty($check->data['exists'])) {
                    $this->dockerImageError = 'Image not found: '.$image;

                    return 'Image not found: '.$image;
                }
            }
            $input = [
                'mode' => trim($this->dockerMode),
                'internal_port' => trim($this->dockerInternalPort),
            ];
            $image = trim($this->dockerImage);
            if ($image !== '') {
                $input['image'] = $image;
            }
            $compose = trim($this->dockerCompose);
            if ($compose !== '') {
                $input['compose'] = $compose;
            }
            $dockerfile = trim($this->dockerDockerfile);
            if ($dockerfile !== '') {
                $input['dockerfile'] = $dockerfile;
            }
            $res = $broker->call('vhost.docker.enable', [$domain], $input, 900);

            return $res->ok ? null : (string) $res->error;
        }

        return null;
    }

    public function startEdit(string $domain): void
    {
        $this->error = null;
        $this->showForm = false;
        foreach ($this->vhosts as $v) {
            if (($v['domain'] ?? '') !== $domain || ! empty($v['readonly'])) {
                continue;
            }
            $this->editingDomain = $domain;
            $this->editType = (string) ($v['type'] ?? 'php');
            $this->editEngine = (string) ($v['engine'] ?? 'caddy');
            $this->editRoot = (string) ($v['root'] ?? '');
            $this->editPhpVersion = (string) ($v['php_version'] ?? $this->php_version);
            $this->editTls = ! empty($v['tls']);
            $this->editTlsMode = (string) ($v['tls_mode'] ?? ($this->editTls ? 'auto' : 'off'));
            $this->editDnsProvider = '';
            $this->editDnsToken = '';
            $this->editAcmeStaging = false;
            $this->editWildcard = false;

            return;
        }
        $this->error = "{$domain} is managed externally and can't be edited.";
    }

    public function cancelEdit(): void
    {
        $this->reset('editingDomain', 'editRoot', 'editPhpVersion', 'editTls', 'editTlsMode', 'editDnsProvider', 'editDnsToken', 'editAcmeStaging', 'editWildcard', 'editType', 'editEngine');
    }

    public function saveEdit(BrokerClient $broker): void
    {
        if ($this->editingDomain === null) {
            return;
        }
        $this->error = null;
        try {
            $domain = Validator::domain($this->editingDomain);
            $this->assertMutableVhost($domain, 'edited');
            $mode = $this->editTlsMode;
            if ($mode === '' || $mode === 'off') {
                $mode = $this->editTls ? 'auto' : 'off';
            }
            $payload = [
                'domain' => $domain,
                'root' => Validator::webRoot($this->editRoot, (string) config('azerioid.www_root'), new \AzerioidPanel\Broker\FakeRuntime()),
                'tls_mode' => $mode,
                'tls' => $mode !== 'off',
                'engine' => $this->editType === 'proxy' ? 'caddy' : Validator::vhostEngine($this->editEngine),
            ];
            if ($this->editType === 'php') {
                $payload['php_version'] = Validator::phpVersion($this->editPhpVersion, $this->phpVersions);
            }
            if ($mode === 'dns01') {
                if ($this->editDnsProvider === '') {
                    throw new \RuntimeException('Choose a DNS provider for DNS-01.');
                }
                $payload['dns_provider'] = $this->editDnsProvider;
                if (trim($this->editDnsToken) !== '') {
                    $store = $broker->call('tls.dns-credential.store', [], [
                        'provider' => $this->editDnsProvider,
                        'token' => $this->editDnsToken,
                    ]);
                    if (! $store->ok) {
                        throw new \RuntimeException((string) $store->error);
                    }
                    $this->editDnsToken = '';
                }
                if ($this->editWildcard) {
                    $payload['wildcard'] = true;
                }
            }
            if ($this->editAcmeStaging) {
                $payload['staging'] = true;
            }
            $res = $broker->call('vhost.edit', [$domain], $payload);
            if (! $res->ok) {
                $this->error = $this->operatorMessage((string) $res->error);

                return;
            }
            $this->flash = "Updated {$domain}.";
            $this->cancelEdit();
            $this->reload($broker);
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
        }
    }

    public function askDelete(string $domain): void
    {
        $this->confirmDelete = $domain;
        $this->removeSupervisorOnDelete = false;
        $this->dropMailOnDelete = false;
    }

    public function delete(BrokerClient $broker, ?string $domain = null): void
    {
        $requested = $domain ?? $this->confirmDelete ?? '';
        if ($this->confirmDelete === null || $requested === '' || $requested !== $this->confirmDelete) {
            $this->error = 'Confirm deletion from the panel UI before removing a vhost.';
            $this->confirmDelete = null;
            $this->removeSupervisorOnDelete = false;
            $this->dropMailOnDelete = false;

            return;
        }
        $domain = Validator::domain($this->confirmDelete);
        try {
            $this->assertMutableVhost($domain, 'deleted');
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
            $this->confirmDelete = null;
            $this->removeSupervisorOnDelete = false;
            $this->dropMailOnDelete = false;

            return;
        }
        $stdin = [];
        if ($this->removeSupervisorOnDelete) {
            $stdin['remove_supervisor_programs'] = true;
        }
        if ($this->dropMailOnDelete && isset($this->mailDomains[$domain])) {
            $stdin['drop_mail'] = true;
            $stdin['confirm'] = Validator::DROP_MAIL_CONFIRM;
        }
        $res = $broker->call('vhost.del', [Validator::domain($domain)], $stdin);
        if (! $res->ok) {
            $this->error = $this->operatorMessage((string) $res->error);
        } else {
            $this->flash = $this->dropMailOnDelete
                ? "Deleted {$domain} and its mailboxes. Website files were left in place."
                : "Deleted {$domain}. Website files were left in place.";
        }
        $this->confirmDelete = null;
        $this->removeSupervisorOnDelete = false;
        $this->dropMailOnDelete = false;
        $this->reload($broker);
    }

    public function askOctane(string $domain): void
    {
        $this->error = null;
        $this->flash = null;
        $this->showForm = false;
        $this->octaneTarget = $domain;
        $this->octaneMaxRequests = '500';
    }

    public function cancelOctane(): void
    {
        $this->reset('octaneTarget', 'octaneMaxRequests');
    }

    public function enableOctane(BrokerClient $broker): void
    {
        $domain = (string) $this->octaneTarget;
        $this->error = null;
        try {
            $domain = Validator::domain($domain);
            $this->assertMutableVhost($domain);
            $this->assertLaravelVhost($domain);
            $res = $broker->call('vhost.octane.enable', [$domain], [
                'max_requests' => trim($this->octaneMaxRequests),
            ], 900);
            if (! $res->ok) {
                $this->error = $this->operatorMessage((string) $res->error);
            } else {
                $port = is_array($res->data) ? ($res->data['octane_port'] ?? '?') : '?';
                $this->flash = "Octane is now serving {$domain} from 127.0.0.1:{$port}. Deploys need a worker reload.";
            }
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
        }
        $this->cancelOctane();
        $this->reload($broker);
    }

    public function disableOctane(BrokerClient $broker, string $domain): void
    {
        $this->error = null;
        try {
            $domain = Validator::domain($domain);
            $this->assertMutableVhost($domain);
            $res = $broker->call('vhost.octane.disable', [$domain], [], 300);
            if (! $res->ok) {
                $this->error = $this->operatorMessage((string) $res->error);
            } else {
                $this->flash = "{$domain} is back on traditional PHP-FPM.";
            }
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
        }
        $this->reload($broker);
    }

    public function reloadOctane(BrokerClient $broker, string $domain): void
    {
        $this->error = null;
        try {
            $domain = Validator::domain($domain);
            $this->assertMutableVhost($domain);
            $res = $broker->call('vhost.octane.reload', [$domain], [], 300);
            if (! $res->ok) {
                $this->error = $this->operatorMessage((string) $res->error);
            } else {
                $method = is_array($res->data) ? (string) ($res->data['method'] ?? 'octane:reload') : 'octane:reload';
                $this->flash = "Reloaded Octane workers for {$domain} ({$method}).";
            }
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
        }
        $this->reload($broker);
    }

    public function askPm2(string $domain): void
    {
        $this->error = null;
        $this->flash = null;
        $this->showForm = false;
        $this->pm2Target = $domain;
        $this->pm2ScaleTarget = null;
        $this->pm2Instances = '1';
        $this->pm2Entry = '';
    }

    public function askPm2Scale(string $domain): void
    {
        $this->error = null;
        $this->flash = null;
        $this->pm2Target = null;
        $this->pm2ScaleTarget = $domain;
        foreach ($this->vhosts as $v) {
            if (($v['domain'] ?? '') === $domain) {
                $this->pm2Instances = (string) ($v['pm2_instances'] ?? '1');

                return;
            }
        }
        $this->pm2Instances = '1';
    }

    public function cancelPm2(): void
    {
        $this->reset('pm2Target', 'pm2ScaleTarget', 'pm2Instances', 'pm2Entry');
        $this->pm2Instances = '1';
    }

    public function enablePm2(BrokerClient $broker): void
    {
        $domain = (string) $this->pm2Target;
        $this->error = null;
        try {
            $domain = Validator::domain($domain);
            $this->assertMutableVhost($domain);
            $this->assertPm2Candidate($domain);
            $input = [
                'instances' => trim($this->pm2Instances),
            ];
            $entry = trim($this->pm2Entry);
            if ($entry !== '') {
                $input['entry'] = $entry;
            }
            $res = $broker->call('vhost.pm2.enable', [$domain], $input, 900);
            if (! $res->ok) {
                $this->error = $this->operatorMessage((string) $res->error);
            } else {
                $port = is_array($res->data) ? ($res->data['pm2_port'] ?? '?') : '?';
                $this->flash = "PM2 is now serving {$domain} from 127.0.0.1:{$port}. Deploys need a worker reload.";
            }
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
        }
        $this->cancelPm2();
        $this->reload($broker);
    }

    public function disablePm2(BrokerClient $broker, string $domain): void
    {
        $this->error = null;
        try {
            $domain = Validator::domain($domain);
            $this->assertMutableVhost($domain);
            $res = $broker->call('vhost.pm2.disable', [$domain], [], 300);
            if (! $res->ok) {
                $this->error = $this->operatorMessage((string) $res->error);
            } else {
                $this->flash = "{$domain} no longer runs under PM2.";
            }
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
        }
        $this->reload($broker);
    }

    public function reloadPm2(BrokerClient $broker, string $domain): void
    {
        $this->error = null;
        try {
            $domain = Validator::domain($domain);
            $this->assertMutableVhost($domain);
            $res = $broker->call('vhost.pm2.reload', [$domain], [], 300);
            if (! $res->ok) {
                $this->error = $this->operatorMessage((string) $res->error);
            } else {
                $method = is_array($res->data) ? (string) ($res->data['method'] ?? 'pm2-reload') : 'pm2-reload';
                $this->flash = "Reloaded PM2 workers for {$domain} ({$method}).";
            }
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
        }
        $this->reload($broker);
    }

    public function scalePm2(BrokerClient $broker): void
    {
        $domain = (string) $this->pm2ScaleTarget;
        $this->error = null;
        try {
            $domain = Validator::domain($domain);
            $this->assertMutableVhost($domain);
            $res = $broker->call('vhost.pm2.scale', [$domain], [
                'instances' => trim($this->pm2Instances),
            ], 300);
            if (! $res->ok) {
                $this->error = $this->operatorMessage((string) $res->error);
            } else {
                $n = is_array($res->data) ? ($res->data['pm2_instances'] ?? '?') : '?';
                $this->flash = "Scaled PM2 on {$domain} to {$n} worker(s).";
            }
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
        }
        $this->cancelPm2();
        $this->reload($broker);
    }

    /** PM2 only runs Node apps on proxy/static Caddy vhosts — never trust the UI-hidden button. */
    private function assertPm2Candidate(string $domain): void
    {
        foreach ($this->vhosts as $v) {
            if (($v['domain'] ?? '') !== $domain) {
                continue;
            }
            $type = (string) ($v['type'] ?? '');
            if ($type === 'php') {
                throw new \RuntimeException(
                    'PM2 is for Node apps. This is a PHP vhost — use Octane for Laravel, or create a separate Node site.'
                );
            }
            if (! in_array($type, ['proxy', 'static'], true)) {
                throw new \RuntimeException('PM2 is only available for proxy or static vhosts with a Node entrypoint.');
            }
            if (in_array($v['engine'] ?? 'caddy', ['apache', 'nginx'], true)) {
                throw new \RuntimeException(
                    "PM2 requires the Caddy engine. Switch {$domain} to engine=caddy first."
                );
            }
            $runtime = (string) ($v['runtime'] ?? 'fpm');
            if (in_array($runtime, ['pm2', 'octane', 'docker'], true)) {
                throw new \RuntimeException("PM2 cannot be enabled while {$domain} uses runtime={$runtime}.");
            }
            if (empty($v['node_app'])) {
                throw new \RuntimeException(
                    "{$domain} does not look like a Node application, so PM2 cannot run it. "
                    .(string) ($v['node_app_detail'] ?? '')
                );
            }

            return;
        }
        throw new \RuntimeException("{$domain} is not a mutable panel vhost.");
    }

    public function askDocker(string $domain): void
    {
        $this->error = null;
        $this->flash = null;
        $this->showForm = false;
        $this->dockerTarget = $domain;
        $this->dockerMode = 'image';
        $this->dockerImage = '';
        $this->dockerInternalPort = '8080';
        $this->dockerCompose = '';
        $this->dockerDockerfile = '';
        foreach ($this->vhosts as $v) {
            if (($v['domain'] ?? '') !== $domain) {
                continue;
            }
            if (! empty($v['docker_app'])) {
                $detail = (string) ($v['docker_app_detail'] ?? '');
                if (str_contains($detail, 'compose') || str_contains($detail, 'docker-compose')) {
                    $this->dockerMode = 'compose';
                } elseif (str_contains($detail, 'Dockerfile')) {
                    $this->dockerMode = 'dockerfile';
                }
            }
            break;
        }
    }

    public function cancelDocker(): void
    {
        $this->reset(
            'dockerTarget',
            'dockerMode',
            'dockerImage',
            'dockerInternalPort',
            'dockerCompose',
            'dockerDockerfile',
            'dockerImageSuggestions',
            'dockerImageError'
        );
        $this->dockerMode = 'image';
        $this->dockerInternalPort = '8080';
    }

    public function enableDocker(BrokerClient $broker): void
    {
        $domain = (string) $this->dockerTarget;
        $this->error = null;
        $this->dockerImageError = null;
        try {
            $domain = Validator::domain($domain);
            $this->assertMutableVhost($domain);
            $this->assertDockerCandidate($domain);
            if (trim($this->dockerMode) === 'image') {
                $image = trim($this->dockerImage);
                $check = $broker->call('vhost.docker.image.validate', [], ['image' => $image], 60, false);
                if ($check->ok && empty($check->data['exists'])) {
                    $this->dockerImageError = 'Image not found: '.$image;
                    $this->error = $this->dockerImageError;

                    return;
                }
            }
            $input = [
                'mode' => trim($this->dockerMode),
                'internal_port' => trim($this->dockerInternalPort),
            ];
            $image = trim($this->dockerImage);
            if ($image !== '') {
                $input['image'] = $image;
            }
            $compose = trim($this->dockerCompose);
            if ($compose !== '') {
                $input['compose'] = $compose;
            }
            $dockerfile = trim($this->dockerDockerfile);
            if ($dockerfile !== '') {
                $input['dockerfile'] = $dockerfile;
            }
            $res = $broker->call('vhost.docker.enable', [$domain], $input, 900);
            if (! $res->ok) {
                $this->error = $this->operatorMessage((string) $res->error);
            } else {
                $port = is_array($res->data) ? ($res->data['docker_port'] ?? '?') : '?';
                $this->flash = "Docker is now serving {$domain} from 127.0.0.1:{$port} (rootless).";
            }
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
        }
        $this->cancelDocker();
        $this->reload($broker);
    }

    public function disableDocker(BrokerClient $broker, string $domain): void
    {
        $this->error = null;
        try {
            $domain = Validator::domain($domain);
            $this->assertMutableVhost($domain);
            $res = $broker->call('vhost.docker.disable', [$domain], [], 300);
            if (! $res->ok) {
                $this->error = $this->operatorMessage((string) $res->error);
            } else {
                $this->flash = "{$domain} no longer runs under Docker.";
            }
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
        }
        $this->reload($broker);
    }

    public function rebuildDocker(BrokerClient $broker, string $domain): void
    {
        $this->error = null;
        try {
            $domain = Validator::domain($domain);
            $this->assertMutableVhost($domain);
            $res = $broker->call('vhost.docker.build', [$domain], [], 900);
            if (! $res->ok) {
                $this->error = $this->operatorMessage((string) $res->error);
            } else {
                $this->flash = "Rebuilt Docker workload for {$domain}.";
            }
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
        }
        $this->reload($broker);
    }

    public function restartDocker(BrokerClient $broker, string $domain): void
    {
        $this->error = null;
        try {
            $domain = Validator::domain($domain);
            $this->assertMutableVhost($domain);
            $res = $broker->call('vhost.docker.restart', [$domain], [], 300);
            if (! $res->ok) {
                $this->error = $this->operatorMessage((string) $res->error);
            } else {
                $this->flash = "Restarted Docker for {$domain}.";
            }
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
        }
        $this->reload($broker);
    }

    /** Docker only runs on proxy/static Caddy vhosts — never trust the UI-hidden button. */
    private function assertDockerCandidate(string $domain): void
    {
        foreach ($this->vhosts as $v) {
            if (($v['domain'] ?? '') !== $domain) {
                continue;
            }
            $type = (string) ($v['type'] ?? '');
            if ($type === 'php') {
                throw new \RuntimeException(
                    'Docker is for containerized apps on proxy/static sites. This is a PHP vhost — use Octane for Laravel.'
                );
            }
            if (! in_array($type, ['proxy', 'static'], true)) {
                throw new \RuntimeException('Docker is only available for proxy or static vhosts.');
            }
            if (in_array($v['engine'] ?? 'caddy', ['apache', 'nginx'], true)) {
                throw new \RuntimeException(
                    "Docker requires the Caddy engine. Switch {$domain} to engine=caddy first."
                );
            }
            $runtime = (string) ($v['runtime'] ?? 'fpm');
            if (in_array($runtime, ['pm2', 'octane', 'docker'], true)) {
                throw new \RuntimeException("Docker cannot be enabled while {$domain} uses runtime={$runtime}.");
            }

            return;
        }
        throw new \RuntimeException("{$domain} is not a mutable panel vhost.");
    }

    /** Octane only runs Laravel — never trust the UI-hidden button. */
    private function assertLaravelVhost(string $domain): void
    {
        foreach ($this->vhosts as $v) {
            if (($v['domain'] ?? '') !== $domain) {
                continue;
            }
            if (($v['type'] ?? '') !== 'php') {
                throw new \RuntimeException('Octane is only available for PHP vhosts.');
            }
            if (in_array($v['engine'] ?? 'caddy', ['apache', 'nginx'], true)) {
                throw new \RuntimeException(
                    "Octane requires the Caddy engine. Switch {$domain} to engine=caddy first."
                );
            }
            if (empty($v['laravel_app'])) {
                throw new \RuntimeException(
                    "{$domain} does not look like a Laravel application, so Octane cannot run it. "
                    .(string) ($v['laravel_app_detail'] ?? '')
                );
            }

            return;
        }
        throw new \RuntimeException("{$domain} is not a mutable panel vhost.");
    }

    /** Server-side check — do not trust UI-hidden buttons or a tampered editingDomain. */
    private function assertMutableVhost(string $domain, string $intent = 'edited'): void
    {
        foreach ($this->vhosts as $v) {
            if (($v['domain'] ?? '') !== $domain) {
                continue;
            }
            if (! empty($v['readonly'])) {
                throw new \RuntimeException(
                    $intent === 'deleted'
                        ? 'This vhost is managed externally and cannot be deleted by the panel.'
                        : "{$domain} is managed externally and can't be edited."
                );
            }

            return;
        }
        throw new \RuntimeException(
            $intent === 'deleted'
                ? 'This vhost is managed externally and cannot be deleted by the panel.'
                : "{$domain} is not a mutable panel vhost."
        );
    }

    private function operatorMessage(string $raw): string
    {
        if (str_contains($raw, 'open_basedir')) {
            return 'Broker PHP is restricted by open_basedir. Re-run the panel installer so the broker wrapper is installed.';
        }
        if (str_contains($raw, 'read-only for the broker context') || str_contains($raw, 'Read-only file system')) {
            return 'Web-server config is read-only in the panel process namespace. Re-run the installer so the broker leaves the PHP-FPM sandbox (ProtectSystem).';
        }

        return (string) preg_replace('#(?:/etc/caddy/conf\.d|/etc/apache2/sites-(?:available|enabled)|/etc/httpd/conf\.d/vhost)/\S+#', 'an existing vhost', $raw);
    }

    private function reload(BrokerClient $broker): void
    {
        try {
            $this->vhosts = $broker->call('vhost.list')->dataOrFail()['vhosts'] ?? [];
            $php = $broker->call('php.versions')->dataOrFail()['versions'] ?? [];
            $this->phpVersions = array_column($php, 'version');
            if ($this->php_version === '' && $this->phpVersions !== []) {
                $this->php_version = $this->phpVersions[array_key_last($this->phpVersions)];
            }
            $this->supervisorByVhost = [];
            try {
                $programs = $broker->call('supervisor.program.list')->dataOrFail()['programs'] ?? [];
                foreach ($programs as $program) {
                    $vd = $program['vhost_domain'] ?? null;
                    if ($vd) {
                        $this->supervisorByVhost[$vd][] = $program['name'];
                    }
                }
            } catch (BrokerCallException) {
            }
            $this->mailDomains = [];
            try {
                foreach ($broker->call('mail.domain.list', [], [], null, false)->dataOrFail()['domains'] ?? [] as $md) {
                    $name = (string) ($md['domain'] ?? '');
                    if ($name !== '') {
                        $this->mailDomains[$name] = (int) ($md['mailbox_count'] ?? 0);
                    }
                }
            } catch (BrokerCallException) {
            }
            try {
                $this->dnsProviders = $broker->call('tls.dns-providers', [], [], null, false)->dataOrFail()['providers'] ?? [];
            } catch (BrokerCallException) {
                $this->dnsProviders = [
                    ['id' => 'cloudflare', 'display_name' => 'Cloudflare', 'credentials_present' => false],
                    ['id' => 'digitalocean', 'display_name' => 'DigitalOcean', 'credentials_present' => false],
                ];
            }
        } catch (BrokerCallException $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * @param  array<string, mixed>  $vhost
     * @return array{state: string, label: string, detail: ?string}
     */
    public function vhostStatus(array $vhost): array
    {
        if (isset($vhost['enabled']) && $vhost['enabled'] === false) {
            return ['state' => 'disabled', 'label' => 'Disabled', 'detail' => null];
        }

        $ts = is_array($vhost['tls_status'] ?? null) ? $vhost['tls_status'] : [];
        if (! empty($ts['failed'])) {
            $detail = trim((string) ($ts['error'] ?? $ts['label'] ?? 'Failed'));
            $detail = preg_replace('/^Failed\s*[—\-]\s*/u', '', $detail) ?: $detail;

            return ['state' => 'failed', 'label' => 'Failed', 'detail' => $detail !== '' ? $detail : null];
        }
        if (! empty($ts['pending'])) {
            $detail = trim((string) ($ts['error'] ?? 'Certificate pending'));
            $detail = preg_replace('/^Pending\s*[—\-]\s*/u', '', $detail) ?: $detail;

            return ['state' => 'pending', 'label' => 'Pending', 'detail' => $detail !== '' ? $detail : null];
        }

        return ['state' => 'healthy', 'label' => 'Healthy', 'detail' => null];
    }

    /**
     * @param  array<string, mixed>  $vhost
     * @return array{label: string, tone: string, detail: ?string}
     */
    public function vhostRuntimeBadge(array $vhost): array
    {
        $runtime = strtolower((string) ($vhost['runtime'] ?? 'fpm'));
        $type = (string) ($vhost['type'] ?? '');

        return match ($runtime) {
            'octane' => [
                'label' => 'Octane',
                'tone' => 'accent',
                'detail' => 'FrankenPHP · 127.0.0.1:'.($vhost['octane_port'] ?? '?')
                    .' · max-req '.($vhost['octane_max_requests'] ?? '?'),
            ],
            'pm2' => [
                'label' => 'PM2',
                'tone' => 'accent',
                'detail' => 'Node · 127.0.0.1:'.($vhost['pm2_port'] ?? '?')
                    .' · '.($vhost['pm2_instances'] ?? '?').' worker(s)'
                    .(! empty($vhost['pm2_entry']) ? ' · '.$vhost['pm2_entry'] : ''),
            ],
            'docker' => [
                'label' => 'Docker',
                'tone' => 'accent',
                'detail' => 'Rootless · 127.0.0.1:'.($vhost['docker_port'] ?? '?')
                    .'→:'.($vhost['docker_internal_port'] ?? '?')
                    .' · '.($vhost['docker_mode'] ?? '?')
                    .(! empty($vhost['docker_image']) ? ' · '.$vhost['docker_image'] : ''),
            ],
            default => match ($type) {
                'php' => [
                    'label' => 'PHP-FPM',
                    'tone' => 'neutral',
                    'detail' => empty($vhost['readonly']) && empty($vhost['laravel_app'])
                        ? (string) ($vhost['laravel_app_detail'] ?? 'Octane unavailable — not a Laravel app')
                        : (empty($vhost['readonly']) && in_array($vhost['engine'] ?? 'caddy', ['apache', 'nginx'], true)
                            ? 'Octane unavailable — needs the Caddy engine'
                            : null),
                ],
                'static' => [
                    'label' => 'Static',
                    'tone' => 'neutral',
                    'detail' => $this->nodeRuntimeHint($vhost),
                ],
                'proxy' => [
                    'label' => 'Proxy',
                    'tone' => 'neutral',
                    'detail' => $this->nodeRuntimeHint($vhost),
                ],
                default => ['label' => '—', 'tone' => 'neutral', 'detail' => null],
            },
        };
    }

    /**
     * @param  array<string, mixed>  $vhost
     * @return list<array{label: string, items: list<array{label: string, href?: string, wireClick?: string, danger?: bool}>}>
     */
    public function vhostActionGroups(array $vhost): array
    {
        $domain = (string) ($vhost['domain'] ?? '');
        if ($domain === '') {
            return [];
        }

        $readonly = ! empty($vhost['readonly']);
        $runtime = strtolower((string) ($vhost['runtime'] ?? 'fpm'));
        $type = (string) ($vhost['type'] ?? '');
        $engine = (string) ($vhost['engine'] ?? 'caddy');
        $groups = [];

        $access = [];
        if (! empty($this->supervisorByVhost[$domain])) {
            $n = count($this->supervisorByVhost[$domain]);
            $access[] = [
                'label' => $n.' proc'.($n === 1 ? '' : 's'),
                'href' => '/processes',
            ];
        }
        if (! $readonly) {
            $access[] = ['label' => 'Files', 'href' => '/vhosts/'.$domain.'/files'];
            $access[] = ['label' => 'Terminal', 'href' => '/vhosts/'.$domain.'/terminal'];
            if ($runtime === 'docker') {
                $access[] = ['label' => 'Container shell', 'href' => '/vhosts/'.$domain.'/container-shell'];
                $access[] = ['label' => 'Container logs', 'href' => '/vhosts/'.$domain.'/container-logs'];
            }
        }
        if ($access !== []) {
            $groups[] = ['label' => 'Access', 'items' => $access];
        }

        if (! $readonly) {
            $runtimeItems = [];
            if ($runtime === 'octane') {
                $runtimeItems[] = ['label' => 'Reload application', 'wireClick' => "reloadOctane('{$domain}')"];
                $runtimeItems[] = ['label' => 'Switch to PHP-FPM', 'wireClick' => "disableOctane('{$domain}')"];
            } elseif ($runtime === 'pm2') {
                $runtimeItems[] = ['label' => 'Reload application', 'wireClick' => "reloadPm2('{$domain}')"];
                $runtimeItems[] = ['label' => 'Scale', 'wireClick' => "askPm2Scale('{$domain}')"];
                $runtimeItems[] = ['label' => 'Switch off PM2', 'wireClick' => "disablePm2('{$domain}')"];
            } elseif ($runtime === 'docker') {
                $runtimeItems[] = ['label' => 'Rebuild', 'wireClick' => "rebuildDocker('{$domain}')"];
                $runtimeItems[] = ['label' => 'Restart', 'wireClick' => "restartDocker('{$domain}')"];
                $runtimeItems[] = ['label' => 'Switch off Docker', 'wireClick' => "disableDocker('{$domain}')"];
            } else {
                if ($type === 'php' && ! empty($vhost['laravel_app']) && $engine === 'caddy' && $runtime === 'fpm') {
                    $runtimeItems[] = ['label' => 'Enable Octane', 'wireClick' => "askOctane('{$domain}')"];
                }
                if (in_array($type, ['proxy', 'static'], true) && ! empty($vhost['node_app']) && $engine === 'caddy'
                    && ! in_array($runtime, ['pm2', 'octane', 'docker'], true)) {
                    $runtimeItems[] = ['label' => 'Enable PM2', 'wireClick' => "askPm2('{$domain}')"];
                }
                if (in_array($type, ['proxy', 'static'], true) && $engine === 'caddy'
                    && ! in_array($runtime, ['pm2', 'octane', 'docker'], true)) {
                    $runtimeItems[] = ['label' => 'Enable Docker', 'wireClick' => "askDocker('{$domain}')"];
                }
            }
            if ($runtimeItems !== []) {
                $groups[] = ['label' => 'Runtime', 'items' => $runtimeItems];
            }

            $groups[] = [
                'label' => 'Configuration',
                'items' => [
                    ['label' => 'Edit', 'wireClick' => "startEdit('{$domain}')"],
                ],
            ];
            $groups[] = [
                'label' => 'Destructive',
                'items' => [
                    ['label' => 'Delete', 'wireClick' => "askDelete('{$domain}')", 'danger' => true],
                ],
            ];
        }

        return $groups;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function filteredVhosts(): array
    {
        $search = strtolower(trim($this->listSearch));

        return array_values(array_filter($this->vhosts, function (array $v) use ($search): bool {
            $domain = (string) ($v['domain'] ?? '');
            if ($search !== '' && ! str_contains(strtolower($domain), $search)) {
                return false;
            }
            if ($this->filterType !== '' && (string) ($v['type'] ?? '') !== $this->filterType) {
                return false;
            }
            if ($this->filterEngine !== '' && (string) ($v['engine'] ?? 'caddy') !== $this->filterEngine) {
                return false;
            }
            if ($this->filterRuntime !== '') {
                $runtime = strtolower((string) ($v['runtime'] ?? 'fpm'));
                $want = $this->filterRuntime;
                if ($want === 'php-fpm') {
                    if ($runtime !== 'fpm' || ($v['type'] ?? '') !== 'php') {
                        return false;
                    }
                } elseif ($want === 'static' || $want === 'proxy') {
                    if ($runtime !== 'fpm' || ($v['type'] ?? '') !== $want) {
                        return false;
                    }
                } elseif ($runtime !== $want) {
                    return false;
                }
            }
            if ($this->filterStatus !== '') {
                if ($this->vhostStatus($v)['state'] !== $this->filterStatus) {
                    return false;
                }
            }

            return true;
        }));
    }

    public function clearListFilters(): void
    {
        $this->listSearch = '';
        $this->filterType = '';
        $this->filterEngine = '';
        $this->filterRuntime = '';
        $this->filterStatus = '';
    }

    /**
     * @param  array<string, mixed>  $vhost
     */
    private function nodeRuntimeHint(array $vhost): ?string
    {
        if (! empty($vhost['readonly'])) {
            return null;
        }
        if (empty($vhost['node_app'])) {
            return (string) ($vhost['node_app_detail'] ?? 'PM2 unavailable — not a Node app');
        }
        if (in_array($vhost['engine'] ?? 'caddy', ['apache', 'nginx'], true)) {
            return 'PM2 unavailable — needs the Caddy engine';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $vhost
     */
    public function vhostRootLabel(array $vhost): string
    {
        $engine = (string) ($vhost['engine'] ?? 'caddy');
        if (in_array($engine, ['apache', 'nginx'], true)) {
            return (string) ($vhost['root'] ?? $vhost['reverse_proxy'] ?? '—');
        }

        return (string) ($vhost['reverse_proxy'] ?? $vhost['root'] ?? '—');
    }

    /**
     * TLS column: certificate details only (no failure narrative — that lives in Status).
     *
     * @param  array<string, mixed>  $vhost
     * @return array{primary: string, secondary: ?string}
     */
    public function vhostTlsDisplay(array $vhost): array
    {
        $ts = is_array($vhost['tls_status'] ?? null) ? $vhost['tls_status'] : null;
        $mode = (string) ($vhost['tls_mode'] ?? (! empty($vhost['tls']) ? 'auto' : 'off'));

        if ($ts === null) {
            return [
                'primary' => ! empty($vhost['tls']) ? $mode : 'No TLS',
                'secondary' => null,
            ];
        }

        if (empty($ts['enabled']) || $mode === 'off') {
            return ['primary' => 'No TLS', 'secondary' => null];
        }

        $issuerType = (string) ($ts['issuer_type'] ?? '');
        $primary = match ($issuerType) {
            'lets_encrypt' => "Let's Encrypt (HTTP-01)",
            'dns01' => "Let's Encrypt (DNS-01)",
            'self_signed' => 'Self-signed',
            'pending', 'failed' => strtoupper($mode),
            default => strtoupper($mode),
        };

        $bits = [];
        if (! empty($ts['issuer']) && ! in_array($issuerType, ['pending', 'failed'], true)) {
            $bits[] = (string) $ts['issuer'];
        }
        if (! empty($ts['valid_to']) && ! empty($ts['ok'])) {
            $bits[] = 'exp '.substr((string) $ts['valid_to'], 0, 16);
        }

        return [
            'primary' => $primary,
            'secondary' => $bits !== [] ? implode(' · ', $bits) : null,
        ];
    }

    public function render()
    {
        return view('livewire.vhosts', [
            'filteredVhosts' => $this->filteredVhosts(),
        ])->layoutData([
            'heading' => 'Virtual hosts',
            'sub' => 'Caddy is the front door on :80/:443. Each vhost chooses Caddy, Apache, or Nginx as its engine. Reverse-proxy and protected vhosts are read-only.',
        ]);
    }
}
