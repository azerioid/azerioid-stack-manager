# Known issues

Minor defects that are understood, not urgent, and waiting for a release to ride along with.
Anything security-relevant or data-affecting belongs in `DECISIONS.md` as an ADR or erratum instead.

Each entry: what happens, where, and what the fix is. Remove an entry in the commit that fixes it.

KI-1 and KI-2 were fixed in v2.0.3, KI-3 in v2.0.1/v2.0.2, and KI-5 (Adminer reloaded the wrong php-fpm master on migrated apt hosts, an A39 regression) in v2.0.4.

## KI-4 — On EL, Adminer's PHP is not confined by SELinux

- **Found:** 2026-09-27, v2.0.1 verification on Rocky Linux 9 (Enforcing).
- **What happens:** on EL the Adminer pool (`azerioid-adminer-tool`) is written into the panel
  master's pool directory, so its workers are children of `azerioid-panel-php-fpm`. They inherit that
  master's domain, `unconfined_service_t`, instead of `httpd_t` like the site pools on the distro
  master. The rest of Adminer's confinement is intact:
  - it runs as its own `nologin` account, with no sudo grant;
  - its pool disables `proc_open`, `exec`, `system`, `popen` and `pcntl_*`;
  - `open_basedir` limits it to its own directory and `/tmp`;
  - every request passes the panel's `forward_auth` first.

  What is missing is SELinux as a second layer. A PHP-level escape in Adminer would not be contained by
  the policy the way a site pool would be.
- **Not a regression:** this was already the case before A39. The panel master on EL has run
  `unconfined_service_t` since A3, so that `sudo` can start the broker. A39 did not change the domain.
- **Where:** `broker/src/Tool/AdminerTool.php`, `fpmPoolFile()` (the EL branch writes to
  `/etc/azerioid-panel/php-fpm.d/`).
- **Fix:** run the Adminer pool on the distro php-fpm master on EL (`/etc/php-fpm.d/`, `httpd_t`), as
  apt already does. This needs its socket, `open_basedir` paths and file contexts checked against
  `httpd_t` on an Enforcing host, so it waits for an EL host to verify on.
- **Note:** security-relevant (defence in depth), so it is a candidate for an ADR rather than a
  ride-along fix.
