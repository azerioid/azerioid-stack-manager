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
    public ?string $error = null;
    public ?string $flash = null;
    public ?string $confirmDelete = null;
    public bool $removeSupervisorOnDelete = false;
    public array $supervisorByVhost = [];
    public bool $showForm = false;

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

    public function create(BrokerClient $broker): void
    {
        $this->error = null;
        try {
            $domain = Validator::domain($this->domain);
            $root = Validator::webRoot($this->root, (string) config('azerioid.www_root'), new \AzerioidPanel\Broker\FakeRuntime());
            $type = Validator::vhostType($this->type);
            $args = [$domain, $root, $type];
            if ($type === 'php') {
                $args[] = Validator::phpVersion($this->php_version, $this->phpVersions);
            } elseif ($type === 'proxy') {
                $args[] = Validator::localUpstream($this->upstream);
            }
            $res = $broker->call('vhost.add', $args, [
                'engine' => $type === 'proxy' ? 'caddy' : Validator::vhostEngine($this->engine),
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
            $this->flash = "Created {$domain}.";
            $this->reset('domain', 'root', 'type', 'upstream', 'engine', 'tlsMode', 'dnsProvider', 'dnsToken', 'acmeStaging', 'wildcard', 'showForm');
            $this->reload($broker);
        } catch (\Throwable $e) {
            $this->error = $this->operatorMessage($e->getMessage());
        }
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
    }

    public function delete(BrokerClient $broker, ?string $domain = null): void
    {
        $domain = Validator::domain($domain ?? $this->confirmDelete ?? '');
        $stdin = [];
        if ($this->removeSupervisorOnDelete) {
            $stdin['remove_supervisor_programs'] = true;
        }
        $res = $broker->call('vhost.del', [Validator::domain($domain)], $stdin);
        if (! $res->ok) {
            $this->error = $this->operatorMessage((string) $res->error);
        } else {
            $this->flash = "Deleted {$domain}. Website files were left in place.";
        }
        $this->confirmDelete = null;
        $this->removeSupervisorOnDelete = false;
        $this->reload($broker);
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

    public function render()
    {
        return view('livewire.vhosts')->layoutData([
            'heading' => 'Virtual hosts',
            'sub' => 'Caddy is the front door on :80/:443. Each vhost chooses Caddy, Apache, or Nginx as its engine. Reverse-proxy and protected vhosts are read-only.',
        ]);
    }
}
