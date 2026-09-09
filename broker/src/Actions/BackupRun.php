<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\ArchiveCrypto;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\SpacesClient;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Web\WebServers;

final class BackupRun
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $passphrase = Validator::password((string) ($input['passphrase'] ?? ''));
        $destination = $this->destination($input);
        $stamp = gmdate('Ymd\THis') . sprintf('%06d', (int) ((microtime(true) - floor(microtime(true))) * 1_000_000)) . 'Z';
        $keep = max(1, min(365, (int) ($input['keep'] ?? 14)));

        $plain = match ($action) {
            'backup.db' => $this->dumpDb($runtime, $config, (string) ($args[0] ?? ($input['database'] ?? 'all'))),
            'backup.files' => $this->tarSite($runtime, $config, (string) ($args[0] ?? '')),
            'backup.caddy' => $this->tarCaddy($runtime, $config, (bool) ($input['include_fpm'] ?? false)),
            default => throw new BrokerException('Unknown backup action.', 2),
        };

        $blob = ArchiveCrypto::encrypt($plain['bytes'], $passphrase);
        unset($plain['bytes']);

        if ($destination === 'local') {
            return $this->storeLocal($runtime, $config, $plain, $blob, $stamp, $keep);
        }

        $client = SpacesClient::fromInput($input['spaces'] ?? []);
        $key = 'azerioid/' . $plain['kind'] . '/' . $plain['name'] . '/' . $stamp . '.bin';
        $uploaded = $client->put($key, $blob);
        unset($blob);

        return [
            'key' => $uploaded['key'],
            'size' => $uploaded['size'],
            'kind' => $plain['kind'],
            'name' => $plain['name'],
            'sha256' => $plain['sha256'],
            'destination' => 'spaces',
            'encrypted' => true,
        ];
    }

    /** @param array<string,mixed> $input */
    private function destination(array $input): string
    {
        $dest = strtolower(trim((string) ($input['destination'] ?? 'spaces')));
        if ($dest === 'local') {
            return 'local';
        }
        if ($dest === 'spaces' || $dest === '') {
            return 'spaces';
        }
        throw new BrokerException('destination must be spaces or local.', 2);
    }

    /**
     * @param array{kind:string,name:string,sha256:string} $plain
     * @return array{key:string,size:int,kind:string,name:string,sha256:string,destination:string,encrypted:bool,pruned:list<string>}
     */
    private function storeLocal(
        Runtime $runtime,
        Config $config,
        array $plain,
        string $blob,
        string $stamp,
        int $keep,
    ): array {
        $base = rtrim($config->localBackupDir, '/');
        $dir = $base . '/' . $plain['kind'] . '/' . $plain['name'];
        $runtime->mkdir($base, 0750);
        $runtime->mkdir($base . '/' . $plain['kind'], 0750);
        $runtime->mkdir($dir, 0750);
        $path = $dir . '/' . $stamp . '.bin';
        if ($runtime->resolveUnderBase($path, $base) === null) {
            throw new BrokerException('Local backup path escaped backup root.', 3);
        }
        $runtime->writeFile($path, $blob, 0600);
        $pruned = $this->pruneLocal($runtime, $dir, $keep);

        return [
            'key' => $path,
            'size' => strlen($blob),
            'kind' => $plain['kind'],
            'name' => $plain['name'],
            'sha256' => $plain['sha256'],
            'destination' => 'local',
            'encrypted' => true,
            'pruned' => $pruned,
        ];
    }

    /** @return list<string> */
    private function pruneLocal(Runtime $runtime, string $dir, int $keep): array
    {
        $files = [];
        foreach ($runtime->listDir($dir) as $name) {
            if (!str_ends_with($name, '.bin')) {
                continue;
            }
            $path = rtrim($dir, '/') . '/' . $name;
            if ($runtime->fileExists($path)) {
                $files[] = $path;
            }
        }
        rsort($files, SORT_STRING);
        $deleted = [];
        foreach (array_slice($files, $keep) as $old) {
            $runtime->deleteFile($old);
            $deleted[] = $old;
        }
        return $deleted;
    }

    /** @return array{kind:string,name:string,bytes:string,sha256:string} */
    private function dumpDb(Runtime $runtime, Config $config, string $which): array
    {
        $which = trim($which);
        $args = [
            '/usr/bin/mysqldump',
            '--protocol=socket',
            '--socket=' . $config->mysqlSocket,
            '--single-transaction',
            '--quick',
            '--routines',
            '--skip-comments',
        ];
        if ($which === '' || $which === 'all') {
            $args[] = '--all-databases';
            $name = 'all';
        } else {
            $name = Validator::dbName($which);
            $args[] = $name;
        }
        $cnf = $this->defaultsFile($runtime, $config);
        array_splice($args, 1, 0, ['--defaults-extra-file=' . $cnf]);
        try {
            $result = $runtime->exec($args, null, 300);
        } finally {
            $runtime->deleteFile($cnf);
        }
        if (!$result->ok() || $result->stdout === '') {
            throw new BrokerException(trim($result->stderr) !== '' ? trim($result->stderr) : 'mysqldump failed.', 1);
        }
        return [
            'kind' => 'db',
            'name' => $name,
            'bytes' => $result->stdout,
            'sha256' => hash('sha256', $result->stdout),
        ];
    }

    /** @return array{kind:string,name:string,bytes:string,sha256:string} */
    private function tarSite(Runtime $runtime, Config $config, string $site): array
    {
        $site = Validator::siteName($site);
        $root = rtrim($config->wwwRoot, '/') . '/' . $site;
        if ($runtime->resolveUnderBase($root, $config->wwwRoot) === null) {
            throw new BrokerException('Site path escaped www root.', 3);
        }
        if (!$runtime->isDir($root) && !$runtime->fileExists($root)) {
            throw new BrokerException('Site directory does not exist.', 2);
        }
        $out = rtrim($config->stagingDir, '/') . '/files-' . $site . '.tgz';
        $runtime->mkdir($config->stagingDir, 0750);
        $result = $runtime->exec([
            '/usr/bin/tar',
            '-C',
            $config->wwwRoot,
            '-czf',
            $out,
            '--exclude=' . $site . '/vendor',
            '--exclude=' . $site . '/node_modules',
            '--exclude=' . $site . '/storage/logs',
            $site,
        ], null, 300);
        if (!$result->ok()) {
            throw new BrokerException(trim($result->stderr) !== '' ? trim($result->stderr) : 'tar failed.', 1);
        }
        $bytes = $runtime->readFile($out);
        $runtime->deleteFile($out);
        return [
            'kind' => 'files',
            'name' => $site,
            'bytes' => $bytes,
            'sha256' => hash('sha256', $bytes),
        ];
    }

    /** @return array{kind:string,name:string,bytes:string,sha256:string} */
    private function tarCaddy(Runtime $runtime, Config $config, bool $includeFpm): array
    {
        $out = rtrim($config->stagingDir, '/') . '/caddy.tgz';
        $runtime->mkdir($config->stagingDir, 0750);
        $cmd = array_merge(['/usr/bin/tar', '-czf', $out], WebServers::for($config)->backupPaths($config));
        if ($includeFpm) {
            $cmd[] = '/etc/php';
        }
        $result = $runtime->exec($cmd, null, 120);
        if (!$result->ok()) {
            throw new BrokerException(trim($result->stderr) !== '' ? trim($result->stderr) : 'tar failed.', 1);
        }
        $bytes = $runtime->readFile($out);
        $runtime->deleteFile($out);
        return [
            'kind' => 'caddy',
            'name' => $includeFpm ? 'caddy-php' : 'caddy',
            'bytes' => $bytes,
            'sha256' => hash('sha256', $bytes),
        ];
    }

    private function defaultsFile(Runtime $runtime, Config $config): string
    {
        $path = rtrim($config->stagingDir, '/') . '/mysqldump.cnf';
        $runtime->mkdir($config->stagingDir, 0750);
        $body = "[client]\nuser={$config->mysqlUser}\npassword={$config->mysqlPassword}\nsocket={$config->mysqlSocket}\n";
        $runtime->writeFile($path, $body, 0600);
        return $path;
    }
}
