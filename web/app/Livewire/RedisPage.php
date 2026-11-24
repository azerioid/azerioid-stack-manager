<?php

namespace App\Livewire;

use App\Services\Broker\BrokerClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Per-site Redis users (A111).
 *
 * A site gets its own Redis user, limited to keys and channels under its own prefix. The
 * credentials are shown here as .env lines when the user is created or on request; they live
 * root-only on the host and nowhere in the panel's database.
 *
 * Until the operator enforces per-site users, Redis keeps answering clients without a password
 * (sites that use it today do exactly that), so the order is: enable each site, update its .env,
 * then enforce.
 */
#[Layout('layouts.app')]
#[Title('Redis · AZERIOID Stack Manager')]
class RedisPage extends Component
{
    /** @var array<string,mixed> */
    public array $state = [];

    /** @var list<array<string,mixed>> */
    public array $vhosts = [];

    /** @var array<string,string>|null connection settings being shown, password included */
    public ?array $shown = null;

    public string $confirm = '';

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(BrokerClient $broker): void
    {
        $this->reload($broker);
    }

    /** Never clears $this->error (see SftpPage). */
    public function reload(BrokerClient $broker): void
    {
        $res = $broker->call('redis.status', [], [], 30, false);
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
        foreach ((array) ($this->state['sites'] ?? []) as $site) {
            if (($site['domain'] ?? null) === $domain) {
                return true;
            }
        }

        return false;
    }

    public function enable(BrokerClient $broker, string $domain): void
    {
        $this->error = null;
        $this->flash = null;
        $res = $broker->call('redis.site.enable', [$domain], [], 120);
        if (! $res->ok) {
            $this->error = $res->error;
        } else {
            $this->shown = is_array($res->data) ? array_map('strval', $res->data) : null;
            $this->flash = 'Redis user created for ' . $domain . '. Put these lines in the site\'s .env.';
        }
        $this->reload($broker);
    }

    public function show(BrokerClient $broker, string $domain): void
    {
        if (($this->shown['domain'] ?? null) === $domain) {
            $this->shown = null;

            return;
        }
        $this->error = null;
        $res = $broker->call('redis.site.show', [$domain], [], 30);
        $this->shown = $res->ok && is_array($res->data) ? array_map('strval', $res->data) : null;
        if (! $res->ok) {
            $this->error = $res->error;
        }
    }

    public function disable(BrokerClient $broker, string $domain): void
    {
        $this->error = null;
        $this->flash = null;
        $res = $broker->call('redis.site.disable', [$domain], [], 120);
        if (! $res->ok) {
            $this->error = $res->error;
        } else {
            $this->flash = 'Redis user removed for ' . $domain . '.';
            if (($this->shown['domain'] ?? null) === $domain) {
                $this->shown = null;
            }
        }
        $this->reload($broker);
    }

    public function enforce(BrokerClient $broker): void
    {
        $this->error = null;
        $this->flash = null;
        $res = $broker->call('redis.enforce', [], ['confirm' => trim($this->confirm)], 120);
        if (! $res->ok) {
            $this->error = $res->error;
        } else {
            $this->flash = 'Per-site users enforced: Redis no longer answers a client without credentials.';
            $this->confirm = '';
        }
        $this->reload($broker);
    }

    public function envLines(): string
    {
        if ($this->shown === null) {
            return '';
        }

        return implode("\n", [
            'REDIS_HOST=' . ($this->shown['host'] ?? '127.0.0.1'),
            'REDIS_PORT=' . ($this->shown['port'] ?? '6379'),
            'REDIS_USERNAME=' . ($this->shown['user'] ?? ''),
            'REDIS_PASSWORD=' . ($this->shown['password'] ?? ''),
            'REDIS_PREFIX=' . ($this->shown['prefix'] ?? ''),
        ]);
    }

    public function render()
    {
        return view('livewire.redis')->layoutData([
            'heading' => 'Redis',
            'sub' => 'one user per site · keys under its own prefix only',
        ]);
    }
}
