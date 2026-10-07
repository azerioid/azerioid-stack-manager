<?php

namespace App\Console\Commands\Azerioid;

use AzerioidPanel\Broker\Tls\TlsMode;
use AzerioidPanel\Broker\Validator;
use Illuminate\Console\Command;

class VhostCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:vhost
        {action : list|add|edit|clone|del|files|octane|pm2|docker|reconcile|isolation|php-pool}
        {filesOp? : list|read|write|delete|mkdir|rename (with files); enable|disable|reload|status|scale|node (with octane/pm2); enable|disable|build|restart|logs|status|services|settings|env|env-set (with docker); status|apply (with isolation); status|apply|set (with php-pool)}
        {--domain= : Vhost domain}
        {--dry-run : reconcile: report drift without changing the projection; isolation apply: show the plan}
        {--confirm : Required for isolation apply and php-pool apply}
        {--open-basedir= : on|off (php-pool set --domain=)}
        {--repair : reconcile: rebuild the projection from the config files}
        {--type=php : php|static|proxy}
        {--php= : PHP version for php vhosts}
        {--root= : Document root}
        {--source= : Source vhost domain to clone from (clone)}
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
        {--instances= : PM2 cluster worker count (pm2 enable|scale; default 1)}
        {--entry= : Optional Node entry script relative to the app root (pm2 enable)}
        {--node= : Node.js for a PM2 vhost: system, 20, 22 or 24 (pm2 enable|node)}
        {--port= : Loopback port (octane: 34000-34999; pm2: 36000-36999; docker: 37000-37999)}
        {--mode= : Docker mode: image|compose|dockerfile (docker enable)}
        {--image= : Container image (docker enable --mode=image)}
        {--internal-port= : Container listen port (docker enable; required)}
        {--compose= : Compose file relative to docroot (docker enable; default docker-compose.yml)}
        {--dockerfile= : Dockerfile relative to docroot (docker enable; default Dockerfile)}
        {--lines= : Log line count (docker logs; default 100)}
        {--service= : Compose service that serves the site (docker enable|settings)}
        {--restart= : always|on-failure|never (docker enable|settings)}
        {--volume=* : Data directory host:container[:ro], host relative to the app (docker enable|settings; repeatable)}
        {--no-volumes : Remove every data volume (docker settings)}
        {--registry= : Saved registry name for private images, or "none" (docker enable|settings)}
        {--env-file= : KEY=VALUE file for the container environment (docker enable|env-set); values never go on the command line}
        {--reveal : Show environment values, not only names (docker env)}
        {--runtime= : Create-time runtime intent: traditional|octane|pm2|docker (add; same as UI createRuntime)}
        {--json : JSON output (list / files list / octane / pm2 / docker)}';

    protected $description = 'Manage virtual hosts via broker vhost.* actions. Create-time runtimes (octane/pm2/docker) use --runtime= on add, or enable afterward via azerioid vhost octane|pm2|docker enable (UI: Add vhost → Runtime intent).';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listVhosts(),
            'add' => $this->addVhost(),
            'edit' => $this->editVhost(),
            'clone' => $this->cloneVhost(),
            'del', 'delete', 'rm' => $this->delVhost(),
            'files' => $this->files(),
            'octane' => $this->octane(),
            'pm2' => $this->pm2(),
            'docker' => $this->docker(),
            'reconcile' => $this->reconcile(),
            'isolation' => $this->isolation(),
            'php-pool', 'phppool' => $this->phpPool(),
            default => $this->invalidAction(),
        };
    }

    /**
     * ADR A55: every PHP site in a PHP-FPM pool of its own, running as the site's identity.
     * Runs automatically from the scheduler; this is the status check, the operator retry
     * (sites put back on the shared pool) and the per-site open_basedir switch.
     */
    private function phpPool(): int
    {
        $op = strtolower((string) ($this->argument('filesOp') ?: 'status'));
        if (! in_array($op, ['status', 'check', 'apply', 'set'], true)) {
            $this->error('Unknown php-pool op. Use: azerioid vhost php-pool status|apply|set');

            return self::INVALID;
        }
        $domain = trim((string) $this->option('domain'));

        try {
            if ($op === 'set') {
                $switch = strtolower(trim((string) $this->option('open-basedir')));
                if ($domain === '' || ! in_array($switch, ['on', 'off'], true)) {
                    $this->error('Usage: azerioid vhost php-pool set --domain=<site> --open-basedir=on|off');

                    return self::INVALID;
                }
                $data = $this->brokerData('vhost.phppool.set', [$domain], ['open_basedir' => $switch === 'on'], 120);
                $this->info($domain.': open_basedir '.(($data['open_basedir'] ?? true) ? 'on' : 'off').'.');

                return self::SUCCESS;
            }
            if ($op === 'apply') {
                if (! $this->option('confirm')) {
                    $this->error('Refusing to move sites without --confirm (each moved site\'s PHP restarts in a pool of its own).');

                    return self::INVALID;
                }
                $data = $this->brokerData('vhost.phppool.apply', [], array_filter([
                    'confirm' => Validator::ISOLATE_PHP_CONFIRM,
                    'domain' => $domain !== '' ? $domain : null,
                ]), 3600);
                if ($this->option('json')) {
                    $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                    return self::SUCCESS;
                }
                foreach ((array) ($data['sites'] ?? []) as $site => $row) {
                    $this->line('  '.$site.'  '.(string) ($row['result'] ?? '?').'  HTTP '.(int) ($row['before'] ?? 0).' → '.(int) ($row['after'] ?? 0)
                        .(isset($row['reason']) ? '  — '.(string) $row['reason'] : ''));
                }
                foreach ((array) ($data['removed_pools'] ?? []) as $path) {
                    $this->line('  removed unused pool '.(string) $path);
                }
                ($data['result'] ?? '') === 'ok' ? $this->info('Done.') : $this->warn('Some sites were put back on the shared pool; see above.');

                return ($data['result'] ?? '') === 'ok' ? self::SUCCESS : self::FAILURE;
            }
            $data = $this->brokerData('vhost.phppool.status', [], [], 120, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($data['migrated'] ?? false) === true ? self::SUCCESS : self::FAILURE;
        }
        foreach ((array) ($data['sites'] ?? []) as $row) {
            $this->line('  '.(string) ($row['domain'] ?? '?').'  php '.(string) ($row['php_version'] ?? '').'  '.(string) ($row['state'] ?? '')
                .'  open_basedir '.(($row['open_basedir'] ?? true) ? 'on' : 'off')
                .(isset($row['reason']) && $row['reason'] !== null ? '  — '.(string) $row['reason'] : ''));
        }
        ($data['migrated'] ?? false) === true ? $this->info((string) ($data['verdict'] ?? 'OK')) : $this->warn((string) ($data['verdict'] ?? 'PENDING'));

        return ($data['migrated'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * ADR A49: every vhost identity on a group of its own, so no site can open another's files.
     * Runs automatically from the scheduler; this is the status check and the operator retry.
     */
    private function isolation(): int
    {
        $op = strtolower((string) ($this->argument('filesOp') ?: 'status'));

        return match ($op) {
            'status', 'check' => $this->isolationStatus(),
            'apply' => $this->isolationApply(),
            default => $this->badIsolationOp(),
        };
    }

    private function badIsolationOp(): int
    {
        $this->error('Unknown isolation op. Use: azerioid vhost isolation status|apply');

        return self::INVALID;
    }

    private function isolationStatus(): int
    {
        try {
            $data = $this->brokerData('vhost.isolation.status', [], [], 120, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($data['migrated'] ?? false) === true ? self::SUCCESS : self::FAILURE;
        }

        foreach ((array) ($data['pending'] ?? []) as $row) {
            $this->line('  '.(string) ($row['user'] ?? '?').'  '.(string) ($row['root'] ?? '').'  — '.(string) ($row['reason'] ?? ''));
        }
        $last = $data['last_attempt'] ?? null;
        if (is_array($last) && $last !== []) {
            $this->line('Last attempt: '.(string) ($last['result'] ?? '?')
                .(isset($last['finished_at']) ? ' at '.(string) $last['finished_at'] : '')
                .(isset($last['trigger']) ? ' ('.(string) $last['trigger'].')' : ''));
        }
        if (($data['migrated'] ?? false) === true) {
            $this->info((string) ($data['verdict'] ?? 'OK'));

            return self::SUCCESS;
        }
        $this->warn((string) ($data['verdict'] ?? 'PENDING'));

        // Non-zero until isolated, so a fleet check can gate on it.
        return self::FAILURE;
    }

    private function isolationApply(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! $this->option('confirm')) {
            $this->error('Refusing to migrate without --confirm (this regroups every vhost docroot and restarts the web server).');

            return self::INVALID;
        }

        try {
            $data = $this->brokerData('vhost.isolation.apply', [], [
                'confirm' => Validator::ISOLATE_VHOSTS_CONFIRM,
                'dry_run' => $dryRun,
            ], 3600);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if (($data['already_migrated'] ?? false) === true) {
            $this->info('Already isolated — every vhost identity has a group of its own.');

            return self::SUCCESS;
        }
        if ($dryRun) {
            $this->info('Dry run — nothing changed. Readers: '.implode(', ', (array) ($data['readers'] ?? [])));
            foreach ((array) ($data['plan'] ?? []) as $row) {
                $this->line('  '.(string) ($row['user'] ?? '?').'  '.(string) ($row['root'] ?? '').'  — '.(string) ($row['reason'] ?? ''));
            }

            return self::SUCCESS;
        }
        $this->info('Isolated: '.implode(', ', (array) ($data['identities'] ?? [])));
        foreach ((array) ($data['log'] ?? []) as $line) {
            $this->line('  · '.(string) $line);
        }

        return self::SUCCESS;
    }

    /**
     * Full audit of the vhost projection against the config files (A44).
     *
     * Reconciliation normally runs on change, so an out-of-band edit to
     * /etc/caddy/conf.d is invisible until something asks. This asks. Reporting is
     * the default; repairing requires --repair, because silently rewriting panel
     * state is not something a status command should do.
     */
    private function reconcile(): int
    {
        $projection = app(\App\Services\VhostProjection::class);

        try {
            $drift = $projection->drift();
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        $drifted = $drift['drifted'];

        if (! $this->option('repair')) {
            if ($this->wantsJson()) {
                return $this->emitData($drift + ['repaired' => false]);
            }
            $this->line('in sync : ' . $drift['in_sync']);
            $this->line('drifted : ' . count($drifted));
            foreach ($drifted as $row) {
                $this->warn('  ' . $row['domain'] . ' — ' . $row['reason']);
            }
            $this->newLine();
            if ($drifted === []) {
                $this->info('Projection matches the config files.');

                return self::SUCCESS;
            }
            $this->line('Run with --repair to rebuild the projection from the config files.');

            // Non-zero so this is usable as a fleet drift check.
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing changed. ' . count($drifted) . ' vhost(s) would be reconciled.');

            return self::SUCCESS;
        }

        try {
            $result = $projection->reconcile();
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        if ($this->wantsJson()) {
            return $this->emitData($result + ['repaired' => true]);
        }

        $this->info(sprintf(
            'Reconciled: %d created, %d updated, %d unchanged, %d removed (%d total).',
            $result['created'],
            $result['updated'],
            $result['unchanged'],
            $result['removed'],
            $result['total']
        ));

        return self::SUCCESS;
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
                    : (($v['runtime'] ?? 'fpm') === 'pm2'
                        ? 'pm2:'.(string) ($v['pm2_port'] ?? '?')
                        : (($v['runtime'] ?? 'fpm') === 'docker'
                            ? 'docker:'.(string) ($v['docker_port'] ?? '?')
                            : (string) ($v['runtime'] ?? 'fpm'))),
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
            $runtime = strtolower(trim((string) ($this->option('runtime') ?: 'traditional')));
            if (! in_array($runtime, ['traditional', 'octane', 'pm2', 'docker'], true)) {
                throw new \RuntimeException('--runtime= must be traditional, octane, pm2, or docker.');
            }
            if ($runtime === 'octane' && $type !== 'php') {
                throw new \RuntimeException('--runtime=octane requires --type=php.');
            }
            if (in_array($runtime, ['pm2', 'docker'], true) && ! in_array($type, ['proxy', 'static'], true)) {
                throw new \RuntimeException("--runtime={$runtime} requires --type=proxy or static.");
            }
            if ($runtime === 'docker') {
                $engine = 'caddy';
            }
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

            if ($runtime !== 'traditional') {
                $enableCode = $this->enableRuntimeAfterAdd($domain, $runtime);
                if ($enableCode !== self::SUCCESS) {
                    $this->error(
                        "Created {$domain}, but {$runtime} enable failed. "
                        .'The vhost exists — retry with: azerioid vhost '.$runtime.' enable --domain='.$domain
                    );

                    return $enableCode;
                }
                $this->line("Created vhost {$domain} and enabled {$runtime}.");
            } else {
                $this->line("Created vhost {$domain}.");
            }
            if ($this->wantsJson() || is_array($res->data)) {
                $this->line(json_encode($res->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    /**
     * A79 (increment 1): clone a site to a new domain for staging — vhost replicated
     * and the file tree copied into a fully isolated vhost. Databases and the
     * Octane/PM2/Docker runtimes are not cloned yet.
     */
    private function cloneVhost(): int
    {
        $source = trim((string) $this->option('source'));
        $domain = trim((string) $this->option('domain'));
        if ($source === '' || $domain === '') {
            $this->error('Usage: azerioid vhost clone --source=<src-domain> --domain=<new-domain>');

            return self::INVALID;
        }

        try {
            $res = $this->brokerCall('vhost.clone', [$source, $domain], [], 1800);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
            }
            $this->line("Cloned {$source} → {$domain}.");
            if (is_array($res->data) && isset($res->data['note'])) {
                $this->line((string) $res->data['note']);
            }
            if ($this->wantsJson() && is_array($res->data)) {
                $this->line(json_encode($res->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function enableRuntimeAfterAdd(string $domain, string $runtime): int
    {
        try {
            if ($runtime === 'octane') {
                $input = [];
                if ($this->option('max-requests') !== null && $this->option('max-requests') !== '') {
                    $input['max_requests'] = (string) $this->option('max-requests');
                }
                $res = $this->brokerCall('vhost.octane.enable', [$domain], $input, 900);
            } elseif ($runtime === 'pm2') {
                $input = [];
                if ($this->option('instances') !== null && $this->option('instances') !== '') {
                    $input['instances'] = (string) $this->option('instances');
                }
                if ($this->option('entry')) {
                    $input['entry'] = (string) $this->option('entry');
                }
                $res = $this->brokerCall('vhost.pm2.enable', [$domain], $input, 900);
            } else {
                $input = [];
                if ($this->option('mode')) {
                    $input['mode'] = (string) $this->option('mode');
                }
                if ($this->option('image')) {
                    $input['image'] = (string) $this->option('image');
                }
                if ($this->option('internal-port')) {
                    $input['internal_port'] = (string) $this->option('internal-port');
                }
                if ($this->option('compose')) {
                    $input['compose'] = (string) $this->option('compose');
                }
                if ($this->option('dockerfile')) {
                    $input['dockerfile'] = (string) $this->option('dockerfile');
                }
                if ($this->option('port')) {
                    $input['port'] = (string) $this->option('port');
                }
                $res = $this->brokerCall('vhost.docker.enable', [$domain], $input, 900);
            }
            if (! $res->ok) {
                $this->error((string) $res->error);

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
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

    private function pm2(): int
    {
        $op = strtolower(trim((string) $this->argument('filesOp')));
        if (! in_array($op, ['enable', 'disable', 'reload', 'status', 'scale', 'node'], true)) {
            $this->error('Unknown pm2 operation. Use: enable|disable|reload|status|scale|node');

            return self::INVALID;
        }
        try {
            $domain = Validator::domain((string) $this->option('domain'));
            $input = [];
            if ($op === 'enable') {
                if ($this->option('instances')) {
                    $input['instances'] = (string) $this->option('instances');
                }
                if ($this->option('entry')) {
                    $input['entry'] = (string) $this->option('entry');
                }
                if ($this->option('port')) {
                    $input['port'] = (string) $this->option('port');
                }
                if ($this->option('node')) {
                    $input['node'] = (string) $this->option('node');
                }
            }
            if ($op === 'node') {
                if (! $this->option('node')) {
                    throw new \RuntimeException('--node= is required: system, 20, 22 or 24.');
                }
                $input['node'] = (string) $this->option('node');
            }
            if ($op === 'scale') {
                if (! $this->option('instances')) {
                    throw new \RuntimeException('--instances= is required for pm2 scale.');
                }
                $input['instances'] = (string) $this->option('instances');
            }
            $res = $this->brokerCall('vhost.pm2.'.$op, [$domain], $input, $op === 'enable' ? 900 : 180);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
            }
            $data = is_array($res->data) ? $res->data : [];
            if ($this->wantsJson()) {
                return $this->emitData($data);
            }
            $this->line(match ($op) {
                'enable' => "PM2 enabled for {$domain} on 127.0.0.1:".(string) ($data['pm2_port'] ?? '?')
                    .' ('.(string) ($data['pm2_instances'] ?? '?').' worker(s), entry '
                    .(string) ($data['pm2_entry'] ?? '?').', supervisor program '
                    .(string) ($data['pm2_program'] ?? '?').').',
                'disable' => "PM2 disabled for {$domain}; the vhost no longer runs under PM2.",
                'reload' => "Reloaded PM2 workers for {$domain} via ".(string) ($data['method'] ?? 'pm2-reload').'.',
                'scale' => "Scaled PM2 on {$domain} to ".(string) ($data['pm2_instances'] ?? '?').' worker(s).',
                'node' => ($data['changed'] ?? false)
                    ? "{$domain} now runs on Node.js ".(string) ($data['node'] ?? '?').' ('.(string) ($data['node_version'] ?? '?').'), was '
                        .(string) ($data['previous'] ?? '?').'. '.(string) ($data['note'] ?? '')
                    : "{$domain} already runs on Node.js ".(string) ($data['node'] ?? '?').'.',
                default => $this->pm2StatusLine($domain, $data),
            });
            if ($op === 'enable') {
                $this->warn('PM2 cluster mode shares one listen port across workers. Reload performs a zero-downtime '
                    .'rolling restart; each worker is a fresh Node process afterward (module cache is not shared '
                    .'like a long-lived PHP worker). Cluster workers do not share in-memory session or state — '
                    .'use sticky sessions or an external store. The app must listen on process.env.PORT '
                    .'(and preferably 127.0.0.1). See '
                    .(string) ($data['cluster_docs_url'] ?? 'https://pm2.keymetrics.io/docs/usage/cluster-mode/').'.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    /** @param  array<string,mixed>  $data */
    private function pm2StatusLine(string $domain, array $data): string
    {
        if ((string) ($data['runtime'] ?? 'fpm') !== 'pm2') {
            $node = ! empty($data['node_app']) ? 'Node app detected' : 'not a Node app';

            return "{$domain}: runtime=fpm ({$node}).";
        }

        $state = is_array($data['program'] ?? null) && is_array($data['program']['status'] ?? null)
            ? (string) ($data['program']['status']['state'] ?? 'unknown')
            : 'unknown';

        return "{$domain}: runtime=pm2 port=".(string) ($data['pm2_port'] ?? '?')
            .' instances='.(string) ($data['pm2_instances'] ?? '?')
            .' entry='.(string) ($data['pm2_entry'] ?? '?')
            .' program='.(string) ($data['pm2_program'] ?? '?')." ({$state}).";
    }

    /**
     * Compose services, workload settings and container environment (B4, ADR A50).
     */
    private function dockerWorkload(string $op): int
    {
        try {
            $domain = Validator::domain((string) $this->option('domain'));
            [$action, $input] = match ($op) {
                'services' => ['vhost.docker.services', $this->option('compose') ? ['compose' => (string) $this->option('compose')] : []],
                'settings' => ($settings = $this->dockerSettingsInput()) === []
                    ? ['vhost.docker.settings', []]
                    : ['vhost.docker.settings.set', $settings],
                'env' => ['vhost.docker.env', []],
                'env-set' => ['vhost.docker.env.set', ['env' => $this->readEnvFile((string) $this->option('env-file'))]],
            };
            $res = $this->brokerCall($action, [$domain], $input, 300);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
            }
            $data = is_array($res->data) ? $res->data : [];
            if ($op === 'env' && ! $this->option('reveal')) {
                $data['env'] = array_fill_keys(array_keys((array) ($data['env'] ?? [])), '(hidden; --reveal to show)');
            }
            if ($this->wantsJson()) {
                return $this->emitData($data);
            }
            match ($op) {
                'services' => $this->line('Services in '.(string) ($data['compose'] ?? '?').': '.implode(', ', (array) ($data['services'] ?? []))
                    .' (serving: '.(string) ($data['selected'] ?? 'not chosen').')'),
                'settings' => $this->printDockerSettings((array) ($data['settings'] ?? []), (array) ($data['env_keys'] ?? []), $data['applied'] ?? null),
                'env' => array_map(fn ($k, $v) => $this->line($k.'='.$v), array_keys((array) $data['env']), (array) $data['env']),
                'env-set' => $this->line('Environment saved ('.count((array) ($data['env_keys'] ?? [])).' variable(s))'
                    .(($data['applied'] ?? false) ? '; container restarted.' : '; applies when Docker is enabled.')),
            };

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    /** @return array<string, mixed> */
    private function dockerSettingsInput(): array
    {
        $input = [];
        if ($this->option('service')) {
            $input['service'] = (string) $this->option('service');
        }
        if ($this->option('restart')) {
            $input['restart'] = (string) $this->option('restart');
        }
        if ($this->option('registry')) {
            $registry = (string) $this->option('registry');
            $input['registry'] = strtolower($registry) === 'none' ? null : $registry;
        }
        if ($this->option('no-volumes')) {
            $input['volumes'] = [];
        } elseif ((array) $this->option('volume') !== []) {
            $input['volumes'] = array_map(static function (string $spec): array {
                $parts = explode(':', $spec);
                if (count($parts) < 2 || count($parts) > 3 || (isset($parts[2]) && $parts[2] !== 'ro')) {
                    throw new \InvalidArgumentException("--volume must be host:container or host:container:ro, got {$spec}");
                }

                return ['host' => $parts[0], 'container' => $parts[1], 'readonly' => ($parts[2] ?? '') === 'ro'];
            }, (array) $this->option('volume'));
        }

        return $input;
    }

    /** @return array<string, string> */
    private function readEnvFile(string $path): array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException('--env-file must name a readable KEY=VALUE file.');
        }
        $env = [];
        foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            if (! str_contains($line, '=')) {
                throw new \InvalidArgumentException("Not KEY=VALUE: {$line}");
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $env[trim($key)] = $value;
        }

        return $env;
    }

    /** @param array<string, mixed> $settings */
    private function printDockerSettings(array $settings, array $envKeys, mixed $applied): void
    {
        $this->line('Service  : '.(string) ($settings['service'] ?? '(not chosen)'));
        $this->line('Restart  : '.(string) ($settings['restart'] ?? 'always'));
        $this->line('Registry : '.(string) ($settings['registry'] ?? '(public)'));
        foreach ((array) ($settings['volumes'] ?? []) as $v) {
            $this->line('Volume   : '.$v['host'].' → '.$v['container'].(($v['readonly'] ?? false) ? ' (read-only)' : ''));
        }
        $this->line('Env      : '.($envKeys === [] ? '(none)' : implode(', ', $envKeys)));
        if ($applied === true) {
            $this->info('Applied: container restarted.');
        }
    }

    private function docker(): int
    {
        $op = strtolower(trim((string) $this->argument('filesOp')));
        if (in_array($op, ['services', 'settings', 'env', 'env-set'], true)) {
            return $this->dockerWorkload($op);
        }
        if (! in_array($op, ['enable', 'disable', 'build', 'restart', 'logs', 'status'], true)) {
            $this->error('Unknown docker operation. Use: enable|disable|build|restart|logs|status|services|settings|env|env-set');

            return self::INVALID;
        }
        try {
            $domain = Validator::domain((string) $this->option('domain'));
            $input = [];
            if ($op === 'enable') {
                if ($this->option('mode')) {
                    $input['mode'] = (string) $this->option('mode');
                }
                if ($this->option('image')) {
                    $input['image'] = (string) $this->option('image');
                }
                if ($this->option('internal-port')) {
                    $input['internal_port'] = (string) $this->option('internal-port');
                }
                if ($this->option('port')) {
                    $input['port'] = (string) $this->option('port');
                }
                if ($this->option('compose')) {
                    $input['compose'] = (string) $this->option('compose');
                }
                if ($this->option('dockerfile')) {
                    $input['dockerfile'] = (string) $this->option('dockerfile');
                }
                $input += $this->dockerSettingsInput();
                if ($this->option('env-file')) {
                    $input['env'] = $this->readEnvFile((string) $this->option('env-file'));
                }
            }
            if ($op === 'logs' && $this->option('lines')) {
                $input['lines'] = (string) $this->option('lines');
            }
            $timeout = match ($op) {
                'enable', 'build' => 900,
                'logs' => 120,
                default => 180,
            };
            $res = $this->brokerCall('vhost.docker.'.$op, [$domain], $input, $timeout);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
            }
            $data = is_array($res->data) ? $res->data : [];
            if ($this->wantsJson()) {
                return $this->emitData($data);
            }
            if ($op === 'logs') {
                $this->line((string) ($data['output'] ?? ''));

                return self::SUCCESS;
            }
            $this->line(match ($op) {
                'enable' => "Docker enabled for {$domain} on 127.0.0.1:".(string) ($data['docker_port'] ?? '?')
                    .' → container :'.(string) ($data['docker_internal_port'] ?? '?')
                    .' (mode '.(string) ($data['docker_mode'] ?? '?').', supervisor program '
                    .(string) ($data['docker_program'] ?? '?').').',
                'disable' => "Docker disabled for {$domain}; the vhost no longer runs a container.",
                'build' => "Rebuilt Docker workload for {$domain} (mode ".(string) ($data['docker_mode'] ?? '?').').',
                'restart' => "Restarted Docker supervisor program for {$domain}.",
                default => $this->dockerStatusLine($domain, $data),
            });
            if ($op === 'enable') {
                $this->warn('Rootless Docker only (azerioid-supervised). Publish is loopback-only '
                    .'(127.0.0.1:37000–37999). Files/Terminal use the host docroot as build context, not the '
                    .'container filesystem. See '
                    .(string) ($data['docs_url'] ?? 'https://docs.docker.com/engine/security/rootless/').'.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    /** @param  array<string,mixed>  $data */
    private function dockerStatusLine(string $domain, array $data): string
    {
        if ((string) ($data['runtime'] ?? 'fpm') !== 'docker') {
            $hint = ! empty($data['docker_app']) ? 'Dockerfile/compose detected' : 'no Dockerfile/compose';

            return "{$domain}: runtime=fpm ({$hint}).";
        }

        $state = is_array($data['program'] ?? null) && is_array($data['program']['status'] ?? null)
            ? (string) ($data['program']['status']['state'] ?? 'unknown')
            : 'unknown';
        $containerState = is_array($data['container'] ?? null)
            ? (string) ($data['container']['state'] ?? '?')
            : '?';

        return "{$domain}: runtime=docker port=".(string) ($data['docker_port'] ?? '?')
            .' internal='.(string) ($data['docker_internal_port'] ?? '?')
            .' mode='.(string) ($data['docker_mode'] ?? '?')
            .' program='.(string) ($data['docker_program'] ?? '?')
            ." ({$state}, container={$containerState}).";
    }

    private function invalidAction(): int
    {
        $this->error('Unknown vhost action. Use: list|add|edit|del|files|octane|pm2|docker');

        return self::INVALID;
    }
}
