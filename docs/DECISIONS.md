# Architecture Decision Log

ADR-style record of locked decisions for AZERIOID Stack Manager.

## A19 — Clean history

**Status:** Accepted  
**Decision:** New repo with clean git history. Install paths: `/usr/local/lib/azerioid-panel`, `/etc/azerioid-panel`, `/var/lib/azerioid-panel`. PHP broker namespace: `AzerioidPanel\Broker`. GitHub outward name: `azerioid/azerioid-stack-manager` (see A19 branding closure).

## A1 — Panel PHP isolation

**Status:** Accepted  
**Decision:** Panel PHP visible in Settings (runtime section) and Components (non-removable system card). Pinned to PHP 8.4. Broker refuses removal while panel depends on it. Dedicated FPM pool at `/run/php/azerioid-panel.sock`, user `caddy`.

## A2 — Panel database / legacy migration

**Status:** Accepted (revised 2026-09-07)  
**Decision (current):** Panel state is SQLite only (`/var/lib/azerioid-panel/panel.sqlite`). MariaDB/PostgreSQL **adopt** (bring an existing database server under panel management for site databases) remains supported.

**Removed (2026-09-07):** Automated migration from a prior `lacmp-panel`-era host is no longer supported. Removed from the tree:

- `deploy/migrate.sh` (MariaDB `lacmp_panel` → SQLite import)
- `deploy/relocate-from-lacmp.sh` (on-disk path relocation)
- `MariadbPanelImporter` / `panel:import-from-mariadb`
- Related seed/cleanup/smoke-p7 tests and README/SPEC upgrade docs

**Tradeoff:** There is **no** supported one-command path to migrate an old `lacmp-panel` host onto the current product. If a legacy host (e.g. `dream` or any other) still runs that software and needs upgrading, the operator must do a **fresh install** of AZERIOID Stack Manager and, if needed, manually export/import application data. Do not rediscover this as a missing feature without an explicit new ADR — removal was intentional.

**Historical note (no longer current):** The original A2 accepted “Adopt + `migrate.sh` for legacy installs” with P7 import tooling. That path is withdrawn.

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

**Out of scope / deliberately unchanged:** encrypted backup magic bytes (`LACMP1`/`LCMP1` — wire format for existing archives), and runtime temp/backup filename prefixes (`.lacmp-tmp`, `.lacmp-bak-*`) used by broker file ops. The relocate/migrate scripts themselves are **removed** (see A2).

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

## A21 — Automatic TLS for user vhosts

**Status:** Accepted (2026-09-07)  
**Decision:**

| Path | Mechanism |
|------|-----------|
| **Caddy + HTTP-01 (default)** | Native Caddy automatic HTTPS (no external ACME client). Bare site label in the generated Caddyfile. Non-public hostnames (IP, `.test`/`.local`/…) force `tls internal`. |
| **Apache / Nginx + HTTP-01** | `certbot certonly --webroot` into `/var/lib/azerioid-panel/acme-webroot`. Broker owns vhost files — **never** `certbot --apache` / `--nginx` installers. |
| **DNS-01 (all drivers)** | certbot DNS plugins via `registry/dns-providers/` (Cloudflare, DigitalOcean v1). Cert files wired as static `tls <cert> <key>` / `SSLCertificateFile` / `ssl_certificate`. |
| **Renewal** | Caddy renews its own certs; certbot.timer + deploy-hook `azerioid-reload.sh` reloads the active driver for certbot-managed certs. |

**Secrets:** DNS API tokens only via broker stdin → root-only `0600` files under `/etc/azerioid-panel/dns-credentials/`. Never argv, never logged.

**UI:** Vhost create/edit offer Automatic / DNS challenge / Self-signed. List TLS column shows issuer type + expiry (from live probe), not a boolean yes/http. Settings can rotate DNS provider credentials without re-displaying the secret. CLI mirrors the same TLS flags (`--tls=auto|dns|self`, env token for DNS-01).
