<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Backup\VhostBundle;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\SpacesClient;
use AzerioidPanel\Broker\Validator;

/**
 * Age-based retention (B6, ADR A52): remove archives older than `days`, per target, but always
 * keep the newest `min_keep` of each target however old — a target that stopped being backed up
 * keeps its last good copy. Optional `kind` and `name` narrow it to one target, which is how a
 * schedule applies its own retention override.
 */
final class BackupPruneAge
{
    private const KINDS = ['db', 'files', 'caddy', 'vhost'];

    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $days = (int) ($input['days'] ?? 0);
        if ($days < 1 || $days > 3650) {
            throw new BrokerException('days must be between 1 and 3650.', 2);
        }
        $minKeep = max(1, min(100, (int) ($input['min_keep'] ?? 1)));
        $kind = isset($input['kind']) && $input['kind'] !== '' ? strtolower((string) $input['kind']) : null;
        if ($kind !== null && !in_array($kind, self::KINDS, true)) {
            throw new BrokerException('kind must be db, files, caddy or vhost.', 2);
        }
        $name = isset($input['name']) && $input['name'] !== '' ? (string) $input['name'] : null;
        $destination = strtolower((string) ($input['destination'] ?? 'spaces')) === 'local' ? 'local' : 'spaces';
        $cutoff = gmdate('Ymd\THis', (int) strtotime($runtime->now()) - $days * 86400);

        $deleted = [];
        if ($kind === null || $kind === 'vhost') {
            $deleted = (new VhostBundle($config, $runtime))->pruneAge($input, $days, $minKeep, $kind === 'vhost' ? $name : null);
        }
        if ($kind !== 'vhost') {
            // One group per target: {kind}/{name}. File names start with the stamp.
            $groups = [];
            if ($destination === 'local') {
                $base = rtrim($config->localBackupDir, '/');
                foreach (['db', 'files', 'caddy'] as $k) {
                    if (($kind !== null && $kind !== $k) || !$runtime->isDir($base . '/' . $k)) {
                        continue;
                    }
                    foreach ($runtime->listDir($base . '/' . $k) as $n) {
                        if ($name !== null && $n !== $name) {
                            continue;
                        }
                        foreach ($runtime->isDir($base . '/' . $k . '/' . $n) ? $runtime->listDir($base . '/' . $k . '/' . $n) : [] as $file) {
                            if (str_ends_with($file, '.bin')) {
                                $groups[$k . '/' . $n][] = $base . '/' . $k . '/' . $n . '/' . $file;
                            }
                        }
                    }
                }
            } else {
                $client = SpacesClient::fromInput($input['spaces'] ?? []);
                foreach ($client->list('azerioid/')['objects'] as $obj) {
                    $p = explode('/', (string) $obj['key']);
                    if (count($p) !== 4 || !in_array($p[1], ['db', 'files', 'caddy'], true)
                        || ($kind !== null && $p[1] !== $kind) || ($name !== null && $p[2] !== $name)) {
                        continue;
                    }
                    $groups[$p[1] . '/' . $p[2]][] = (string) $obj['key'];
                }
            }
            foreach ($groups as $paths) {
                rsort($paths, SORT_STRING);
                foreach (array_slice($paths, $minKeep) as $path) {
                    if (strcmp(substr(basename($path), 0, 15), $cutoff) >= 0) {
                        continue;
                    }
                    if ($destination === 'local') {
                        Validator::localBackupPath($path, $config->localBackupDir, $runtime);
                        $runtime->deleteFile($path);
                    } else {
                        ($client ?? SpacesClient::fromInput($input['spaces'] ?? []))->delete($path);
                    }
                    $deleted[] = $path;
                }
            }
        }

        return ['deleted' => $deleted, 'days' => $days, 'min_keep' => $minKeep, 'destination' => $destination];
    }
}
