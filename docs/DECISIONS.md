# Architecture Decision Log

ADR-style record of locked decisions for AZERIOID Stack Manager.

## A19 — Clean history

**Status:** Accepted  
**Decision:** New repo `azerioid-panel` with clean git history. Install paths: `/usr/local/lib/azerioid-panel`, `/etc/azerioid-panel`, `/var/lib/azerioid-panel`. PHP broker namespace: `AzerioidPanel\Broker`. Upgrades from `lacmp-panel` paths use `deploy/relocate-from-lacmp.sh`.

## A1 — Panel PHP isolation

**Status:** Accepted  
**Decision:** Panel PHP visible in Settings (runtime section) and Components (non-removable system card). Pinned to PHP 8.4. Broker refuses removal while panel depends on it. Dedicated FPM pool at `/run/php/azerioid-panel.sock`, user `caddy`.

## A2 — Migration policy

**Status:** Accepted  
**Decision:** Adopt + `migrate.sh` for legacy installs. P1 targets fresh installs only. Adopt flow in P5; `deploy/migrate.sh` + `panel:import-from-mariadb` in P7.

## A3 — SELinux (EL)

**Status:** Accepted (P1 minimum)  
**Decision:** On EL with enforcing SELinux: `semanage port` for 3169, fcontext for `/data/www` and `/var/lib/azerioid-panel`, `httpd_can_network_connect`. Package: `policycoreutils-python-utils`.

## A4 — Distro package names

**Status:** Accepted  
**Decision:** Per-component `distros.{debian,ubuntu,el}` blocks in registry JSON with `packages`, `unit_name`, `detect`, and `repo` metadata.

## A5 — Package manager locks

**Status:** Partial (P1)  
**Decision:** `DPkg::Lock::Timeout=120` on apt. Full mutex deferred to P3.

## A6 — Job system

**Status:** Accepted (P1 foundation)  
**Decision:** `QUEUE_CONNECTION=database`, systemd queue worker unit, `PingJob` placeholder.

## A8 — Security defaults

**Status:** Deferred to P3+  
**Decision:** Component `secure` blocks in registry; applied after install in P3+.

## A9 — Port ownership

**Status:** Accepted  
**Decision:** See `docs/port-ownership.md`. Panel Caddy always single instance; snippet in `/etc/caddy/conf.d/`.

## A10 — GPG pinning

**Status:** Accepted (P1)  
**Decision:** Caddy official repo + Sury (deb) / Remi (EL) with GPG fingerprint verification in `deploy/lib/repos.sh`.

## A15 — RBAC

**Status:** Accepted  
**Decision:** v1 single admin. `users.role` nullable for future.

## A16 — Node scope

**Status:** Accepted  
**Decision:** v1 Node = runtime install only; no PM2.

## Product naming

**Status:** Accepted (superseded 2026-09-07 by A19 branding closure)  
**Decision (original):** User-facing name "Stack Manager". Entrypoint `stack-manager.sh`. Drop "LACMP Panel" from P1 user-facing copy.

## A19 branding closure — AZERIOID Stack Manager

**Status:** Accepted (operator decision, 2026-09-07)  
**Decision:**

1. **Display brand (long form):** `AZERIOID Stack Manager` — README title, page `<title>` tags, login/setup headers, CLI/installer copy, docs.
2. **Short form (space-constrained UI only):** `AZERIOID` — sidebar brand mark; subtitle may say `stack manager`. Do not mix alternate short forms.
3. **Technical slug stays `azerioid-panel`:** install paths, systemd units, sudoers, sockets, config dirs (`/usr/local/lib/azerioid-panel`, `azerioid-panel-queue.service`, `/etc/azerioid-panel/`, `/var/lib/azerioid-panel/`, etc.) are **not renamed**. No host migration path is planned for a path-level rename.
4. **GitHub repository:** rename outward-facing repo to `azerioid/azerioid-stack-manager` (GitHub redirects the old name). Existing clones must update `origin` explicitly after rename.

**Rationale:** Closes the branding item from the gap analysis (A19) without repeating the predecessor's half-measure path rename (LCMP→LACMP). User-facing identity matches the product; installed-system identity stays stable.

**Out of scope / deliberately unchanged:** encrypted backup magic (`LACMP1`/`LCMP1`), `LACMP_*` env fallbacks, and `deploy/relocate-from-lacmp.sh` (historical migrate-from tool).

## Panel database

**Status:** Accepted (P1)  
**Decision:** SQLite at `/var/lib/azerioid-panel/panel.sqlite`. No MariaDB panel DB in bootstrap.

## Bootstrap stack

**Status:** Accepted (P1)  
**Decision:** Self-contained installer installs Caddy + PHP 8.4 + SQLite. No `lcmp`/`lamp` prerequisite. Legacy detection becomes adopt path (P5).

## A20 — fail2ban flush on every install/reinstall

**Status:** Accepted (operator decision, 2026-09-02)  
**Decision:** On every AZERIOID Stack Manager install or reinstall (not scoped to fresh-install-only), the installer:

1. Unbans all IPs in the **`azerioid-panel` fail2ban jail only** (never touches other jails such as `sshd`).
2. Truncates **`/var/log/azerioid-panel/auth-fail.log`** only.

The uninstall path performs the same panel-scoped flush before removing the jail config.

**Rationale:** A `--drop-db` reinstall previously left `auth-fail.log` intact; fail2ban re-read it on jail enable and immediately re-banned the operator's IP, causing `ERR_CONNECTION_REFUSED` on a working install. Flushing on every run prevents self-lockout from stale ban state.

**Accepted tradeoff:** Ban history and accumulated failed-login records for the panel jail are wiped on each install/reinstall. An operator who reinstalls frequently loses attack-history signal in that log. This is intentional simplicity over retaining cross-reinstall ban history — do not "fix" this as a bug without an explicit ADR change.
