# Known issues

Minor defects that are understood, not urgent, and waiting for a release to ride along with.
Anything security-relevant or data-affecting belongs in `DECISIONS.md` as an ADR or erratum instead.

Each entry: what happens, where, and what the fix is. Remove an entry in the commit that fixes it.

## KI-1 — `azerioid backup list` demands a passphrase it never uses

- **Found:** 2026-09-27, v2.0.0 live verification.
- **What happens:** `azerioid backup list --local` fails with "Backup passphrase missing" unless
  `AZERIOID_BACKUP_PASSPHRASE` is set or a passphrase is saved in the Backups UI. The broker's
  `backup.list` action does not read the passphrase at all, so any value lets the listing run.
- **Where:** `web/app/Console/Commands/Azerioid/BackupCommand.php`. `listBackups()` builds its stdin
  through `stdinFor()`, which always resolves the passphrase.
- **Fix:** have `listBackups()` send only `destination` (plus Spaces credentials when needed), and
  resolve the passphrase only for create, restore and verify.

## KI-2 — `azerioid cron add --json` prints plain text

- **Found:** 2026-09-27, v2.0.0 live verification.
- **What happens:** `add` ignores `--json` and prints `Added job-…; runs as …`, so a script cannot
  read the new job's id. `list`, by contrast, honours `--json`.
- **Where:** `web/app/Console/Commands/Azerioid/CronCommand.php`, the `add` path.
- **Fix:** when `--json` is set, emit the broker's response (including `id` and `runs_as`) with
  `emitData()`, as `list` does.

## KI-3 — On EL the panel's php-fpm copy never picks up distro PHP patches

- **Found:** 2026-09-27, while reviewing ADR A39 Amendment A39-A1.
- **What happens:** on EL, SELinux requires the panel master to run from a `bin_t` copy of php-fpm
  (`PREFIX/sbin/php-fpm`, A3). `deploy/lib/fpm.sh` makes that copy once, at install time. Neither
  self-update nor the A39 migration refreshes it, so a distro PHP security update reaches the site
  pools but not the panel until the panel is reinstalled. apt hosts are unaffected, because they run
  the distro binary directly.
- **Where:** `deploy/lib/fpm.sh` (`configure_panel_fpm_el`) and `broker/src/Panel/PanelUpdater.php`,
  which has no step for it.
- **Fix:** during self-update on EL, compare the copy with the distro php-fpm. When they differ,
  re-copy it, `restorecon` it and restart `azerioid-panel-php-fpm`.
- **Note:** this is the one entry here with security weight: an unpatched PHP in the panel on EL.
  It is listed rather than fixed only because no EL host was available to verify the change on.
