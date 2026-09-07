<?php

namespace App\Livewire;

use App\Services\Broker\BrokerClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Security · AZERIOID Stack Manager')]
class SecurityPage extends Component
{
    public array $auth = [];
    public array $firewall = [];
    public string $unban_ip = '';
    public string $unban_jail = 'sshd';
    public string $confirm = '';
    public string $bind_backup = '';
    public string $crontab_text = '';
    public ?string $flash = null;
    public ?string $error = null;

    public function mount(BrokerClient $broker): void
    {
        $this->reload($broker);
    }

    public function reload(BrokerClient $broker): void
    {
        $a = $broker->call('auth.audit', [], [], null, false);
        $this->auth = $a->ok ? $a->data : [];
        $f = $broker->call('firewall.status', [], [], null, false);
        $this->firewall = $f->ok ? $f->data : [];
        $c = $broker->call('cron.list', [], [], null, false);
        if ($c->ok) {
            $this->crontab_text = implode("\n", $c->data['lines'] ?? []);
        }
    }

    public function unban(BrokerClient $broker): void
    {
        $res = $broker->call('firewall.unban', [$this->unban_ip, $this->unban_jail]);
        $this->flash = $res->ok ? 'Unbanned '.$this->unban_ip : null;
        $this->error = $res->ok ? null : $res->error;
        $this->reload($broker);
    }

    public function installFail2ban(BrokerClient $broker): void
    {
        $res = $broker->call('firewall.fail2ban.install', [], ['confirm' => $this->confirm], 300);
        $this->confirm = '';
        $this->flash = $res->ok ? 'fail2ban installed.' : null;
        $this->error = $res->ok ? null : $res->error;
        $this->reload($broker);
    }

    public function rollbackMariadb(BrokerClient $broker): void
    {
        $res = $broker->call('mariadb.bind.rollback', [$this->bind_backup]);
        $this->flash = $res->ok ? 'MariaDB bind-address restored from backup.' : null;
        $this->error = $res->ok ? null : $res->error;
    }

    public function saveCron(BrokerClient $broker): void
    {
        $lines = preg_split('/\R/', $this->crontab_text) ?: [];
        $res = $broker->call('cron.set', [], ['lines' => $lines]);
        $this->flash = $res->ok ? 'Root crontab updated.' : null;
        $this->error = $res->ok ? null : $res->error;
        $this->reload($broker);
    }

    public function render()
    {
        return view('livewire.security')->layoutData([
            'heading' => 'Security',
            'sub' => 'SSH audit · UFW · fail2ban · root cron',
        ]);
    }
}
