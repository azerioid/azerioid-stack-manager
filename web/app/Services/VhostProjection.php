<?php

namespace App\Services;

use App\Models\Vhost;
use App\Services\Broker\BrokerClient;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the `vhosts` projection in step with the web-server config files (A44).
 *
 * Reconciliation runs **on change only** — after a mutating vhost action — rather
 * than on every page render, which is why reads are cheap. Out-of-band edits
 * (someone editing /etc/caddy/conf.d by hand) therefore cannot be caught
 * immediately, so each row stores the hash of the config file it was built from and
 * drift is reported when the hash no longer matches. `azerioid vhost reconcile`
 * does a full audit on demand.
 *
 * The broker action list this reconciles from is authoritative; this class only
 * projects. Nothing here may be used to make a privileged decision.
 */
class VhostProjection
{
    /**
     * Broker actions that change vhost state. Anything here invalidates the
     * projection, so it is reconciled after the call succeeds. Kept as one explicit
     * list rather than scattered call sites, because a forgotten trigger shows up
     * as silent drift.
     */
    public const MUTATING_ACTIONS = [
        'vhost.add',
        'vhost.edit',
        'vhost.del',
        'vhost.octane.enable',
        'vhost.octane.disable',
        'vhost.pm2.enable',
        'vhost.pm2.disable',
        'vhost.pm2.scale',
        'vhost.docker.enable',
        'vhost.docker.disable',
    ];

    public function __construct(private readonly BrokerClient $broker)
    {
    }

    public static function invalidatedBy(string $action): bool
    {
        return in_array($action, self::MUTATING_ACTIONS, true);
    }

