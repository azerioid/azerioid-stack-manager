<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Files\VhostFiles;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\VhostUser;

final class VhostFilesAction
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $op = match ($action) {
            'vhost.files.list' => 'list',
            'vhost.files.read' => 'read',
            'vhost.files.write' => 'write',
            'vhost.files.mkdir' => 'mkdir',
            'vhost.files.rename' => 'rename',
            'vhost.files.move' => 'move',
            'vhost.files.copy' => 'copy',
            'vhost.files.chmod' => 'chmod',
            'vhost.files.search' => 'search',
            'vhost.files.zip' => 'zip',
            'vhost.files.extract' => 'extract',
            'vhost.files.delete' => 'delete',
            default => throw new BrokerException('Unknown file manager action.', 2),
        };
        $domain = Validator::domain((string) ($args[0] ?? ($input['domain'] ?? '')));

        if ($op === 'zip') {
            return $this->zip($domain, $input, $runtime, $config);
        }

        return (new VhostFiles($config, $runtime))->run($op, $domain, $input);
    }

    /**
     * Wrap the zip operation in the ownership handover it needs (B3 / G12).
     *
     * The operation runs as the vhost identity (the helper drops to it with posix_setuid), so
     * the archive it writes belongs to that identity — which is the point: the site's bytes
     * never pass through panel PHP. But the panel then has to stream the file, and it runs as
     * a different user, so root has to hand it over in the middle.
     *
     * Three steps, each with a reason:
     *  1. A per-request directory, owned by the vhost identity, under the staging tree. Not a
     *     shared directory: two archives being built at once must not be able to see or
     *     overwrite each other, and the name must not be guessable by the site itself.
     *  2. The operation writes the archive there, as the site.
     *  3. Root hands the finished archive to the panel user, read-only, and removes the
     *     directory's group/other access so nothing else on the host can read a copy of
     *     someone's site while it waits to be streamed.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function zip(string $domain, array $input, Runtime $runtime, Config $config): array
    {
        $identity = VhostUser::username($domain);
        $dir = rtrim($config->stagingDir, '/') . '/zip-' . bin2hex(random_bytes(8));
        $out = $dir . '/' . preg_replace('/[^a-zA-Z0-9._-]+/', '-', $domain) . '-files.zip';

        $runtime->mkdir($config->stagingDir, 0750);
        $runtime->mkdir($dir, 0700);
        $runtime->chown($dir, $identity, $identity);

        try {
            $result = (new VhostFiles($config, $runtime))->run('zip', $domain, $input + ['out' => $out]);
        } catch (\Throwable $e) {
            // Never leave a half-built archive of someone's site lying in staging.
            $runtime->exec(['/bin/rm', '-rf', $dir], null, 30);
            throw $e;
        }

        if (!$runtime->fileExists($out)) {
            $runtime->exec(['/bin/rm', '-rf', $dir], null, 30);
            throw new BrokerException('The archive was reported written but is not there.', 1);
        }

        // Handover: the panel reads it, nobody else on the host does.
        $runtime->chown($out, $config->panelUser, $config->panelUser);
        $runtime->chmod($out, 0400);
        $runtime->chown($dir, 'root', 'root');
        $runtime->chmod($dir, 0710);

        return $result + [
            'path' => $out,
            'directory' => $dir,
            'owner' => $config->panelUser,
            // The caller streams it and is responsible for removing the directory afterwards;
            // azerioid:maintenance sweeps anything left behind by a request that died.
            'cleanup' => $dir,
        ];
    }
}
