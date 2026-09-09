<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\SpacesClient;

final class BackupList
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $destination = strtolower(trim((string) ($input['destination'] ?? 'spaces')));
        if ($destination === 'local') {
            return ['objects' => $this->listLocal($runtime, $config), 'destination' => 'local'];
        }
        if ($destination !== 'spaces' && $destination !== '') {
            throw new BrokerException('destination must be spaces or local.', 2);
        }

        $client = SpacesClient::fromInput($input['spaces'] ?? []);
        $listed = $client->list('azerioid/');
        $objects = [];
        foreach ($listed['objects'] as $obj) {
            $key = (string) ($obj['key'] ?? '');
            $parts = explode('/', $key);
            $objects[] = [
                'key' => $key,
                'size' => (int) ($obj['size'] ?? 0),
                'last_modified' => $obj['last_modified'] ?? null,
                'kind' => $parts[1] ?? 'unknown',
                'name' => $parts[2] ?? '',
                'destination' => 'spaces',
            ];
        }
        usort($objects, static fn ($a, $b) => strcmp((string) $b['last_modified'], (string) $a['last_modified']));
        return ['objects' => $objects, 'destination' => 'spaces'];
    }

    /** @return list<array{key:string,size:int,last_modified:?string,kind:string,name:string,destination:string}> */
    private function listLocal(Runtime $runtime, Config $config): array
    {
        $base = rtrim($config->localBackupDir, '/');
        if (!$runtime->isDir($base)) {
            return [];
        }
        $objects = [];
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
                foreach ($runtime->listDir($nameDir) as $file) {
                    if (!str_ends_with($file, '.bin')) {
                        continue;
                    }
                    $path = $nameDir . '/' . $file;
                    if (!$runtime->fileExists($path)) {
                        continue;
                    }
                    $stamp = basename($file, '.bin');
                    $objects[] = [
                        'key' => $path,
                        'size' => $runtime->fileSize($path),
                        'last_modified' => $this->stampToIso($stamp),
                        'kind' => $kind,
                        'name' => $name,
                        'destination' => 'local',
                    ];
                }
            }
        }
        usort($objects, static fn ($a, $b) => strcmp((string) $b['last_modified'], (string) $a['last_modified']));
        return $objects;
    }

    private function stampToIso(string $stamp): ?string
    {
        // 20260909T123456Z
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/', $stamp, $m)) {
            return null;
        }
        return sprintf('%s-%s-%sT%s:%s:%sZ', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
    }
}
