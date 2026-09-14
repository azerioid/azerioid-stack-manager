<?php

namespace App\Console\Commands\Azerioid;

use AzerioidPanel\Broker\Tls\TlsMode;
use AzerioidPanel\Broker\Validator;
use Illuminate\Console\Command;

class VhostCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:vhost
        {action : list|add|edit|del|files|octane}
        {filesOp? : list|read|write|delete|mkdir|rename (with files); enable|disable|reload|status (with octane)}
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
        {--engine= : caddy|apache|nginx (add/edit; also filters list). Proxy vhosts always use Caddy}
        {--stack= : Alias of --engine= when listing}
        {--path= : Relative path inside the vhost (files)}
        {--dest= : Destination relative path (files rename)}
        {--recursive : Recursive delete of a directory (files delete)}
        {--max-requests= : Requests per Octane worker before it is recycled (octane enable)}
        {--port= : Loopback port for the Octane worker, 34000-34999 (octane enable)}
        {--json : JSON output (list / files list / octane)}';

    protected $description = 'Manage virtual hosts via broker vhost.* actions';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listVhosts(),
            'add' => $this->addVhost(),
            'edit' => $this->editVhost(),
            'del', 'delete', 'rm' => $this->delVhost(),
            'files' => $this->files(),
            'octane' => $this->octane(),
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
        $stackFilter = strtolower(trim((string) $this->option('engine')));
        if ($stackFilter === '') {
            $stackFilter = strtolower(trim((string) $this->option('stack')));
        }
        if ($stackFilter !== '') {
            $vhosts = array_values(array_filter($vhosts, function ($v) use ($stackFilter) {
                $engine = strtolower((string) ($v['engine'] ?? $v['stack'] ?? $v['web_server'] ?? 'caddy'));

                return match ($stackFilter) {
                    'caddy' => in_array($engine, ['caddy', 'lcmp', ''], true),
                    'apache' => in_array($engine, ['apache', 'lamp', 'httpd'], true),
                    'nginx' => $engine === 'nginx',
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
                    'valid_from' => $ts['valid_from'] ?? null,
                    'valid_to' => $ts['valid_to'] ?? null,
                    'days_remaining' => $ts['days_remaining'] ?? null,
                    'ok' => (bool) ($ts['ok'] ?? false),
                    'pending' => (bool) ($ts['pending'] ?? false),
                    'failed' => (bool) ($ts['failed'] ?? false),
                    'error' => $ts['error'] ?? null,
                    'label' => (string) ($ts['label'] ?? (! empty($row['tls']) ? 'tls' : 'No TLS')),
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
                (string) ($v['engine'] ?? $v['stack'] ?? 'caddy'),
                ($v['runtime'] ?? 'fpm') === 'octane'
                    ? 'octane:'.(string) ($v['octane_port'] ?? '?')
                    : (string) ($v['runtime'] ?? 'fpm'),
            ];
        }

        return $this->emitTable(['domain', 'type', 'php', 'root', 'tls', 'readonly', 'engine', 'runtime'], $rows);
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

            $engine = $type === 'proxy' ? 'caddy' : Validator::vhostEngine((string) ($this->option('engine') ?: 'caddy'));
            $res = $this->brokerCall('vhost.add', $args, ['engine' => $engine]);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
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
            if ($this->option('engine')) {
                $payload['engine'] = Validator::vhostEngine((string) $this->option('engine'));
            }
            if ($this->option('upstream')) {
                $payload['upstream'] = Validator::localUpstream((string) $this->option('upstream'));
            }
            $tlsPayload = $this->buildTlsPayload($domain);
            if ($tlsPayload !== null) {
                $payload = array_merge($payload, $tlsPayload);
            } elseif (! $this->option('root') && ! $this->option('php') && ! $this->option('engine') && ! $this->option('upstream')) {
                throw new \RuntimeException('Nothing to edit. Pass --root=, --php=, --engine=, --upstream=, and/or --tls=.');
            }

            $res = $this->brokerCall('vhost.edit', [$domain], $payload);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
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
                $this->throwBrokerFailure($res);
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
                    $this->throwBrokerFailure($store);
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

    private function files(): int
    {
        $op = strtolower(trim((string) $this->argument('filesOp')));
        if (! in_array($op, ['list', 'read', 'write', 'delete', 'mkdir', 'rename'], true)) {
            $this->error('Unknown files operation. Use: list|read|write|delete|mkdir|rename');
            $this->line('Upload/download is UI-only — binary transfer does not map cleanly to a single CLI invocation.');

            return self::INVALID;
        }
        try {
            $domain = Validator::domain((string) $this->option('domain'));
            $path = (string) $this->option('path');
            $input = ['path' => $path, 'admin_user_id' => 'cli'];
            $timeout = $op === 'write' ? 120 : 60;
            if ($op === 'write') {
                $input['content_base64'] = base64_encode($this->stdinBytes());
            }
            if ($op === 'rename') {
                $dest = trim((string) $this->option('dest'));
                if ($dest === '') {
                    throw new \RuntimeException('--dest is required for files rename.');
                }
                $input['dest'] = $dest;
            }
            if ($op === 'delete' && $this->option('recursive')) {
                $input['recursive'] = true;
            }
            $res = $this->brokerCall('vhost.files.' . $op, [$domain], $input, $timeout);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
            }
            $data = is_array($res->data) ? $res->data : [];
            if ($op === 'read') {
                $bytes = base64_decode((string) ($data['content_base64'] ?? ''), true);
                if ($bytes === false) {
                    throw new \RuntimeException('Invalid file payload from broker.');
                }
                if ($this->wantsJson()) {
                    return $this->emitData($data);
                }
                $this->output->write($bytes);

                return self::SUCCESS;
            }
            if ($this->wantsJson() || $op === 'list') {
                if ($op === 'list' && ! $this->wantsJson()) {
                    $rows = [];
                    foreach ((array) ($data['entries'] ?? []) as $row) {
                        if (! is_array($row)) {
                            continue;
                        }
                        $rows[] = [
                            (string) ($row['name'] ?? ''),
                            (string) ($row['type'] ?? ''),
                            (string) ($row['size'] ?? ''),
                            ! empty($row['escaped']) ? 'outside' : '',
                        ];
                    }

                    return $this->emitTable(['name', 'type', 'size', 'note'], $rows);
                }

                return $this->emitData($data);
            }
            $this->line(match ($op) {
                'write' => 'Wrote ' . (string) ($data['path'] ?? $path),
                'mkdir' => 'Created ' . (string) ($data['path'] ?? $path),
                'delete' => 'Deleted ' . (string) ($data['path'] ?? $path),
                'rename' => 'Renamed to ' . (string) ($data['path'] ?? ''),
                default => json_encode($data, JSON_UNESCAPED_SLASHES) ?: '',
            });

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function octane(): int
    {
        $op = strtolower(trim((string) $this->argument('filesOp')));
        if (! in_array($op, ['enable', 'disable', 'reload', 'status'], true)) {
            $this->error('Unknown octane operation. Use: enable|disable|reload|status');

            return self::INVALID;
        }
        try {
            $domain = Validator::domain((string) $this->option('domain'));
            $input = [];
            if ($op === 'enable') {
                if ($this->option('max-requests')) {
                    $input['max_requests'] = (string) $this->option('max-requests');
                }
                if ($this->option('port')) {
                    $input['port'] = (string) $this->option('port');
                }
            }
            $res = $this->brokerCall('vhost.octane.'.$op, [$domain], $input, $op === 'enable' ? 900 : 180);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
            }
            $data = is_array($res->data) ? $res->data : [];
            if ($this->wantsJson()) {
                return $this->emitData($data);
            }
            $this->line(match ($op) {
                'enable' => "Octane enabled for {$domain} on 127.0.0.1:".(string) ($data['octane_port'] ?? '?')
                    .' (max-requests '.(string) ($data['octane_max_requests'] ?? '?').', supervisor program '
                    .(string) ($data['octane_program'] ?? '?').').',
                'disable' => "Octane disabled for {$domain}; the vhost serves through PHP-FPM again.",
                'reload' => "Reloaded Octane workers for {$domain} via ".(string) ($data['method'] ?? 'octane:reload').'.',
                default => $this->octaneStatusLine($domain, $data),
            });
            if ($op === 'enable') {
                $this->warn('Long-lived workers keep constructors and static state between requests. Review '
                    .(string) ($data['docs_url'] ?? 'https://laravel.com/docs/octane').' before serving production traffic.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    /** @param  array<string,mixed>  $data */
    private function octaneStatusLine(string $domain, array $data): string
    {
        if ((string) ($data['runtime'] ?? 'fpm') !== 'octane') {
            $laravel = ! empty($data['laravel_app']) ? 'Laravel app detected' : 'not a Laravel app';

            return "{$domain}: runtime=fpm ({$laravel}).";
        }

        $state = is_array($data['program'] ?? null) && is_array($data['program']['status'] ?? null)
            ? (string) ($data['program']['status']['state'] ?? 'unknown')
            : 'unknown';

        return "{$domain}: runtime=octane port=".(string) ($data['octane_port'] ?? '?')
            .' max-requests='.(string) ($data['octane_max_requests'] ?? '?')
            .' program='.(string) ($data['octane_program'] ?? '?')." ({$state}).";
    }

    private function stdinBytes(): string
    {
        if (! defined('STDIN') || ! is_resource(STDIN)) {
            return '';
        }
        $raw = stream_get_contents(STDIN);

        return $raw === false ? '' : $raw;
    }

    private function invalidAction(): int
    {
        $this->error('Unknown vhost action. Use: list|add|edit|del|files|octane');

        return self::INVALID;
    }
}
