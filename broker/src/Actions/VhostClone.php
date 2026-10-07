<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\AppRuntime;
use AzerioidPanel\Broker\Vhost\VhostUser;
use AzerioidPanel\Broker\Web\WebServers;

/**
 * A79: clone a site to a new domain for staging. Increment 1 covers the vhost
 * and the file tree; databases (increment 2) and the Octane/PM2/Docker runtimes
 * (increment 3) follow. The clone is a fully isolated vhost with its own
 * az-vh-<dst> identity — it does not share files, pool or identity with the
 * source (A49).
 */
final class VhostClone
{
    /** @return array<string,mixed> */
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $src = Validator::domain((string) ($args[0] ?? ($input['source'] ?? '')));
        $dst = Validator::domain((string) ($args[1] ?? ($input['domain'] ?? '')));
        if ($src === $dst) {
            throw new BrokerException('The clone must use a different domain than the source.', 2);
        }
        $blocked = array_map('strtolower', $config->readonlyVhosts);
        if (in_array($dst, $blocked, true) || $dst === 'default' || $dst === 'azerioid-panel') {
            throw new BrokerException("{$dst} is managed externally and can't be created.", 3);
        }

        $servers = WebServers::for($config);
        $all = $servers->listVhosts($runtime, $config);
        $source = null;
        foreach ($all as $v) {
            if (($v['domain'] ?? '') === $src) {
                $source = $v;
            }
            if (($v['domain'] ?? '') === $dst) {
                throw new BrokerException("A vhost for {$dst} already exists; choose a new domain for the clone.", 3);
            }
        }
        if ($source === null) {
            throw new BrokerException("Source vhost {$src} was not found.", 2);
        }

        $srcRuntime = (string) ($source['runtime'] ?? AppRuntime::FPM);
        if ($srcRuntime !== AppRuntime::FPM) {
            // Increment 3 will replicate Octane/PM2/Docker onto the clone.
            throw new BrokerException(
                "Cloning a {$srcRuntime} site is not supported yet — clone a PHP, static or proxy site, "
                . 'or disable the runtime on the source first.',
                3
            );
        }

        $srcRoot = (string) $source['root'];
        $srcTop = $this->siteTop($config, $srcRoot);
        $dstTop = rtrim($config->wwwRoot, '/') . '/' . $dst;
        if ($srcTop === '' || !str_starts_with($srcRoot, $srcTop)) {
            throw new BrokerException('Could not resolve the source site directory.', 1);
        }
        $dstRoot = $dstTop . substr($srcRoot, strlen($srcTop));

        // The clone's directory must be brand new. If anything already lives at
        // $dstTop we would rsync into it and then hand it to the clone's identity
        // (VhostUser::ensure chowns the tree) — a cross-site overwrite/takeover of
        // whatever was there. Refuse, rather than clobber.
        if ($runtime->fileExists($dstTop) || $runtime->isLink($dstTop)) {
            throw new BrokerException("{$dstTop} already exists; choose a new domain for the clone.", 3);
        }
        // Another vhost's root may sit under $dstTop (root set by hand, so no vhost
        // is named $dst) or under $srcTop (a site nested inside the source tree).
        // In the first case we'd delete/own its files; in the second we'd copy them
        // into the clone. Keep each vhost's files to its own vhost.
        foreach ($all as $v) {
            $root = (string) ($v['root'] ?? '');
            if ($root === $dstTop || str_starts_with($root, $dstTop . '/')) {
                throw new BrokerException("Another vhost already keeps files under {$dstTop}; choose a new domain.", 3);
            }
            if (($v['domain'] ?? '') !== $src && ($root === $srcTop || str_starts_with($root, $srcTop . '/'))) {
                throw new BrokerException("Another vhost keeps files under the source directory {$srcTop}; it can't be cloned in isolation.", 3);
            }
        }

