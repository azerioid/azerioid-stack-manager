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
            if (($parts[1] ?? '') === 'vhost') {
                continue; // listed as bundles by backup.vhost.list (A52)
            }
            $objects[] = [
                'key' => $key,
                'size' => (int) ($obj['size'] ?? 0),
                'last_modified' => $obj['last_modified'] ?? null,
                'kind' => $parts[1] ?? 'unknown',
                'name' => $parts[2] ?? '',
                'destination' => 'spaces',
                'format' => self::formatOf($key),
                'legacy' => self::formatOf($key) !== 'lacmp2',
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
            if ($kind === 'vhost') {
                continue; // bundles: backup.vhost.list / their own retention (A52)
            }
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
                        'format' => self::formatOf($file),
                        'legacy' => self::formatOf($file) !== 'lacmp2',
                    ];
                }
            }
        }
        usort($objects, static fn ($a, $b) => strcmp((string) $b['last_modified'], (string) $a['last_modified']));
        return $objects;
    }

    /**
     * Archives written since LACMP2 carry the format in their name, so a listing
     * can flag unauthenticated legacy archives without reading any of them.
     */
    public static function formatOf(string $key): string
    {
        return str_contains($key, '.lacmp2.') ? 'lacmp2' : 'legacy';
    }

    private function stampToIso(string $stamp): ?string
    {
        // Tolerate the format marker: 20260926T...Z.lacmp2
        $stamp = preg_replace('/\.lacmp2$/', '', $stamp) ?? $stamp;
        // 20260909T123456123456Z (seconds + microseconds)
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(\d{6})Z$/', $stamp, $m)
            && !preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/', $stamp, $m)) {
            return null;
        }
        return sprintf('%s-%s-%sT%s:%s:%sZ', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
    }
}
