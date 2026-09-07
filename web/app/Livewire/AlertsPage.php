<?php

namespace App\Livewire;

use App\Models\AlertIncident;
use App\Models\Setting;
use App\Services\Alerts\TelegramNotifier;
use App\Services\Broker\BrokerClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Alerts · AZERIOID Stack Manager')]
class AlertsPage extends Component
{
    public bool $service_down = true;
    public bool $observed_down = true;
    public bool $reboot_required = true;
    public bool $tls = true;
    public bool $backup_stale = true;
    public bool $ssh = true;
    public int $disk_percent = 85;
    public int $ram_percent = 90;
    public float $load = 4.0;
    public int $tls_days = 14;
    public int $backup_stale_hours = 36;
    public string $chat_id = '';
    public string $bot_token = '';
    public bool $token_set = false;
    public ?string $flash = null;
    public ?string $error = null;
    /** @var list<array<string,mixed>> */
    public array $incidents = [];

    public function mount(): void
    {
        $rules = Setting::get('alert.rules', []);
        if (is_array($rules)) {
            $this->service_down = (bool) ($rules['service_down'] ?? true);
            $this->observed_down = (bool) ($rules['observed_down'] ?? true);
            $this->reboot_required = (bool) ($rules['reboot_required'] ?? true);
            $this->tls = (bool) ($rules['tls'] ?? true);
            $this->backup_stale = (bool) ($rules['backup_stale'] ?? true);
            $this->ssh = (bool) ($rules['ssh'] ?? true);
            $this->disk_percent = (int) ($rules['disk_percent'] ?? 85);
            $this->ram_percent = (int) ($rules['ram_percent'] ?? 90);
            $this->load = (float) ($rules['load'] ?? 4);
            $this->tls_days = (int) ($rules['tls_days'] ?? 14);
            $this->backup_stale_hours = (int) ($rules['backup_stale_hours'] ?? 36);
        }
        $this->chat_id = (string) Setting::get('telegram.chat_id', '');
        $this->token_set = Setting::secretIsSet('telegram.bot_token');
        $this->reloadIncidents();
    }

    public function saveRules(): void
    {
        $this->validate([
            'disk_percent' => ['integer', 'min:50', 'max:99'],
            'ram_percent' => ['integer', 'min:50', 'max:99'],
            'load' => ['numeric', 'min:0.1', 'max:128'],
            'tls_days' => ['integer', 'min:1', 'max:90'],
            'backup_stale_hours' => ['integer', 'min:1', 'max:168'],
        ]);
        Setting::put('alert.rules', [
            'service_down' => $this->service_down,
            'observed_down' => $this->observed_down,
            'reboot_required' => $this->reboot_required,
            'tls' => $this->tls,
            'backup_stale' => $this->backup_stale,
            'ssh' => $this->ssh,
            'disk_percent' => $this->disk_percent,
            'ram_percent' => $this->ram_percent,
            'load' => $this->load,
            'tls_days' => $this->tls_days,
            'backup_stale_hours' => $this->backup_stale_hours,
        ]);
        $this->flash = 'Alert rules saved.';
    }

    public function saveTelegram(): void
    {
        $this->validate(['chat_id' => ['required', 'string', 'max:40']]);
        Setting::put('telegram.chat_id', $this->chat_id);
        if ($this->bot_token !== '') {
            if (! preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $this->bot_token)) {
                $this->addError('bot_token', 'That does not look like a Telegram bot token.');
                return;
            }
            Setting::putSecret('telegram.bot_token', $this->bot_token);
            $this->bot_token = '';
            $this->token_set = true;
        }
        $this->flash = 'Telegram settings saved. Token is stored encrypted.';
    }

    public function testTelegram(TelegramNotifier $telegram): void
    {
        if ($telegram->send('AZERIOID Stack Manager test message')) {
            $this->flash = 'Test message sent.';
            $this->error = null;
        } else {
            $this->error = 'Telegram send failed. Check token and chat ID.';
        }
    }

    public function installScheduler(BrokerClient $broker): void
    {
        $res = $broker->call('scheduler.install');
        $this->flash = $res->ok ? 'Scheduler cron installed.' : null;
        $this->error = $res->ok ? null : $res->error;
    }

    public function reloadIncidents(): void
    {
        $this->incidents = AlertIncident::query()->latest('opened_at')->limit(40)->get()->toArray();
    }

    public function render()
    {
        return view('livewire.alerts')->layoutData([
            'heading' => 'Alerts',
            'sub' => 'Telegram · thresholds · incident history',
        ]);
    }
}
