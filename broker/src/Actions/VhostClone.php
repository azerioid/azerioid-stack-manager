<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Backup\ArchiveGuard;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\DatabaseManager;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\AppRuntime;
use AzerioidPanel\Broker\Vhost\OctaneManager;
use AzerioidPanel\Broker\Vhost\Pm2Manager;
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

        // Validate the database input before anything is provisioned: a malformed
        // pair must fail the request, not surface after the whole site was copied.
        $pairs = $this->databasePairs($input);
        $engine = $pairs === []
            ? ''
            : (new DatabaseManager($config, $runtime))->resolveEngine((string) ($input['engine'] ?? ''));

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

        $srcRuntime = AppRuntime::normalize((string) ($source['runtime'] ?? AppRuntime::FPM));
        if ($srcRuntime === AppRuntime::DOCKER) {
            // Docker carries many settings (image/compose/registry/env/volumes) and a
            // per-site rootless dockerd (A56); replicating it safely is a job of its
            // own. Clone the files, then set the container up on the clone by hand.
            throw new BrokerException(
                'Cloning a Docker site is not supported yet — clone it, then configure Docker on the clone.',
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

        // A103: copy first, provision after. The clone's directory is created root
        // 0700 and filled before any identity, pool or route for the clone exists, so
        // nothing runs as the clone — and no source code copied into it — while root
        // is still writing the tree (AZ-18). The source is read as the SOURCE identity
        // (AZ-32): root never walks the live, site-owned tree by path, so a component
        // the site swaps mid-copy reaches only what that site could already read.
        // The clone's identity must be new as well: refuse before anything is made, so a
        // rollback can never remove an account that belongs to another site (A96).
        VhostUser::assertNoIdentityCollision($runtime, $config, $dst, VhostUser::username($dst));
        $ownIdentity = !VhostUser::userExists($runtime, VhostUser::username($dst));
        $runtime->mkdir($dstTop, 0700);
        $runtime->chmod($dstTop, 0700);
        try {
            $this->copyAsSource($runtime, $config, $src, $srcTop, $dst, $dstTop);
        } catch (\Throwable $e) {
            $runtime->exec(['/bin/rm', '-rf', '--one-file-system', $dstTop], null, 300);
            throw str_contains($e->getMessage(), 'the clone was removed')
                ? $e
                : new BrokerException('Copying the site files failed; the clone was removed. ' . $e->getMessage(), $e instanceof BrokerException ? $e->getCode() : 1);
        }
        try {
            // The tree came from the source's stream: a docroot path through one of its
            // symlinks is refused, never created or followed by root.
            VhostUser::assertNoSymlinkUnder($runtime, $dstTop, $dstRoot);
            if (!$runtime->isDir($dstRoot)) {
                $runtime->mkdir($dstRoot, 0755);
            }
        } catch (\Throwable $e) {
            $runtime->exec(['/bin/rm', '-rf', '--one-file-system', $dstTop], null, 300);
            throw new BrokerException('The copied site has no plain docroot; the clone was removed. ' . $e->getMessage(), 3);
        }

        try {
            // Create the clone's identity and hand it the docroot + top directory; the
            // A72 sweep (bounded to the site top) quarantines any symlink that escapes
            // the clone's tree.
            VhostUser::ensure($runtime, $config, $dst, $dstRoot);

            // ensure() only owns the docroot and the top directory. The copy also holds
            // the app code above the docroot (vendor, .env, storage), root's from the
            // extraction — hand the WHOLE tree to the clone's identity (A49). The group is
            // whatever ensure settled the docroot on (az-vh-<dst> once migrated, the
            // legacy group before), read back so this stays correct either way.
            $grp = trim($runtime->exec(['/usr/bin/stat', '-c', '%G', $dstRoot], null, 10)->stdout);
            if ($grp === '') {
                throw new BrokerException('Could not hand the clone over to its identity; the clone was removed.', 1);
            }
            // keepExec: the code above the docroot (vendor/bin, node_modules/.bin targets,
            // artisan, deploy scripts) must stay runnable, as it is on the source.
            VhostUser::applyOwnership($runtime, $dstTop, VhostUser::username($dst), $grp, true, $config->wwwRoot);
            // The handover reset every file's mode, undoing the .env 0600 that ensure()'s
            // A72 pass had set — and that pass ran while everything above the docroot was
            // still root's. Run it again now that the clone owns its tree. (ensure() skips
            // it for a not-yet-migrated identity, whose pool does not run as the site user.)
            if ($grp !== VhostUser::LEGACY_GROUP) {
                VhostUser::hardenContent($runtime, $config, $dstRoot, VhostUser::username($dst));
            }

            // Only now: the clone's pool and route.
            $before = array_filter(['index.php', 'index.html'], static fn (string $f): bool => $runtime->fileExists($dstRoot . '/' . $f));
            $servers->addVhost($runtime, $config, [
                'domain' => $dst,
                'root' => $dstRoot,
                'type' => (string) ($source['type'] ?? 'php'),
                'php_version' => $source['php_version'] ?? null,
                'upstream' => $source['reverse_proxy'] ?? null,
                'engine' => (string) ($source['engine'] ?? 'caddy'),
            ]);
            // addVhost seeds a welcome index into an empty docroot; it is not part of the
            // source (whose docroot was empty, then), so it does not stay.
            // The tree is the clone's by now: remove it as the clone, not as root.
            foreach (['index.php', 'index.html'] as $seeded) {
                if (!in_array($seeded, $before, true) && $runtime->fileExists($dstRoot . '/' . $seeded)) {
                    $runtime->exec([$this->runuserBin($runtime), '-u', VhostUser::username($dst), '--', '/bin/rm', '-f', '--', $dstRoot . '/' . $seeded], null, 15);
                    if ($runtime->fileExists($dstRoot . '/' . $seeded)) {
                        $runtime->deleteFile($dstRoot . '/' . $seeded); // FakeRuntime / non-root runs
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->rollback($runtime, $config, $dst, $dstTop, $ownIdentity);
            throw $e instanceof BrokerException ? $e : new BrokerException('Provisioning the clone failed; the clone was removed. ' . $e->getMessage(), 1);
        }

        // A79 increment 2: optionally clone named databases alongside the files.
        // The operator names each as source:target (databases are global and not
        // tied to a domain, so there is nothing to auto-discover). Each is cloned
        // best-effort into a fresh DB + user — a DB failure rolls its own target
        // back (DbClone) and is reported, but never undoes the file clone above.
        $cloned = [];
        $dbErrors = [];
        foreach ($pairs as [$dbSource, $dbTarget]) {
            try {
                $cloned[] = DbClone::run($runtime, $config, $engine, $dbSource, $dbTarget, $dbTarget);
            } catch (\Throwable $e) {
                $dbErrors[] = ['source' => $dbSource, 'target' => $dbTarget, 'error' => $e->getMessage()];
            }
        }

        // A79 increment 3: replicate the source's app runtime onto the clone. The
        // file copy already brought the runtime's artifacts (vendor + FrankenPHP for
        // Octane, node_modules for PM2); enabling here provisions the clone's own
        // supervisor program on a freshly allocated loopback port under the clone's
        // identity. Best-effort: a failure leaves the clone as a plain FPM site and
        // is reported, rather than undoing the file clone. Docker was refused above.
        //
        // Catching here is safe because enable() is transactional: it switches the
        // clone's Caddy routing to the loopback port only AFTER waitForPort confirms
        // the worker is listening, and on any later failure it removes the program and
        // leaves the vhost on PHP-FPM. So a failed enable never leaves the clone
        // routing to a dead or another tenant's port — it stays a plain PHP site.
        $runtimeEnabled = null;
        $runtimeError = null;
        if ($srcRuntime === AppRuntime::OCTANE) {
            try {
                (new OctaneManager($config, $runtime))->enable($dst, [
                    'max_requests' => $source['octane_max_requests'] ?? null,
                ]);
                $runtimeEnabled = AppRuntime::OCTANE;
            } catch (\Throwable $e) {
                $runtimeError = $e->getMessage();
            }
        } elseif ($srcRuntime === AppRuntime::PM2) {
            try {
                $pm2 = new Pm2Manager($config, $runtime);
                $pm2->enable($dst, array_filter([
                    'instances' => $source['pm2_instances'] ?? null,
                    'entry' => $source['pm2_entry'] ?? null,
                    // node_modules were built against the source's Node major.
                    'node' => $pm2->nodeOf($src),
                ], static fn ($v) => $v !== null));
                $runtimeEnabled = AppRuntime::PM2;
            } catch (\Throwable $e) {
                $runtimeError = $e->getMessage();
            }
        }

        $dbNote = $cloned === [] && $dbErrors === []
            ? ''
            : 'Update the clone\'s config with the new database name, user and password. ';
        $rtNote = $runtimeError !== null
            ? 'The ' . $srcRuntime . ' runtime could not be enabled on the clone; it is serving as a plain PHP site.'
            : '';

        // What the panel's Operations page keeps of a queued clone. No passwords:
        // that record is stored and readable for months.
        $detail = ["Copied {$srcTop} to {$dstTop}."];
        foreach ($cloned as $db) {
            $detail[] = "Database {$db['source']} cloned to {$db['target']} (user {$db['user']}).";
        }
        foreach ($dbErrors as $err) {
            $detail[] = "Database {$err['source']} → {$err['target']} NOT cloned: {$err['error']}";
        }
        if ($runtimeEnabled !== null) {
            $detail[] = "Runtime {$runtimeEnabled} enabled on the clone.";
        }
        if ($runtimeError !== null) {
            $detail[] = $rtNote . ' ' . $runtimeError;
        }

        return [
            'source' => $src,
            'domain' => $dst,
            'root' => $dstRoot,
            'type' => (string) ($source['type'] ?? 'php'),
            'files_copied' => true,
            'databases' => $cloned,
            'database_errors' => $dbErrors,
            'runtime' => $runtimeEnabled,
            'runtime_error' => $runtimeError,
            'detail' => $detail,
            'note' => trim($dbNote . $rtNote) !== '' ? trim($dbNote . $rtNote) : 'Clone complete.',
        ];
    }

    /**
     * Parse the source:target database pairs from `databases` (array) or `db`
     * (comma-separated string). A bare name with no `:target` is rejected — the
     * target must be named explicitly (db names are a 32-char namespace, so there
     * is no safe auto-derived name).
     *
     * @return list<array{0:string,1:string}>
     */
    private function databasePairs(array $input): array
    {
        $raw = $input['databases'] ?? $input['db'] ?? [];
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)), static fn ($s) => $s !== '');
        }
        if (!is_array($raw)) {
            return [];
        }
        $pairs = [];
        foreach ($raw as $entry) {
            if (!is_string($entry) || !str_contains($entry, ':')) {
                throw new BrokerException('Each database must be given as source:target (e.g. shop:shop_staging).', 2);
            }
            [$s, $t] = explode(':', $entry, 2);
            $pairs[] = [Validator::dbName(trim($s)), Validator::dbName(trim($t))];
        }

        return $pairs;
    }

    /**
     * Undo a clone that failed before it was handed over. VhostDel is the full
     * inverse of addVhost (pool, identity, routing); the copied tree goes too —
     * it is a copy, and $dstTop was verified not to exist before we created it.
     */
    private function rollback(Runtime $runtime, Config $config, string $dst, string $dstTop, bool $ownIdentity = true): void
    {
        try {
            (new VhostDel())->handle('vhost.del', [$dst], [], $runtime, $config);
        } catch (\Throwable) {
            // The vhost may not exist yet (A103: the identity comes first); still drop
            // what this clone made — the identity only if this run created it.
            try {
                WebServers::for($config)->removeVhost($runtime, $config, $dst);
            } catch (\Throwable) {
            }
            if ($ownIdentity) {
                VhostUser::deprovision($runtime, $config, $dst);
            }
        }
        $runtime->exec(['/bin/rm', '-rf', '--one-file-system', $dstTop], null, 300);
    }

    /**
     * A103 (AZ-32): the source tree, archived by the source identity and extracted by
     * root into the empty, root-only $dstTop. The stream is the site's to shape (it can
     * reach that tar process), so it is inspected first like a backup archive: no
     * devices, FIFOs, setuid/setgid files, absolute or `..` names, hardlinks (it is
     * made with --hard-dereference, so a hardlink is crafted), or entries below a symlink. Symlinks are kept as they are (`storage:link` makes an
     * absolute one); the clone's A72 sweep quarantines one that escapes its tree.
     */
    private function copyAsSource(Runtime $runtime, Config $config, string $src, string $srcTop, string $dst, string $dstTop): void
    {
        $srcUser = VhostUser::username($src);
        if (!VhostUser::userExists($runtime, $srcUser)) {
            throw new BrokerException("The source site has no identity of its own ({$srcUser}); it can't be cloned.", 3);
        }
        $runuser = $this->runuserBin($runtime);
        $runtime->mkdir($config->stagingDir, 0750);
        $stem = rtrim($config->stagingDir, '/') . '/clone-' . VhostUser::slug($dst) . '-' . bin2hex(random_bytes(8));
        $archive = $stem . '.tar';
        $checked = $stem . '.checked.tar';
        try {
            // Root's shell owns the redirect (umask 077, in root's staging dir); tar runs
            // as the source. --ignore-failed-read: a file the source cannot read is not
            // the clone's either. Exit 1 is "a file changed while being read" — routine
            // on a live site (sessions, caches), the archive is otherwise complete.
            $pack = $runtime->exec([
                '/bin/sh', '-c', 'umask 077 && exec "$@" > "$0"', $archive,
                $runuser, '-u', $srcUser, '--',
                '/usr/bin/tar', '-C', $srcTop, '--one-file-system', '--ignore-failed-read', '--hard-dereference',
                '--warning=no-file-changed', '--exclude=.git', '-cf', '-', '.',
            ], null, 1800);
            if (!$pack->ok() && $pack->exitCode !== 1) {
                throw new BrokerException('Copying the site files failed; the clone was removed. ' . trim($pack->stderr), 1);
            }
            // The source's tar held the archive open, and its uid can reopen that fd
            // through /proc and keep writing after the inspection. Inspect and extract
            // a copy root made after tar exited, which no source process ever held.
            $copy = $runtime->exec(['/bin/cp', '--', $archive, $checked], null, 600);
            $runtime->deleteFile($archive);
            if (!$copy->ok()) {
                throw new BrokerException('Copying the site files failed; the clone was removed. ' . trim($copy->stderr), 1);
            }
            // tar -x detects compression by magic, the inspection reads through gzopen:
            // a plain tar is all the source's tar makes, so anything that starts like a
            // compressed stream is crafted — refuse it, and both read the same bytes.
            $head = (string) $runtime->exec(['/usr/bin/head', '-c', '6', '--', $checked], null, 10)->stdout;
            foreach (["\x1f\x8b", "\x1f\x9d", 'BZh', "\xfd7zXZ", "\x28\xb5\x2f\xfd", 'LZIP', "\x5d\x00\x00"] as $magic) {
                if (str_starts_with($head, $magic)) {
                    throw new BrokerException('Copying the site files failed; the clone was removed. The copy stream is compressed.', 3);
                }
            }
            ArchiveGuard::inspectStream($runtime->gzReader($checked), anySymlinkTarget: true);
            $unpack = $runtime->exec(
                array_merge(['/usr/bin/tar', '-C', $dstTop], ArchiveGuard::safeExtractFlags(), ['-xf', $checked]),
                null,
                1800
            );
            if (!$unpack->ok()) {
                throw new BrokerException('Copying the site files failed; the clone was removed. ' . trim($unpack->stderr), 1);
            }
        } finally {
            foreach ([$archive, $checked] as $f) {
                if ($runtime->fileExists($f)) {
                    $runtime->deleteFile($f);
                }
            }
        }
    }

    private function runuserBin(Runtime $runtime): string
    {
        foreach (['/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser'] as $bin) {
            if ($runtime->fileExists($bin)) {
                return $bin;
            }
        }

        return '/usr/sbin/runuser';
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
