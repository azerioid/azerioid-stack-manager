<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

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

        // Provision the clone as its own isolated vhost (new az-vh-<dst> identity).
        $servers->addVhost($runtime, $config, [
            'domain' => $dst,
            'root' => $dstRoot,
            'type' => (string) ($source['type'] ?? 'php'),
            'php_version' => $source['php_version'] ?? null,
            'upstream' => $source['reverse_proxy'] ?? null,
            'engine' => (string) ($source['engine'] ?? 'caddy'),
        ]);
        // addVhost seeds a welcome index into the empty docroot. It is not part of
        // the source: left in place it survives the copy whenever the source has no
        // file of that name. $dstTop did not exist before addVhost, so anything in
        // the docroot now is that seed.
        foreach (['index.php', 'index.html'] as $seeded) {
            if ($runtime->fileExists($dstRoot . '/' . $seeded)) {
                $runtime->deleteFile($dstRoot . '/' . $seeded);
            }
        }

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
        // Exit 24 is "some source files vanished during the transfer" — routine on a
        // live site (sessions, cache files) and the copy is otherwise complete. Any
        // other failure leaves a half-copied tree behind a vhost that already routes:
        // remove both, so nothing half-built is served and a retry is not refused.
        if (!$copy->ok() && $copy->exitCode !== 24) {
            $this->rollback($runtime, $config, $dst, $dstTop);
            throw new BrokerException('Copying the site files failed; the clone was removed. ' . trim($copy->stderr), 1);
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
            $this->rollback($runtime, $config, $dst, $dstTop);
            throw new BrokerException('Could not hand the clone over to its identity; the clone was removed.', 1);
        }
        // keepExec: the code above the docroot (vendor/bin, node_modules/.bin targets,
        // artisan, deploy scripts) must stay runnable, as it is on the source.
        VhostUser::applyOwnership($runtime, $dstTop, VhostUser::username($dst), $grp, true, $config->wwwRoot);
        // The handover above reset every file's mode, undoing the .env 0600 that
        // ensure()'s A72 pass had set — and that pass ran while everything above the
        // docroot was still root's, so it could not remove an escaping symlink there.
        // Run it again now that the clone owns its tree. (ensure() skips it for a
        // not-yet-migrated identity, whose pool does not run as the site user.)
        if ($grp !== VhostUser::LEGACY_GROUP) {
            VhostUser::hardenContent($runtime, $config, $dstRoot, VhostUser::username($dst));
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
    private function rollback(Runtime $runtime, Config $config, string $dst, string $dstTop): void
    {
        try {
            (new VhostDel())->handle('vhost.del', [$dst], [], $runtime, $config);
        } catch (\Throwable) {
            WebServers::for($config)->removeVhost($runtime, $config, $dst);
        }
        $runtime->exec(['/bin/rm', '-rf', '--one-file-system', $dstTop], null, 300);
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