        // Provision the clone as its own isolated vhost (new az-vh-<dst> identity).
        $servers->addVhost($runtime, $config, [
            'domain' => $dst,
            'root' => $dstRoot,
            'type' => (string) ($source['type'] ?? 'php'),
            'php_version' => $source['php_version'] ?? null,
            'upstream' => $source['reverse_proxy'] ?? null,
            'engine' => (string) ($source['engine'] ?? 'caddy'),
        ]);

        // Copy the whole source site tree into the clone. Deliberately NOT rsync -a:
        //  -rlt      — recurse, keep symlinks as links (not followed), keep mtimes;
        //  no -p     — do not preserve source modes. A tenant can set the setuid/setgid
        //              bit on a file they own; with --no-o the copy becomes root-owned,
        //              and a root-owned setuid file is a local-root primitive. Dropping
        //              -p (and --chmod=ug-s as a belt-and-braces) means no such bit
        //              survives; applyOwnership below then sets the final 2770/0660.
        //  --no-D    — do not recreate device or special files as root from a
        //              tenant-controlled tree.
        //  --no-o --no-g — root-owned copy (not az-vh-<src>): the clone must not inherit
        //              the SOURCE tenant's ownership, and a root-owned tree means no
        //              tenant can reach $dstTop to race the handover below.
        //  --one-file-system stays on the site's own filesystem. No --delete: $dstTop
        //  is freshly created, nothing to prune, and --delete into a tree is a foot-gun.
        $copy = $runtime->exec([
            '/usr/bin/rsync', '-rlt', '--no-D', '--no-o', '--no-g', '--one-file-system',
            '--chmod=ug-s', '--exclude', '.git/',
            rtrim($srcTop, '/') . '/', rtrim($dstTop, '/') . '/',
        ], null, 1800);
        if (!$copy->ok()) {
            throw new BrokerException('Copying the site files failed: ' . trim($copy->stderr), 1);
        }

        // Create the clone's identity and hand it the docroot + top directory; the
        // A72 sweep (bounded to the site top) quarantines any symlink that escapes
        // the clone's tree.
        VhostUser::ensure($runtime, $config, $dst, $dstRoot);

        // ensure() only owns the docroot and the top directory. The copy also holds
        // the app code above the docroot (vendor, .env, storage) still owned by root
        // from the copy — hand the WHOLE copied tree to the clone's identity so the
        // clone, and only the clone, owns its files (A49 isolation). The group is
        // whatever ensure settled the docroot on (az-vh-<dst> once migrated, the
        // legacy group before), read back so this stays correct either way. Safe to
        // chown -R as root here: the tree is root-owned throughout, so no tenant can
        // race it (unlike applyOwnership's general case — A77).
        $grp = trim($runtime->exec(['/usr/bin/stat', '-c', '%G', $dstRoot], null, 10)->stdout);
        if ($grp === '') {
            // Fail closed: a clone the identity does not own is both useless and a
            // root-owned tree we must not leave behind. Roll back the copy and vhost.
            $runtime->exec(['/bin/rm', '-rf', '--one-file-system', $dstTop], null, 300);
            $servers->removeVhost($runtime, $config, $dst);
            throw new BrokerException('Could not hand the clone over to its identity; the clone was removed.', 1);
        }
        VhostUser::applyOwnership($runtime, $dstTop, VhostUser::username($dst), $grp);

        return [
            'source' => $src,
            'domain' => $dst,
            'root' => $dstRoot,
            'type' => (string) ($source['type'] ?? 'php'),
            'files_copied' => true,
            'note' => 'Databases and Octane/PM2/Docker runtimes are not cloned yet.',
        ];
    }

    private function siteTop(Config $config, string $root): string
    {
        $www = rtrim($config->wwwRoot, '/') . '/';
        if (!str_starts_with($root, $www)) {
            return '';
        }
        $first = explode('/', substr($root, strlen($www)))[0];

        return $first === '' ? '' : $www . $first;
    }
}
