<?php

namespace App\Console\Commands\Azerioid;

use AzerioidPanel\Broker\Tls\TlsMode;
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
        {--tls= : off|auto|internal|dns01 (aliases: on=auto, dns=dns01, self=internal)}
        {--tls-mode= : Alias of --tls=}
        {--dns-provider= : cloudflare|digitalocean (for --tls=dns01)}
        {--wildcard : Also request *.apex for DNS-01}
        {--staging : Use Let\'s Encrypt staging}
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
            // Ensure tls_status fields are present for scripting.
            $safe = array_map(static function ($row) {
                if (! is_array($row)) {
                    return $row;
                }
                $ts = is_array($row['tls_status'] ?? null) ? $row['tls_status'] : [];
                $row['tls_status'] = [
                    'enabled' => (bool) ($ts['enabled'] ?? ! empty($row['tls'])),
                    'mode' => (string) ($ts['mode'] ?? ($row['tls_mode'] ?? 'off')),
                    'issuer_type' => (string) ($ts['issuer_type'] ?? 'none'),
                    'issuer' => $ts['issuer'] ?? null,
                    'valid_to' => $ts['valid_to'] ?? null,
                    'days_remaining' => $ts['days_remaining'] ?? null,
                    'ok' => (bool) ($ts['ok'] ?? false),
                    'pending' => (bool) ($ts['pending'] ?? false),
                    'failed' => (bool) ($ts['failed'] ?? false),
                    'label' => (string) ($ts['label'] ?? (! empty($row['tls']) ? 'tls' : 'http')),
                ];

                return $row;
            }, is_array($vhosts) ? $vhosts : []);

            return $this->emitData(['vhosts' => $safe]);
        }

        $rows = [];
        foreach ($vhosts as $v) {
            $rows[] = [
                (string) ($v['domain'] ?? ($v['domains'][0] ?? '')),
                (string) ($v['type'] ?? ''),
                (string) ($v['php_version'] ?? ''),
                (string) ($v['root'] ?? ''),
                ! empty($v['tls_status']['label'])
                    ? (string) $v['tls_status']['label']
                    : (! empty($v['tls']) ? (string) ($v['tls_mode'] ?? 'yes') : 'http'),
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

            $tlsPayload = $this->buildTlsPayload($domain);
            if ($tlsPayload !== null) {
                $edit = $this->brokerCall('vhost.edit', [$domain], $tlsPayload);
                if (! $edit->ok) {
                    $this->line('Vhost created but TLS configure failed: ' . $edit->error);

                    return self::FAILURE;
                }
            }

            $this->line("Created vhost {$domain}.");
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
            $tlsPayload = $this->buildTlsPayload($domain);
            if ($tlsPayload !== null) {
                $payload = array_merge($payload, $tlsPayload);
            } elseif (! $this->option('root') && ! $this->option('php')) {
                throw new \RuntimeException('Nothing to edit. Pass --root=, --php=, and/or --tls=.');
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
     * @return array<string,mixed>|null null when TLS flags were not provided
     */
    private function buildTlsPayload(string $domain): ?array
    {
        $raw = $this->option('tls-mode');
        if ($raw === null || $raw === false || $raw === '') {
            if (! $this->input->hasParameterOption(['--tls'], true)) {
                return null;
            }
            $raw = $this->option('tls');
        }
        // Bare --tls (boolean true) means auto.
        if ($raw === true || $raw === null || $raw === '') {
            $raw = 'auto';
        }
        $mode = $this->normalizeTlsCli((string) $raw);
        $payload = [
            'domain' => $domain,
            'tls_mode' => $mode,
            'tls' => TlsMode::enabled($mode),
        ];
        if ($mode === TlsMode::DNS01) {
            $provider = strtolower(trim((string) $this->option('dns-provider')));
            if ($provider === '') {
                throw new \RuntimeException('--dns-provider=cloudflare|digitalocean is required with --tls=dns01.');
            }
            $payload['dns_provider'] = $provider;
            $token = (string) (getenv('AZERIOID_DNS_API_TOKEN') ?: getenv('DNS_API_TOKEN') ?: '');
            if ($token !== '') {
                $store = $this->brokerCall('tls.dns-credential.store', [], [
                    'provider' => $provider,
                    'token' => $token,
                ]);
                if (! $store->ok) {
                    throw new \RuntimeException((string) $store->error);
                }
                $this->line("Stored DNS credentials for {$provider} (token not echoed).");
            }
            if ($this->option('wildcard')) {
                $payload['wildcard'] = true;
            }
        }
        if ($this->option('staging')) {
            $payload['staging'] = true;
        }

        return $payload;
    }

    private function normalizeTlsCli(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return match ($raw) {
            'auto', 'on', '1', 'true', 'yes', 'http01', 'http-01', 'le', 'letsencrypt' => TlsMode::AUTO,
            'off', '0', 'false', 'no', 'http' => TlsMode::OFF,
            'self', 'self-signed', 'selfsigned', 'internal', 'snakeoil' => TlsMode::INTERNAL,
            'dns', 'dns01', 'dns-01', 'wildcard' => TlsMode::DNS01,
            default => throw new \RuntimeException('--tls must be off|auto|internal|dns01 (aliases: dns, self, on).'),
        };
    }

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
