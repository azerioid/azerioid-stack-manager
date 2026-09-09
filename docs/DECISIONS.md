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
**Decision:** On EL with enforcing SELinux: `semanage port` for 3169 and backend 8081/8082 (`http_port_t`), fcontext for `/data/www`, `/var/lib/azerioid-panel`, and the panel web tree (content + rw storage/cache), `httpd_can_network_connect` (+ `httpd_unified` for FPM). Package: `policycoreutils-python-utils`. Never disable SELinux to make a step pass. Distro `php-fpm` (site `www` pool) stays `httpd_t`. Panel PHP-FPM is a dedicated `azerioid-panel-php-fpm` unit executing a `bin_t` copy of php-fpm (`PREFIX/sbin/php-fpm`) so the master is `unconfined_service_t` and the UI can sudo the broker. Caddy stays `httpd_t`; the `azerioid_panel_fpm` module allows `connectto` on the panel FPM unix socket. `httpd_t` cannot sudo (often dontaudit, empty stdout → “Broker returned non-JSON output”). Do not `SELinuxContext=` the distro `httpd_exec_t` binary — systemd fails with 203/EXEC.

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

**Status:** Accepted (revised — Caddy permanent front router)  
**Decision:** See `docs/port-ownership.md`. Single Caddy instance owns `:80`/`:443` forever and the panel snippet on `:3169`. Apache (`127.0.0.1:8081`) and Nginx (`127.0.0.1:8082`) are loopback-only backends selected per vhost. `web.release-site-ports` is deprecated (no-op). The earlier limitation (“no path to reclaim Caddy after releasing ports to Nginx”) no longer exists.

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
| **HTTP-01 (any engine)** | Native Caddy automatic HTTPS on the front-router site block. Apache/Nginx never terminate TLS for site vhosts. Non-public hostnames (IP, `.test`/`.local`/…) force `tls internal`. |
| **DNS-01 (any engine)** | certbot DNS plugins via `registry/dns-providers/` (Cloudflare, DigitalOcean v1). Cert files are always wired as static `tls <cert> <key>` on the **Caddy** block. |
| **Renewal** | Caddy renews its own HTTP-01 certs; certbot.timer + deploy-hook `azerioid-reload.sh` reloads Caddy for DNS-01 static certs. |

**Secrets:** DNS API tokens only via broker stdin → root-only `0600` files under `/etc/azerioid-panel/dns-credentials/`. Never argv, never logged.

**UI:** Vhost create/edit offer Automatic / DNS challenge / Self-signed, independent of Engine (Caddy / Apache / Nginx). List TLS column shows issuer type + expiry (from live probe). CLI mirrors the same TLS flags (`--tls=auto|dns|self`, env token for DNS-01) plus `--engine=caddy|apache|nginx`.

**Retired:** certbot HTTP-01 webroot on Apache/Nginx, and the panel-only Caddyfile produced by `SitePortReleaser` (`auto_https disable_redirects`). Caddy never releases `:80`/`:443`.

**Operator note:** CertProbe reads the origin cert via `127.0.0.1:443` + SNI. If the domain is orange-clouded at Cloudflare, browsers may see a Cloudflare edge cert while the panel correctly shows Let's Encrypt on origin. Grey-cloud (DNS only) for HTTP-01 troubleshooting, or use CF Full (strict) once origin LE is issued.

## A22 — Panel white-label domain (and :3169 Host isolation)

**Status:** Accepted (2026-09-08)  
**Decision:**

Caddy treats a **single site** on a listener as the default for **any** Host/SNI on that port. The panel's `https://<IP>:3169` block therefore used to serve the panel for unrelated names (e.g. `https://let.az:3169/`) whenever DNS pointed at the same box — usually as a blank page from `APP_URL`/asset mismatch.

**Fix (always, even with no custom domain):** when public IP access is enabled, add a less-specific catch-all on the same port:

```
https://:3169 {
    tls internal
    respond "Misdirected request." 421
}
```

`https://<IP>:3169` stays more specific. Tunnel `http://127.0.0.1:3169` (`bind 127.0.0.1`) is unchanged. Tunnel-only installs must **not** add a public catch-all (that would open `*:3169`).

**White-label:** optional hostname on **:443** (not :3169), same A21 TLS pipeline (`auto` HTTP-01 / `dns01` / `internal`). `APP_URL` becomes `https://<domain>` (no port). Settings, `azerioid panel domain set|show|clear`, and install `--domain=`.

**Lockout:** IP:3169 and the SSH tunnel **always** remain after a custom domain is set. Switching domains rewrites the snippet (old name no longer serves the panel; old cert left to expire). Refused if the hostname is already a **site** vhost.

**Apply:** validate → write snippet + `.env` `APP_URL` + `broker.json` `panel` → Caddy apply → reload panel PHP-FPM; rollback snippet/env/broker.json if Caddy rejects.

## A23 — Per-database remote access (engine differences)

**Status:** Accepted (2026-09-08 audit)  
**Decision:** Remote reachability is explicit per database, but **enforcement differs by engine**:

| Engine | Authz | Network |
|--------|-------|---------|
| MariaDB | `GRANT … TO user@host` (per database) | `bind-address` + tagged ufw/firewalld on 3306 |
| PostgreSQL | `pg_hba.conf` (per database) | `listen_addresses` + firewall on 5432 |
| MongoDB | **Instance-wide** firewall on 27017 only (no per-database bind) | `bindIp` + firewall; UI must show this caveat |

Opening remote access for **any** database on an engine may bind that engine publicly. The **union** of specific IPs (or fully open if any database is Global) is what the firewall allows. Closing the last remote database returns bind + firewall to localhost. Activating the firewall for this layer **always keeps 80/443 open**.

**CLI secrets:** DB passwords are generated and printed once — never argv. Global mode requires typed confirmation (`OPEN-GLOBAL` / `--confirm`).

## A24 — Real-IP trust boundary (front router)

**Status:** Accepted (2026-09-08)  
**Decision:** Caddy is the only public TLS terminator. `reverse_proxy` sets `X-Forwarded-For` / `X-Forwarded-Proto` / `X-Forwarded-Host` from the real client. Apache `mod_remoteip` and Nginx `real_ip` restore `REMOTE_ADDR`, trusting **only** `127.0.0.1`/`::1`. Never trust those headers from the public internet. Panel white-label on `:443` is a separate Caddy site and must not change site-vhost header handling.

## A25 — Per-vhost Unix identity (Terminal / Files / Supervisor)

**Status:** Accepted  
**Decision:** Terminal and File Manager drop to a dedicated `az-vh-*` user in group `azerioid-vhosts` (same identity for every engine: Caddy / Apache / Nginx). Supervisor programs run as `azerioid-supervised`, never root / `caddy` / `www-data`. Read-only and system vhosts (panel snippet, reverse-proxy) refuse Terminal, Files, and delete/edit. Uninstall `--full` / `--drop-db` must remove these identities; `/usr/local/bin/azerioid` is removed on every uninstall.


