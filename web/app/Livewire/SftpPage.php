<?php

namespace App\Livewire;

use App\Services\Broker\BrokerClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Per-vhost SFTP (A48 / request #11).
 *
 * Its own page rather than a switch on the vhost row, because turning SFTP on for a site is
 * meaningless until a key is installed — the two belong next to each other, or an operator enables
 * access that nobody can use and has no reason to look for the second step.
 *
 * Every guarantee is in the broker: drop-in only, `sshd -t` before every reload, reload never
 * restart, and a Match block that cannot match root. This page adds none of its own.
 */
#[Layout('layouts.app')]
#[Title('SFTP · AZERIOID Stack Manager')]
class SftpPage extends Component
{
    /** @var array<string,mixed> */
    public array $state = [];

    /** @var list<array<string,mixed>> */
    public array $vhosts = [];

    public ?string $keysFor = null;

    /** @var list<array<string,mixed>> */
    public array $keys = [];

    public string $newKey = '';

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(BrokerClient $broker): void
    {
        $this->reload($broker);
    }

    /**
     * Never clears $this->error — the write that called it has already set one, and clearing it here
     * is how a refusal reaches the operator as silence (see the audit in ErrorSurvivesReloadTest).
     */
    public function reload(BrokerClient $broker): void
    {
        $res = $broker->call('sftp.status', [], [], 30, false);
        $this->state = $res->ok && is_array($res->data) ? $res->data : [];
        if (! $res->ok && $this->error === null) {
            $this->error = $res->error;
        }

        $list = $broker->call('vhost.list', [], [], 30, false);
        $this->vhosts = $list->ok
            ? array_values(array_filter(
                (array) ($list->data['vhosts'] ?? []),
                static fn ($v): bool => is_array($v) && empty($v['readonly'])
            ))
            : [];
    }

    public function enabledFor(string $domain): bool
    {
        $user = 'az-vh-' . str_replace('.', '-', $domain);

        return in_array($user, (array) ($this->state['sites'] ?? []), true);
    }

    public function configure(BrokerClient $broker): void
    {
        $this->error = null;
        $this->flash = null;
        $res = $broker->call('sftp.configure', [], [], 120);
        $this->flash = $res->ok ? 'SFTP configured; sshd reloaded. Your own SSH access is unchanged.' : null;
        if (! $res->ok) {
            $this->error = $res->error;
        }
        $this->reload($broker);
    }

    public function unconfigure(BrokerClient $broker): void
    {
        $this->error = null;
        $this->flash = null;
        $res = $broker->call('sftp.unconfigure', [], [], 120);
        $this->flash = $res->ok ? 'Panel-managed SFTP removed; sshd reloaded.' : null;
        if (! $res->ok) {
            $this->error = $res->error;
        }
        $this->reload($broker);
    }

    public function toggle(BrokerClient $broker, string $domain, bool $enable): void
    {
        $this->error = null;
        $this->flash = null;
        $res = $broker->call($enable ? 'sftp.enable' : 'sftp.disable', [$domain], [], 120);
        if (! $res->ok) {
            $this->error = $res->error;
        } else {
            $this->flash = $enable
                ? 'SFTP enabled for ' . $domain . '. Install a key below — nobody can connect until you do.'
                : 'SFTP disabled for ' . $domain . '.';
            if ($enable) {
                $this->showKeys($broker, $domain);
            }
        }
        $this->reload($broker);
    }

    public function showKeys(BrokerClient $broker, string $domain): void
    {
        if ($this->keysFor === $domain) {
            $this->keysFor = null;
            $this->keys = [];

            return;
        }
        $res = $broker->call('sftp.key.list', [$domain], [], 30, false);
        $this->keysFor = $domain;
        $this->keys = $res->ok ? array_values((array) ($res->data['keys'] ?? [])) : [];
        if (! $res->ok) {
            $this->error = $res->error;
        }
    }

    public function addKey(BrokerClient $broker): void
    {
        if ($this->keysFor === null) {
            return;
        }
        $this->error = null;
        $this->flash = null;
        $domain = $this->keysFor;
        $res = $broker->call('sftp.key.add', [$domain], ['key' => trim($this->newKey)], 60);
        if (! $res->ok) {
            $this->error = $res->error;
        } else {
            $this->flash = 'Key installed: ' . (string) ($res->data['fingerprint'] ?? '');
            $this->newKey = '';
        }
        $this->keysFor = null;
        $this->showKeys($broker, $domain);
    }

    public function removeKey(BrokerClient $broker, string $fingerprint): void
    {
        if ($this->keysFor === null) {
            return;
        }
        $this->error = null;
        $this->flash = null;
        $domain = $this->keysFor;
        $res = $broker->call('sftp.key.del', [$domain, $fingerprint], [], 60);
        if (! $res->ok) {
            $this->error = $res->error;
        } else {
            $this->flash = 'Key removed.';
        }
        $this->keysFor = null;
        $this->showKeys($broker, $domain);
    }

    public function render()
    {
        return view('livewire.sftp')->layoutData([
            'heading' => 'SFTP',
            'sub' => 'per-site file transfer · keys only · no shell',
        ]);
    }
}
