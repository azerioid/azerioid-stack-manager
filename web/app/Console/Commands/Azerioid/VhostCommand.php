<?php

namespace App\Console\Commands\Azerioid;

use AzerioidPanel\Broker\Validator;
use Illuminate\Console\Command;

class VhostCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:vhost
        {action : list|add|edit|del}
        {--domain= : Vhost domain}
        {--type=php : php|static|proxy}
        {--php= : PHP version for php vhosts}
        {--root= : Document root}
        {--upstream= : Upstream host:port for proxy vhosts}
        {--tls= : Enable TLS on add (flag) or on|off for edit}
        {--tls-mode= : on|off for edit (alias of --tls=)}
        {--stack= : Filter list by caddy|apache|nginx}
        {--json : JSON output (list)}';

    protected $description = 'Manage virtual hosts via broker vhost.* actions';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listVhosts(),
            'add' => $this->addVhost(),
            'edit' => $this->editVhost(),
            'del', 'delete', 'rm' => $this->delVhost(),
            default => $this->invalidAction(),
        };
    }

    private function listVhosts(): int
    {
        try {
            $data = $this->brokerData('vhost.list', [], [], null, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        $vhosts = $data['vhosts'] ?? [];
        $stackFilter = strtolower(trim((string) $this->option('stack')));
        if ($stackFilter !== '') {
            $vhosts = array_values(array_filter($vhosts, function ($v) use ($stackFilter) {
                $stack = strtolower((string) ($v['stack'] ?? $v['web_server'] ?? ''));

                return match ($stackFilter) {
                    'caddy' => in_array($stack, ['caddy', 'lcmp', ''], true) || str_contains((string) ($v['source'] ?? ''), 'caddy'),
                    'apache' => in_array($stack, ['apache', 'lamp'], true),
                    'nginx' => $stack === 'nginx',
                    default => true,
                };
            }));
        }

        if ($this->wantsJson()) {
            return $this->emitData(['vhosts' => $vhosts]);
        }

        $rows = [];
        foreach ($vhosts as $v) {
            $rows[] = [
                (string) ($v['domain'] ?? ($v['domains'][0] ?? '')),
                (string) ($v['type'] ?? ''),
                (string) ($v['php_version'] ?? ''),
                (string) ($v['root'] ?? ''),
                ! empty($v['tls']) ? 'yes' : 'no',
                ! empty($v['readonly']) ? 'yes' : 'no',
                (string) ($v['stack'] ?? ''),
            ];
        }

        return $this->emitTable(['domain', 'type', 'php', 'root', 'tls', 'readonly', 'stack'], $rows);
    }

    private function addVhost(): int
    {
        try {
            $domain = Validator::domain((string) $this->option('domain'));
            $type = Validator::vhostType((string) $this->option('type'));
            $www = rtrim((string) config('azerioid.www_root', '/data/www'), '/');
            $root = (string) ($this->option('root') ?: ($www . '/' . $domain));
            $root = Validator::webRoot($root, $www, new \AzerioidPanel\Broker\FakeRuntime());
            $args = [$domain, $root, $type];
            if ($type === 'php') {
                $php = (string) $this->option('php');
                if ($php === '') {
                    $versions = $this->brokerData('php.versions', [], [], null, false);
                    $list = array_values(array_filter(array_map(
                        static fn ($row) => is_array($row) ? (string) ($row['version'] ?? '') : (string) $row,
                        $versions['versions'] ?? []
                    )));
                    if ($list === []) {
                        throw new \RuntimeException('No PHP versions available; install a PHP component or pass --php=.');
                    }
                    $php = (string) $list[array_key_last($list)];
                }
                $args[] = $php;
            } elseif ($type === 'proxy') {
                $upstream = (string) $this->option('upstream');
                if ($upstream === '') {
                    throw new \RuntimeException('--upstream=host:port is required for proxy vhosts.');
                }
                $args[] = $upstream;
            }

            $res = $this->brokerCall('vhost.add', $args);
            if (! $res->ok) {
                throw new \RuntimeException((string) $res->error);
            }

            // Optional TLS via edit after create (add path does not take tls flag in broker).
            $tlsOpt = $this->option('tls');
            $wantTls = $tlsOpt === true || $tlsOpt === '1' || $tlsOpt === 'on' || $tlsOpt === '';
            // Symfony treats bare --tls as true; --tls=on as "on"; absent as null/false.
            if ($this->input->hasParameterOption(['--tls'], true) && $wantTls) {
                $edit = $this->brokerCall('vhost.edit', [$domain], [
                    'domain' => $domain,
                    'tls' => true,
                ]);
                if (! $edit->ok) {
                    $this->line('Vhost created but TLS enable failed: ' . $edit->error);
                }
            }

            $this->info("Created vhost {$domain}.");
            if ($this->wantsJson() || is_array($res->data)) {
                $this->line(json_encode($res->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function editVhost(): int
    {
        try {
            $raw = trim((string) $this->option('domain'));
            $this->assertNotReadonlyVhost($raw, 'edit');
            $domain = Validator::domain($raw);
            $payload = ['domain' => $domain];
            if ($this->option('root')) {
                $payload['root'] = (string) $this->option('root');
            }
            if ($this->option('php')) {
                $payload['php_version'] = (string) $this->option('php');
            }
            $tlsMode = strtolower(trim((string) ($this->option('tls-mode') ?: '')));
            if ($tlsMode === '') {
                $tlsRaw = $this->option('tls');
                if ($tlsRaw === true || $tlsRaw === '') {
                    $tlsMode = 'on';
                } elseif (is_string($tlsRaw) && $tlsRaw !== '') {
                    $tlsMode = strtolower($tlsRaw);
                }
            }
            if (in_array($tlsMode, ['on', '1', 'true', 'yes'], true)) {
                $payload['tls'] = true;
            } elseif (in_array($tlsMode, ['off', '0', 'false', 'no'], true)) {
                $payload['tls'] = false;
            }

            $res = $this->brokerCall('vhost.edit', [$domain], $payload);
            if (! $res->ok) {
                throw new \RuntimeException((string) $res->error);
            }
            $this->line("Updated vhost {$domain}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function delVhost(): int
    {
        try {
            $raw = trim((string) $this->option('domain'));
            // Mirror the UI: refuse readonly/panel vhosts before mutate (covers listen
            // addresses like 127.0.0.1:3169 that fail Validator::domain but are listed).
            $this->assertNotReadonlyVhost($raw, 'delete');
            $domain = Validator::domain($raw);
            $res = $this->brokerCall('vhost.del', [$domain], []);
            if (! $res->ok) {
                throw new \RuntimeException((string) $res->error);
            }
            $this->line("Deleted vhost {$domain}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    /**
     * Same protection the dashboard applies (hide/refuse readonly sites) and that
     * CaddyDriver/ApacheDriver enforce after domain validation.
     */
    private function assertNotReadonlyVhost(string $domain, string $op = 'delete'): void
    {
        if ($domain === '') {
            return;
        }
        $data = $this->brokerData('vhost.list', [], [], null, false);
        foreach ((array) ($data['vhosts'] ?? []) as $v) {
            if (! is_array($v)) {
                continue;
            }
            $candidates = array_merge(
                [(string) ($v['domain'] ?? '')],
                array_map('strval', (array) ($v['domains'] ?? []))
            );
            $match = false;
            foreach ($candidates as $c) {
                if ($c !== '' && strcasecmp($c, $domain) === 0) {
                    $match = true;
                    break;
                }
            }
            if (! $match) {
                continue;
            }
            if (! empty($v['readonly'])) {
                $msg = $op === 'edit'
                    ? "{$domain} is managed externally and can't be edited."
                    : 'This vhost is managed externally and cannot be deleted by the panel.';
                throw new \RuntimeException($msg);
            }
        }
    }

    private function invalidAction(): int
    {
        $this->error('Unknown vhost action. Use: list|add|edit|del');

        return self::INVALID;
    }
}
