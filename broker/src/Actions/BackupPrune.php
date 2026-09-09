<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\SpacesClient;

final class BackupPrune
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $keep = max(1, min(365, (int) ($input['keep'] ?? $args[0] ?? 14)));
        $destination = strtolower(trim((string) ($input['destination'] ?? 'spaces')));

        if ($destination === 'local') {
            return $this->pruneLocal($runtime, $config, $keep);
        }
        if ($destination !== 'spaces' && $destination !== '') {
            throw new BrokerException('destination must be spaces or local.', 2);
        }

        $client = SpacesClient::fromInput($input['spaces'] ?? []);
        $listed = $client->list('azerioid/');
        $byKind = [];
        foreach ($listed['objects'] as $obj) {
            $key = (string) ($obj['key'] ?? '');
            $parts = explode('/', $key);
            $kind = ($parts[1] ?? 'unknown') . '/' . ($parts[2] ?? '');
            $byKind[$kind][] = $obj;
        }
        $deleted = [];
        foreach ($byKind as $group) {
            usort($group, static fn ($a, $b) => strcmp((string) $b['last_modified'], (string) $a['last_modified']));
            foreach (array_slice($group, $keep) as $old) {
                $client->delete((string) $old['key']);
                $deleted[] = $old['key'];
            }
        }
        return ['deleted' => $deleted, 'keep' => $keep, 'destination' => 'spaces'];
    }

    /** @return array{deleted:list<string>,keep:int,destination:string} */
    private function pruneLocal(Runtime $runtime, Config $config, int $keep): array
    {
        $base = rtrim($config->localBackupDir, '/');
        if (!$runtime->isDir($base)) {
            return ['deleted' => [], 'keep' => $keep, 'destination' => 'local'];
        }
        $deleted = [];
        foreach ($runtime->listDir($base) as $kind) {
            $kindDir = $base . '/' . $kind;
            if (!$runtime->isDir($kindDir)) {
                continue;
            }
            foreach ($runtime->listDir($kindDir) as $name) {
                $nameDir = $kindDir . '/' . $name;
                if (!$runtime->isDir($nameDir)) {
                    continue;
                }
                $files = [];
                foreach ($runtime->listDir($nameDir) as $file) {
                    if (!str_ends_with($file, '.bin')) {
                        continue;
                    }
                    $path = $nameDir . '/' . $file;
                    if ($runtime->fileExists($path)) {
                        $files[] = $path;
                    }
                }
                rsort($files, SORT_STRING);
                foreach (array_slice($files, $keep) as $old) {
                    $runtime->deleteFile($old);
                    $deleted[] = $old;
                }
            }
        }
        return ['deleted' => $deleted, 'keep' => $keep, 'destination' => 'local'];
    }
}
