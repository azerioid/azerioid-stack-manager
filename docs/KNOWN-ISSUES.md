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
