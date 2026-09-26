<?php

namespace App\Livewire;

use App\Services\Broker\BrokerClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Firewall rules (B2 / request #2).
 *
 * The Security page reports what the firewall looks like; this changes it. Its own
 * page rather than another block there, because a rule change needs the confirm /
 * revert state visible the whole time it is pending — the operator has to be able to
 * see that something is waiting on them.
 *
 * Every refusal shown here comes from the broker (A46). Nothing is hidden to make it
 * safe: the same actions are reachable from the CLI.
 */
#[Layout('layouts.app')]
#[Title('Firewall · AZERIOID Stack Manager')]
class FirewallPage extends Component
{
    /** @var array<string,mixed> */
    public array $state = [];

    public string $action = 'allow';

    public string $port = '';

    public string $protocol = 'tcp';

    public string $source = '';

    public string $note = '';

    public string $revertAfter = '120';

    public string $confirm = '';

    public bool $withoutRevert = false;

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(BrokerClient $broker): void
    {
        $this->reload($broker);
    }

    /**
     * Refreshing must never overwrite the error from the action that just ran: every
     * write below reloads afterwards, and a successful listing would otherwise wipe
     * the refusal the operator needs to read.
     */
    public function reload(BrokerClient $broker): void
    {
        $res = $broker->call('firewall.rules', [], [], 30, false);
        $this->state = $res->ok && is_array($res->data) ? $res->data : [];
        // A host with no active firewall is a normal state, not an error to shout
        // about — but the reason has to reach the operator, because every button on
        // this page will otherwise fail for a reason they cannot see.
        if (! $res->ok && $this->error === null) {
            $this->error = $res->error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function rules(): array
    {
        $rules = $this->state['rules'] ?? [];

        return is_array($rules) ? $rules : [];
    }

    /** @return array<string,mixed> */
    public function revert(): array
    {
        $revert = $this->state['revert'] ?? ['armed' => false];

        return is_array($revert) ? $revert : ['armed' => false];
    }

    public function add(BrokerClient $broker): void
    {
        $this->error = null;
        $this->flash = null;
        $res = $broker->call('firewall.rule.add', [], $this->payload(), 60);
        if (! $res->ok) {
            $this->error = $res->error;
            $this->reload($broker);

            return;
        }
        $this->flash = $this->outcome('Added', $res->data);
        $this->port = '';
        $this->source = '';
        $this->note = '';
        $this->confirm = '';
        $this->reload($broker);
    }

    public function remove(BrokerClient $broker, string $action, int $port, string $protocol, ?string $source = null): void
    {
        $this->error = null;
        $this->flash = null;
        $res = $broker->call('firewall.rule.delete', [], [
            'action' => $action,
            'port' => $port,
            'protocol' => $protocol,
            'source' => $source,
        ] + $this->windowPayload(), 60);
        if (! $res->ok) {
            $this->error = $res->error;
            $this->reload($broker);

            return;
        }
        $this->flash = $this->outcome('Removed', $res->data);
        $this->confirm = '';
        $this->reload($broker);
    }

    public function confirmChange(BrokerClient $broker): void
    {
        $res = $broker->call('firewall.confirm', [], [], 30);
        $this->flash = $res->ok ? 'Change confirmed; it will not be reverted.' : null;
        $this->error = $res->ok ? null : $res->error;
        $this->reload($broker);
    }

    public function revertChange(BrokerClient $broker): void
    {
        $res = $broker->call('firewall.revert', [], [], 60);
        $this->flash = $res->ok ? 'Previous rules restored.' : null;
        $this->error = $res->ok ? null : $res->error;
        $this->reload($broker);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'action' => $this->action,
            'port' => trim($this->port),
            'protocol' => $this->protocol,
            'source' => trim($this->source),
            'note' => trim($this->note),
        ] + $this->windowPayload();
    }

    /** @return array<string,mixed> */
    private function windowPayload(): array
    {
        if ($this->withoutRevert) {
            return ['revert' => false, 'confirm' => trim($this->confirm)];
        }

        return ['revert_after' => (int) $this->revertAfter, 'confirm' => trim($this->confirm)];
    }

    /** @param mixed $data */
    private function outcome(string $verb, mixed $data): string
    {
        $armed = is_array($data) && (bool) ($data['revert']['armed'] ?? false);
        if (! $armed) {
            return $verb . '. No automatic revert was armed.';
        }
        $seconds = is_array($data) ? (int) ($data['revert']['seconds'] ?? 0) : 0;

        return $verb . '. Confirm within ' . $seconds
            . ' seconds or the previous rules are restored automatically.';
    }

    public function render()
    {
        return view('livewire.firewall')->layoutData([
            'heading' => 'Firewall',
            'sub' => 'rules on ufw or firewalld · protected ports · timed revert',
        ]);
    }
}