    /**
     * Rebuild the projection from the live config.
     *
     * @return array{created:int, updated:int, removed:int, total:int, unchanged:int}
     */
    public function reconcile(): array
    {
        $live = $this->liveVhosts();

        // An empty listing is far more likely a broker failure than every vhost
        // having been deleted at once, and deleting the projection on the strength
        // of it would destroy state the panel cannot rebuild until the broker is
        // healthy again. Refuse to treat it as authoritative.
        if ($live === []) {
            return [
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'removed' => 0,
                'total' => Vhost::query()->count(),
                'skipped' => 'no vhosts returned by the broker; projection left untouched',
            ];
        }

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $removed = 0;

        DB::transaction(function () use ($live, &$created, &$updated, &$unchanged, &$removed): void {
            $seen = [];
            foreach ($live as $entry) {
                $domain = (string) ($entry['domain'] ?? '');
                if ($domain === '') {
                    continue;
                }
                $seen[] = $domain;
                $attrs = self::attributesFrom($entry);

                $existing = Vhost::query()->where('domain', $domain)->first();
                if ($existing === null) {
                    Vhost::query()->create(['domain' => $domain] + $attrs);
                    $created++;

                    continue;
                }

                // reconciled_at always moves, so compare everything else to tell a
                // real change from a no-op refresh.
                $compare = $attrs;
                unset($compare['reconciled_at']);
                $changed = false;
                foreach ($compare as $key => $value) {
                    if ($existing->{$key} != $value) {
                        $changed = true;
                        break;
                    }
                }
                $existing->fill($attrs)->save();
                $changed ? $updated++ : $unchanged++;
            }

            // A vhost the config no longer describes has been deleted; the
            // projection must not keep serving a ghost to the UI.
            $removed = Vhost::query()
                ->when($seen !== [], fn ($q) => $q->whereNotIn('domain', $seen))
                ->delete();
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'removed' => $removed,
            'total' => Vhost::query()->count(),
        ];
    }

    /**
     * Report projection rows whose config file no longer matches what they were
     * built from, plus vhosts present on only one side.
     *
     * Because reconciliation is change-triggered, an out-of-band edit to
     * /etc/caddy/conf.d is invisible until something asks. This is what asks.
     *
     * @return array{drifted:list<array{domain:string,reason:string}>, in_sync:int}
     */
    public function drift(): array
    {
        $live = [];
        foreach ($this->liveVhosts() as $entry) {
            $domain = (string) ($entry['domain'] ?? '');
            if ($domain !== '') {
                $live[$domain] = $entry;
            }
        }

        $drifted = [];
        $inSync = 0;

        foreach (Vhost::query()->get() as $row) {
            $entry = $live[$row->domain] ?? null;
            if ($entry === null) {
                $drifted[] = ['domain' => $row->domain, 'reason' => 'in the panel database but not in any config file'];

                continue;
            }
            $liveHash = $entry['config_sha256'] ?? null;
            if ($row->config_sha256 === null || $liveHash === null) {
                $drifted[] = ['domain' => $row->domain, 'reason' => 'config hash unavailable on one side'];

                continue;
            }
            if (! hash_equals((string) $row->config_sha256, (string) $liveHash)) {
                $drifted[] = ['domain' => $row->domain, 'reason' => 'config file changed outside the panel'];

                continue;
            }
            $inSync++;
        }

        foreach (array_keys($live) as $domain) {
            if (! Vhost::query()->where('domain', $domain)->exists()) {
                $drifted[] = ['domain' => $domain, 'reason' => 'served by config but missing from the panel database'];
            }
        }

        return ['drifted' => $drifted, 'in_sync' => $inSync];
    }

    /** @return list<array<string,mixed>> */
    private function liveVhosts(): array
    {
        // probe_certs=false: TLS probing is a live network call and none of it is
        // stored in the projection, so paying for it here would be waste.
        $res = $this->broker->call('vhost.list', [], ['probe_certs' => false], 120, false);
        if (! $res->ok) {
            return [];
        }
        $vhosts = $res->data['vhosts'] ?? [];

        return is_array($vhosts) ? array_values(array_filter($vhosts, 'is_array')) : [];
    }

    /** @param array<string,mixed> $e */
    public static function attributesFrom(array $e): array
    {
        return [
            'engine' => self::str($e['engine'] ?? null),
            'type' => self::str($e['type'] ?? null),
            'runtime' => self::str($e['runtime'] ?? null),
            'php_version' => self::str($e['php_version'] ?? null),
            'docroot' => self::str($e['root'] ?? null),
            'reverse_proxy' => self::str($e['reverse_proxy'] ?? null),
            'tls_mode' => self::str($e['tls_mode'] ?? null),
            'tls_enabled' => (bool) ($e['tls'] ?? false),
            'readonly' => (bool) ($e['readonly'] ?? false),
            'enabled' => (bool) ($e['enabled'] ?? true),
            'runtime_meta' => self::runtimeMeta($e),
            'app_detect' => [
                'laravel' => (bool) ($e['laravel_app'] ?? false),
                'laravel_detail' => $e['laravel_app_detail'] ?? null,
                'node' => (bool) ($e['node_app'] ?? false),
                'node_detail' => $e['node_app_detail'] ?? null,
                'docker' => (bool) ($e['docker_app'] ?? false),
                'docker_detail' => $e['docker_app_detail'] ?? null,
            ],
            'domains' => is_array($e['domains'] ?? null) ? array_values($e['domains']) : null,
            'config_path' => self::str($e['source'] ?? null),
            'config_sha256' => self::str($e['config_sha256'] ?? null),
            'reconciled_at' => now(),
        ];
    }

    /** @param array<string,mixed> $e */
    private static function runtimeMeta(array $e): array
    {
        $keys = [
            'octane_port', 'octane_max_requests', 'octane_program',
            'pm2_port', 'pm2_instances', 'pm2_entry', 'pm2_program',
            'docker_port', 'docker_internal_port', 'docker_mode',
            'docker_image', 'docker_compose', 'docker_dockerfile', 'docker_program',
            'php_socket', 'basename',
        ];
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $e) && $e[$k] !== null) {
                $out[$k] = $e[$k];
            }
        }

        return $out;
    }

    private static function str(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return is_scalar($v) ? (string) $v : null;
    }
}
