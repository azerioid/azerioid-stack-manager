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
    /** File name prefix of zips handed to the panel in storage/framework/tmp. */
    public const HANDOVER_PREFIX = 'azerioid-zip-';

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
     * Steps, each with a reason:
     *  1. A per-request directory, owned by the vhost identity, under vhostZipBuildDir. Not
     *     under /var/lib/azerioid-panel: vhost identities cannot traverse it (A39). Not a
     *     shared directory: two archives being built at once must not see or overwrite each
     *     other, and the base is 0711 so no site can list another's directory.
     *  2. The operation writes the archive there, as the site.
     *  3. Root takes the directory back, refuses anything that is not a regular file (a
     *     planted symlink would make the panel stream its target), and moves the archive into
     *     the panel's own storage/framework/tmp, owned by the panel user. The panel can read
     *     it and delete it after streaming; it could do neither in a root-owned directory.
     *  4. The build directory is always removed.
     *
     * v1.9.0 shipped this broken at every step on real hosts: the helper never received
     * `paths`/`out` (VhostFiles::run() did not forward them), the identity could not reach
     * the staging tree, and the panel could not read a file in a root-owned 0710 directory.
     * VhostFilesSeamTest now runs the real operation on the real payload.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function zip(string $domain, array $input, Runtime $runtime, Config $config): array
    {
        $identity = VhostUser::username($domain);
        $base = rtrim($config->vhostZipBuildDir, '/');
        $dir = $base . '/zip-' . bin2hex(random_bytes(8));
        $out = $dir . '/' . preg_replace('/[^a-zA-Z0-9._-]+/', '-', $domain) . '-files.zip';

        $runtime->mkdir($base, 0711);
        $runtime->chown($base, 'root', 'root');
        $runtime->chmod($base, 0711);
        $runtime->mkdir($dir, 0700);
        $runtime->chown($dir, $identity, $identity);

        try {
            $result = (new VhostFiles($config, $runtime))->run('zip', $domain, $input + ['out' => $out]);

            // Take the directory back before touching what is in it: the site owned it while
            // the archive was built, and must not be able to swap the file mid-handover.
            $runtime->chown($dir, 'root', 'root');
            $runtime->chmod($dir, 0700);
            if (!$runtime->fileExists($out)) {
                throw new BrokerException('The archive was reported written but is not there.', 1);
            }
            // A symlink planted in place of the archive would make the panel stream whatever
            // it points at. Only a regular file is handed over.
            $type = trim($runtime->exec(['/usr/bin/stat', '-c', '%F', $out], null, 10)->stdout);
            if ($type !== 'regular file') {
                throw new BrokerException('Refusing to hand over the archive: it is not a regular file (' . $type . ').', 1);
            }

            // Handover into the panel's own temp dir, where the panel can read the archive and
            // delete it itself once streamed. It could do neither in a root-owned directory.
            $handover = rtrim($config->panelRoot, '/') . '/web/storage/framework/tmp/' . self::HANDOVER_PREFIX
                . bin2hex(random_bytes(8)) . '.zip';
            $mv = $runtime->exec(['/bin/mv', '-f', $out, $handover], null, 120);
            if (!$mv->ok()) {
                throw new BrokerException('Could not hand the archive to the panel: ' . trim($mv->stderr), 1);
            }
            $runtime->chown($handover, $config->panelUser, $config->panelUser);
            $runtime->chmod($handover, 0600);
        } finally {
            // Never leave a copy of someone's site in the build tree, whatever happened.
            $runtime->exec(['/bin/rm', '-rf', $dir], null, 30);
        }

        // Handover keys first: the helper's own reply also has a `path` (the build path,
        // now deleted), and `+` keeps the left-hand value.
        return [
            'path' => $handover,
            'owner' => $config->panelUser,
            // The panel streams it and deletes it; azerioid:maintenance removes any a
            // request left behind (HANDOVER_PREFIX, older than an hour).
            'cleanup' => '',
        ] + $result;
    }
}
