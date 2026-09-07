<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Models\User;
use App\Services\Broker\BrokerClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Settings · AZERIOID Stack Manager')]
class SettingsPage extends Component
{
    public string $name = '';
    public string $email = '';
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';
    public int $idle = 15;
    public int $retention = 90;
    public string $ipAllowlist = '';
    public ?string $flash = null;
    public array $phpIni = [];
    public string $phpVersion = '';
    public array $phpVersions = [];
    public array $opcache = [];
    public array $panelRuntime = [];
    public array $dnsProviders = [];
    public string $dnsRotateProvider = '';
    public string $dnsRotateToken = '';
    public ?string $dnsError = null;

    public function mount(BrokerClient $broker): void
    {
        $user = Auth::user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->idle = (int) Setting::get('session_timeout_minutes', config('azerioid.session_idle_minutes', 15));
        $this->retention = (int) Setting::get('audit_retention_days', 90);
        $ips = Setting::get('ip_allowlist', []);
        $this->ipAllowlist = is_array($ips) ? implode("\n", $ips) : '';
        $runtime = $broker->call('panel.runtime');
        if ($runtime->ok) {
            $this->panelRuntime = $runtime->data ?? [];
        }
        $php = $broker->call('php.versions');
        if ($php->ok) {
            $this->phpVersions = array_column($php->data['versions'] ?? [], 'version');
            $this->phpVersion = $this->phpVersions[array_key_last($this->phpVersions)] ?? '';
            if ($this->phpVersion !== '') {
                $ini = $broker->call('php.ini.get', [$this->phpVersion]);
                $this->phpIni = $ini->ok ? ($ini->data['values'] ?? []) : [];
                $this->loadOpcache($broker);
            }
        }
        $this->reloadDnsProviders($broker);
    }

    public function rotateDnsCredential(BrokerClient $broker): void
    {
        $this->dnsError = null;
        $provider = strtolower(trim($this->dnsRotateProvider));
        $token = trim($this->dnsRotateToken);
        if ($provider === '') {
            $this->dnsError = 'Choose a DNS provider.';

            return;
        }
        if (strlen($token) < 8) {
            $this->dnsError = 'Enter a new API token (existing secret is never shown).';

            return;
        }
        $res = $broker->call('tls.dns-credential.store', [], [
            'provider' => $provider,
            'token' => $token,
        ]);
        if (! $res->ok) {
            $this->dnsError = (string) $res->error;

            return;
        }
        $this->reset('dnsRotateToken');
        $this->flash = "DNS credentials stored for {$provider} (token not displayed).";
        $this->reloadDnsProviders($broker);
    }

    private function reloadDnsProviders(BrokerClient $broker): void
    {
        $res = $broker->call('tls.dns-providers', [], [], null, false);
        if ($res->ok) {
            $this->dnsProviders = $res->data['providers'] ?? [];
        } else {
            $this->dnsProviders = [
                ['id' => 'cloudflare', 'display_name' => 'Cloudflare', 'credentials_present' => false],
                ['id' => 'digitalocean', 'display_name' => 'DigitalOcean', 'credentials_present' => false],
            ];
        }
    }

    public function saveProfile(): void
    {
        $user = Auth::user();
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190'],
        ]);
        $user->forceFill(['name' => $this->name, 'email' => $this->email])->save();
        $this->flash = 'Profile updated.';
    }

    public function savePassword(): void
    {
        $user = Auth::user();
        $this->validate([
            'current_password' => ['required'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if (! Hash::check($this->current_password, $user->password)) {
            $this->addError('current_password', 'Current password is incorrect.');
            return;
        }
        $user->forceFill(['password' => Hash::make($this->password)])->save();
        $this->reset('current_password', 'password', 'password_confirmation');
        $this->flash = 'Password updated.';
    }

    public function savePanel(): void
    {
        $this->validate([
            'idle' => ['required', 'integer', 'min:5', 'max:120'],
            'retention' => ['required', 'integer', 'min:7', 'max:365'],
        ]);
        $ips = array_values(array_filter(array_map('trim', preg_split('/\R/', $this->ipAllowlist) ?: [])));
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $this->addError('ipAllowlist', "Invalid IP: {$ip}");
                return;
            }
        }
        Setting::put('session_timeout_minutes', $this->idle);
        Setting::put('audit_retention_days', $this->retention);
        Setting::put('ip_allowlist', $ips);
        session(['idle_minutes' => $this->idle]);
        $this->flash = 'Panel settings saved.';
    }

    public function saveIni(BrokerClient $broker, string $key, string $value): void
    {
        $res = $broker->call('php.ini.set', [$this->phpVersion, $key, $value]);
        $this->flash = $res->ok ? "Updated {$key}." : null;
        if (! $res->ok) {
            $this->addError('phpIni', $res->error);
        }
    }

    public function loadOpcache(BrokerClient $broker): void
    {
        if ($this->phpVersion === '') {
            return;
        }
        $res = $broker->call('php.opcache.stats', [$this->phpVersion], [], null, false);
        $this->opcache = $res->ok ? ($res->data ?? []) : [];
    }

    public function resetOpcache(BrokerClient $broker): void
    {
        $res = $broker->call('php.opcache.reset', [$this->phpVersion]);
        $this->flash = $res->ok ? 'OPcache reset for PHP '.$this->phpVersion.'.' : null;
        if (! $res->ok) {
            $this->addError('phpIni', $res->error);
        }
        $this->loadOpcache($broker);
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.settings', [
            'totpRequired' => (bool) config('azerioid.require_totp'),
            'totpEnrolled' => $user instanceof User && $user->hasTwoFactorEnabled(),
        ])->layoutData([
            'heading' => 'Settings',
            'sub' => 'Admin, session, panel runtime, DNS-01 credentials, php.ini',
        ]);
    }
}
