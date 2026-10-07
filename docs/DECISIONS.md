# Architecture Decision Log

ADR-style record of locked decisions for AZERIOID Stack Manager.

## A19 — Clean history

**Status:** Accepted  
**Decision:** New repo with clean git history. Install paths: `/usr/local/lib/azerioid-panel`, `/etc/azerioid-panel`, `/var/lib/azerioid-panel`. PHP broker namespace: `AzerioidPanel\Broker`. GitHub outward name: `azerioid/azerioid-stack-manager` (see A19 branding closure).

## A1 — Panel PHP isolation

**Status:** Accepted  
**Decision:** Panel PHP visible in Settings (runtime section) and Components (non-removable system card). Pinned to PHP 8.4. Broker refuses removal while panel depends on it. Dedicated FPM pool at `/run/php/azerioid-panel.sock`, user `caddy`. **Pool user revised by A39 Part A:** `azerioid-panel`, on its own php-fpm master.

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

## A16 — Node scope (revised 2026-09-15)

**Status:** Accepted (revised 2026-09-15)  
**Decision (original):** v1 Node = runtime install only; no PM2.  
**Decision (revised 2026-09-15):** Node.js remains a **runtime-install-only** component at the system level (no npm global tooling installed by default). **PM2 is now offered as an opt-in per-vhost runtime mode** for Node-based vhosts, mirroring the A35 Octane pattern exactly: built on the existing Supervisor (A25) and proxy-vhost (Caddy `reverse_proxy`) infrastructure, never a parallel process-management system.

Rationale for revising the original “no PM2” scoping: PM2’s cluster-mode and zero-downtime-reload capabilities are genuinely useful for Node vhosts and map cleanly onto infrastructure already built for Octane — this is not scope creep, it is reusing a proven pattern for a parallel use case. The general-purpose Supervisor “Processes” page (arbitrary commands, not tied to a vhost) is unchanged — PM2 is specifically for vhost-bound Node web apps that want clustering / zero-downtime reload, not a replacement for ad-hoc process management.

See **A37** for the PM2 implementation constraints (pm2-runtime under Supervisor, port range, Node-app detection).

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
**Decision:** Terminal and File Manager drop to a dedicated `az-vh-*` user in group `azerioid-vhosts` (same identity for every engine: Caddy / Apache / Nginx). **Corrected by A49:** a shared group made every identity a group member on every other docroot; each identity now has a group of its own (see A25-E1). Supervisor programs run as `azerioid-supervised`, never root / `caddy` / `www-data`. Read-only and system vhosts (panel snippet, reverse-proxy) refuse Terminal, Files, and delete/edit. Uninstall `--full` / `--drop-db` must remove these identities; `/usr/local/bin/azerioid` is removed on every uninstall.

## A26 — PostgreSQL reinstall must not destroy leftover data dirs

**Status:** Accepted (2026-09-13)  
**Decision:** Component remove may leave the on-disk cluster. Reinstall must **never silently wipe or re-init** over existing data (same class of safety as vhost docroots and `/data/www`).

| Family | Typical data path | Reinstall behavior |
|--------|-------------------|--------------------|
| **EL** (Alma/Rocky/RHEL) | `/var/lib/pgsql/data` (`PG_VERSION`) | `post_install`: `test -f /var/lib/pgsql/data/PG_VERSION \|\| postgresql-setup --initdb` — skip-if-exists (landed in `f1e82f7`). Reuses leftover cluster; does not overwrite. |
| **apt** (Debian/Ubuntu) | `/var/lib/postgresql/<version>/main` | Cluster init is owned by `postgresql-common` package scripts; they also refuse to clobber an existing data directory. Panel does not run a destructive init step on apt. |

**Unacceptable:** bare `postgresql-setup --initdb` (or equivalent) that could recreate/wipe when leftover data is present. Loud failure refusing init is acceptable; silent overwrite is not.

**Evidence (Rocky 9, leftover `/var/lib/pgsql/data` after remove):** reinstall exited 0, `PG_VERSION`/`pg_hba.conf` hashes unchanged, marker file preserved, unit became `active`.

## A27 — CLI failure exit codes

**Status:** Accepted (2026-09-13)  
**Decision:** `azerioid` CLI failures must exit non-zero. Shared mapping in `CallsBroker::failBroker` / `throwBrokerFailure`:

| Exit | Meaning |
|------|---------|
| `0` | Success only |
| `2` | Validation / usage / policy (`Command::INVALID`; broker codes 2–3; CLI-local `RuntimeException`) |
| `1` | Broker / operational failure (`Command::FAILURE`) |

Broker JSON `code` must be preserved when rethrowing (do not wrap as bare `RuntimeException`, which defaults to code `0` and used to collapse distinct failures).

## A28 — CentOS Stream is first-class EL

**Status:** Accepted (2026-09-14)  
**Decision:** CentOS Stream 9+ is a supported EL-family member (`ID=centos` → `DISTRO_FAMILY=el`), same path as AlmaLinux/Rocky. Detection already listed `centos` in `deploy/lib/detect-os.sh`; fleet verification (SELinux Enforcing, full module chain) confirmed no allow-list gap. Document in README alongside Alma/Rocky — do not treat Stream like Fedora's deliberate refusal.

## A29 — Uninstall retains `/data/www` and `/var/log/azerioid-panel`

**Status:** Accepted (2026-09-14)  
**Decision:** `uninstall.sh --full` must **never** delete:

| Path | Why retain |
|------|------------|
| `/data/www` | Operator site trees / docroots (already established) |
| `/var/log/azerioid-panel` | Panel/broker/auth-fail/audit logs — post-uninstall incident investigation |

`--full` may truncate fail2ban's auth-fail jail feed and remove panel packages/config, but the log **directory** and historical files stay. Operators who want a wipe remove those paths manually.

## A30 — Deploy markers: TAG only when tagged

**Status:** Accepted (2026-09-14)  
**Decision:** Install / self-update markers under `PREFIX` (`/usr/local/lib/azerioid-panel`):

| File | When present |
|------|----------------|
| `COMMIT` | Always (40-char deploy hash) |
| `VERSION` | From tree `VERSION` file when present |
| `TAG` | **Only** when HEAD matches an exact semver tag `vX.Y.Z` (install) or after a successful tag-based panel update |

An install from an **untagged** `main` tip correctly writes `COMMIT`+`VERSION` and **omits** `TAG`. Missing `TAG` is not a bug — panel update check treats that tip as ahead-of-latest-tag / up-to-date per the semver channel (see also A27-era updater behavior). `PanelUpdater::writeDeployedMarkers` deletes a stale `TAG` when deploying an untagged commit.

## A31 — Component memory preflight counts RAM + free swap

**Status:** Accepted (2026-09-14; behavior since `783e369`)  
**Decision:** `ComponentPreflight` measures **MemAvailable + SwapFree** against registry `min_ram_mb` (e.g. 1024 for MariaDB/PostgreSQL/MongoDB). Hard-block only when that **combined** headroom is below the threshold. When physical `MemAvailable` alone is below the threshold but combined headroom is enough (typical 512 MB + 1 GB swap droplets), install **proceeds** with an explicit **warning** that the install may be slow under swap pressure — not a silent pass and not a hard fail. Message text must state what was measured (physical vs combined).

## A32 — Localhost XFF for proxy/Node is expected

**Status:** Accepted (2026-09-14)  
**Decision:** Under A24, Caddy sets `X-Forwarded-For` from the real TCP peer. A request made **from the box itself** to a `type=proxy` (or any) vhost correctly shows `XFF=127.0.0.1` — the peer really is loopback. That is expected, not a Real-IP bug. External clients still show their public address. No code change. Documented here and in `docs/port-ownership.md` so fleet tests do not re-open it.

## A33 — Do not install Caddy's local CA into the OS trust store

**Status:** Accepted (2026-09-14)  
**Decision:** `tls internal` certificates work without installing Caddy's local root into `/usr/local/share/ca-certificates` (or NSS/Java stores). That install attempt runs as the `caddy` user via `sudo tee`, fails (caddy is not in sudoers — by design), and produces recurring auth-fail noise while TLS still issues successfully. Panel/broker do not need OS-wide trust of that CA (browsers already warn on self-signed; broker admin API is localhost HTTP). **Fix:** set global Caddy option `skip_install_trust` in the managed Caddyfile — do **not** grant Caddy broader sudo just to silence logs.

## A34 — Laravel 13 / Livewire 4 upgrade path

**Status:** In progress (branch `upgrade/laravel-13-livewire-5`; 2026-09-14)  
**Decision:** Major-version upgrade is its own milestone (not a drive-by bump). Research finding: **Livewire 5 does not exist** on Packagist as of this work — the current Livewire major that supports Laravel 13 is **Livewire 4** (`^4.0`, proven at `v4.4.4`). ADR wording that said “Livewire 5” is corrected to **Laravel 13 + Livewire 4**. `#[Locked]` remains the property-protection mechanism in Livewire 4 (official security docs). Do **not** merge to `main` until full PHPUnit + Phase 2/3 security re-proof + one-host regression, then cross-OS.

## A35 — Laravel Octane (FrankenPHP) is a per-vhost opt-in, never the panel runtime

**Status:** Accepted (2026-09-14)  
**Decision:** Octane is offered as an **opt-in high-performance mode for a single site vhost**, never as a default and never for the panel's own runtime (the panel stays on its dedicated PHP-FPM pool + queue unit, per A17/A27). It reuses the two mechanisms the panel already owns rather than adding a parallel process manager:

- **Supervisor (A25)** runs `php artisan octane:start --server=frankenphp --host=127.0.0.1 --port=N --max-requests=M` as `azerioid-supervised`, in a panel-managed program named `octane-<domain-slug>`.
- **Caddy (A9)** stays the front door and `reverse_proxy`s to `127.0.0.1:N` with the same forwarding headers as a `type=proxy` vhost, so TLS, logs, and real-IP handling are unchanged.

The vhost **stays `type=php`** in the managed comment (`# azerioid-managed engine=caddy type=php php=8.4 root=… runtime=octane octane_port=N octane_max_requests=M`) so PHP version, docroot, file manager, and terminal keep working. `CaddyParser` must therefore not infer `type=proxy` from the `reverse_proxy` directive when `runtime=octane` is present.

Constraints:
- **Laravel only.** Enable requires an `artisan` entrypoint (in the docroot or its parent when the docroot is `…/public`) **and** `laravel/framework` either required in `composer.json` or installed in `vendor/`. Anything else is refused with an explicit message.
- **Caddy engine only.** Apache/Nginx backend-engine vhosts are refused; Octane needs the Caddy front door talking to loopback directly.
- **Supervisor component required**, and the worker must actually listen before Caddy is rewritten. Enable is transactional: if the worker never listens or Caddy validation fails, the program is removed and the vhost keeps serving through PHP-FPM.
- **Disable** rewrites Caddy back to `php_fastcgi` **first**, then stops and removes the program. **Deleting** the vhost removes the `octane-*` program automatically (operator-created processes still require `remove_supervisor_programs`).
- Deploys need an explicit **reload** (`artisan octane:reload`, falling back to a Supervisor restart) because workers keep constructors, static properties, and singletons between requests. The UI states this before enabling and links the Octane docs.

Port range `34000–34999` is reserved for these workers (see `docs/port-ownership.md`), allocated as the first unused, non-listening port. Default `--max-requests` is 500.

## A37 — PM2 is a per-vhost opt-in Node runtime (mirrors A35)

**Status:** Accepted (2026-09-15) — revises A16; implementation on `feature/pm2-vhost-runtime`.  
**Decision:** Offer **PM2 (Node cluster)** as an opt-in per-vhost runtime for Node apps, reusing Supervisor + Caddy exactly like Octane:

- **Supervisor** runs **`pm2-runtime`** (foreground binary designed for Docker/systemd/Supervisor — not the daemonizing `pm2` CLI) as `azerioid-supervised`, program `pm2-<domain-slug>`, with a per-vhost `PM2_HOME` under `/var/lib/azerioid-supervised/pm2/`.
- **Caddy** `reverse_proxy`s to `127.0.0.1:N` with the same forwarding headers as `type=proxy` / Octane. Managed comment keeps `type=proxy` (or converts from `static`) plus `runtime=pm2 pm2_port=N pm2_instances=M` so Files/Terminal keep the docroot.
- **Shared global `npm install -g pm2`** once per host (PM2 is the supervisor binary; app `node_modules` stay in the vhost). Requires the Node.js component.
- **Node apps only** — enable requires a detectable entry (`package.json` `scripts.start`, or `server.js` / `app.js` / `index.js` / operator-supplied entry). PHP-only / Laravel sites are refused (use Octane).
- **Caddy engine only**; Supervisor required; enable is transactional (worker must listen before Caddy rewrite).
- **Cluster instances** default to **1** (safe); operators raise the count for multi-core load balancing. Scale via `vhost.pm2.scale`.
- **Reload** uses `pm2 reload <name>` against the same `PM2_HOME` (zero-downtime rolling restart of cluster workers). Caveat differs from Octane: workers are fresh Node processes after reload (module cache cleared per worker); apps that hold connections or in-memory sessions across workers still need sticky sessions or external state — document that, do not copy Octane’s Laravel singleton warning verbatim.
- **Disable** restores the prior serving mode (static file_server or prior proxy upstream) recorded at enable time, then removes the Supervisor program.
- Port range **`36000–36999`** (see `docs/port-ownership.md`), distinct from Octane’s 34000–34999 and ttyd’s 35000–35999.

## A38 — Docker container as a per-vhost runtime (rootless only)

**Status:** Accepted (2026-09-15) — implementation on `feature/docker-vhost-runtime`.  
**Decision:** Docker is an **installable component** (official Docker CE packages, not a bootstrap dependency) and an **opt-in per-vhost runtime** — “the operator already has a Dockerfile / compose file / image and wants it bound to a domain.” It is **not** a Coolify-style git build/deploy platform and **not** a Portainer-style container-fleet UI (both explicitly out of scope for v1; same thin-slice discipline as A16 / A35 / A37).

### Privilege model (locked — research 2026-09-15)

**Rootless Docker is the only supported mode.** Do **not** offer membership in the `docker` group and do **not** leave the rootful `docker.service` / `docker.socket` enabled for panel-managed workloads. `docker` group access is a well-documented root-equivalent (bind-mount the host and rewrite `/etc/shadow`); that directly conflicts with A25’s least-privilege identity model.

**Why rootless is viable here (not a silent weaker default):**
- Docker Engine documents rootless since 20.10; current CE packages ship `docker-ce-rootless-extras` + `dockerd-rootless-setuptool.sh`. Daemon and containers run inside a user namespace; no `SETUID` bits except `newuidmap` / `newgidmap`.
- **Fleet prerequisites are automatable:** `uidmap`, ≥65 536 subuids/subgids for `azerioid-supervised`, `loginctl enable-linger`, cgroup v2 (Ubuntu 24.04 / Debian 12 / EL9 all ship cgroup2), `fuse-overlayfs` + `slirp4netns`/`pasta` for storage/networking when needed. Ubuntu 24.04’s `kernel.apparmor_restrict_unprivileged_userns=1` is handled by the **packaged** AppArmor profile for `/usr/bin/rootlesskit` when installing via `docker-ce-rootless-extras` (do **not** use the static `get.docker.com/rootless` path that needs a hand-rolled profile).
- **SELinux Enforcing (EL):** rootless + native `overlay2` is known-bad historically; CE disables that combo and falls back to `fuse-overlayfs` — acceptable, do not disable SELinux.
- Known rootless limits that **do not block** this thin slice: userspace networking (slirp4netns/pasta), no overlay networks, no AppArmor-in-container, no `--privileged` / host-root capability grants, TCP peer inside the container is NAT’d (Caddy still sets `X-Forwarded-*` per A24/A32). Privileged host ports (<1024) are irrelevant — we only publish **high loopback ports**.

**Identity layout (mirrors A25 / A35 / A37):** one rootless `dockerd` owned by **`azerioid-supervised`** (systemd **user** unit + linger — Docker docs forbid a system unit with `User=` for rootless). Per-vhost Supervisor programs call `docker` / `docker compose` against that user’s `DOCKER_HOST=unix:///run/user/<uid>/docker.sock`. Files/Terminal stay on the vhost’s `az-vh-*` identity against the **build-context / docroot on the host**, never inside the container filesystem. Cross-vhost isolation matches Octane/PM2: containers do not receive mounts of other vhosts’ trees; a compromised container cannot become host-root via the daemon. Per-`az-vh-*` dockerd instances are **deferred** (more subuid ranges + linger sprawl) — not required for v1 least-privilege vs the `docker`-group alternative.

**Explicitly rejected for v1:** rootful daemon + `docker` group for panel users; DIND requiring `--privileged`; any UI that manages unrelated host containers/images/volumes.

**Host-mount handling in compose mode:** an operator-supplied `docker-compose.yml` / `Dockerfile` can still request arbitrary host-path bind mounts (e.g. `-v /etc:/host-etc`). This is **not blocked outright** in v1. Rootless Docker's UID-mapping means any such mount is constrained to what the `azerioid-supervised` user itself can already access on the host — it cannot escalate to root or reach files that UID doesn't own/read, so the blast radius is bounded by the same limits proven in the adversarial test (fleet evidence: `/etc/shadow` via bind-mount → Permission denied; host writes land as the supervised UID, never root). Accepted tradeoff: an operator who supplies a malicious or careless compose file can still access/modify anything `azerioid-supervised` can reach (e.g. other Docker-runtime vhosts' own container data, if that UID has access) — this is a known, bounded risk, not equivalent to host compromise. Scanning/restricting compose file mounts is not implemented in v1; revisit if this proves insufficient in practice.

### Runtime pattern

- **Caddy** `reverse_proxy` → `127.0.0.1:N` with the same forwarding headers as proxy / Octane / PM2.
- Managed comment: `type=proxy root=… runtime=docker docker_port=N docker_internal_port=M` plus image or compose markers so Files/Terminal keep the host build context.
- **Inputs:** (a) pull a pre-built image + internal listen port, or (b) build/run from `Dockerfile` / `docker-compose.yml` the operator placed in the vhost docroot via File Manager.
- **Lifecycle** (start/stop/restart/rebuild/logs) via Supervisor + the rootless CLI; long builds use the existing queue/job path.
- **Disable:** stop/remove the vhost’s container(s), remove the Supervisor program, restore the prior serving mode recorded at enable (static / prior proxy / php) — same restore-meta pattern as PM2. Docker-only sites with no prior mode leave a clearly non-serving / restored static stub rather than inventing a parallel “disabled container” router.
- **Component uninstall:** refuse while any vhost still has `runtime=docker` (typed confirm / drop discipline analogous to Mail’s vhost-scoped tenancy).
- Port range **`37000–37999`** (see `docs/port-ownership.md`), distinct from Octane / ttyd / PM2.
- Install from **Docker’s official apt/dnf repos** (`docker-ce`, `docker-ce-cli`, `containerd.io`, `docker-buildx-plugin`, `docker-compose-plugin`, `docker-ce-rootless-extras`) — not distro `docker.io` as the primary path. After package install: **disable and mask** rootful `docker.service` / `docker.socket`, then configure rootless for `azerioid-supervised` only.

## A39 — Panel service identity must never be a site PHP-FPM pool user

**Status:** **Part B landed (hotfix, 2026-09-26). Part A landed in v2.0.0 (2026-09-27)** — see *Part A — as built* below.

**Problem (R1):** `deploy/install.sh` called `detect_web_user()` **before** `bootstrap_packages`
installed Caddy. `deploy/lib/common.sh` prefers the dedicated `caddy` identity only when that user
already exists, and on a stock Debian/Ubuntu image it does not — while the distro web user always does,
so that one won. The corrective re-detect was guarded by `if ! id -u "${WEB_USER}"`, which is never true
on apt, so it never self-corrected.

Consequence on apt installs: the panel FPM pool could end up running as the **same system user as the
distro site PHP-FPM pool**, while that identity also held the privileged broker grant. Sharing a service
identity between the control plane and hosted-tenant code is not an acceptable trust boundary — it
collapses the isolation the rest of this project's design depends on (**A25** per-vhost identities,
**A33** refusing to widen Caddy's privileges). EL hosts were already correct: the distro web user does
not exist on a bare EL image, so the re-detect fired there and selected the dedicated identity.

**Disclosure note:** the precise privilege chain and the exploitation preconditions are deliberately
**not** recorded here for now. They will be added to this ADR after a release carrying Part B is
published and operators have had a window to update. Operators should run
`azerioid panel harden status` — it reports whether a given host is affected and exits non-zero when
remediation is needed.

**Part B — landed now:**

1. Authoritative `detect_web_user()` moved **after** `bootstrap_packages`; the early call is explicitly
   provisional (dry-run display / prompts only). Re-detection is unconditional unless `--web-user=` was
   passed.
2. New `site_pool_user_conflict()` guard: the installer **refuses to proceed** if the resolved web user
   is also a site FPM pool user, rather than installing a root escalation.
3. New idempotent, transactional in-place remediation for already-installed hosts:
   `panel.harden.status` / `panel.harden.apply` broker actions and `azerioid panel harden status|apply`
   (`--dry-run`, `--lockdown-site-pools`). Migration order keeps a working broker path at every
   intermediate step — additive sudoers (both identities) → ownership → pool → units → **verify broker
   call as the new identity** → drop the old grant. Any failure reverts the whole journal.
4. Target identity is **`caddy`**, deliberately: it is what `detect_web_user()` already intends, what EL
   hosts already run, and what the fixed installer produces — so hardened apt hosts converge on one
   state instead of forming a third variant.

**Part A — NOT done here (next major). Operator decisions locked 2026-09-26:**

1. **Dedicated account (revises A1).** The panel pool user becomes a new, separate `azerioid-panel`
   system account — **not** `caddy`, **not** `www-data`. **A1**'s "Pool user: `caddy`" is hereby
   **revised**. Rationale (operator-agreed): **A33** already established the principle "do not grant
   Caddy broader sudo just to make the panel work"; leaving the panel pool on Caddy's uid while that uid
   holds the broker grant contradicts the spirit of that decision. A separate account isolates both
   sides. Part B's use of `caddy` is an explicitly temporary convergence step, not the end state.
2. **Independent panel PHP version.** With its own php-fpm master and its own `php.ini`, the panel's
   PHP pin (**A1**: 8.4) becomes genuinely independent of the distro php-fpm version on every family,
   not only EL. Accepted tradeoff: panel PHP upgrades become a separate operational step rather than
   arriving with a distro update — which is the point, because a distro PHP upgrade can then no longer
   break the panel unexpectedly. *(Refined by Amendment A39-A1 below: independent master, config and
   pin; the distro binary is shared on purpose, so patch releases still arrive with distro updates.)*
3. **Migration model: hybrid.** Part A's migration runs **automatically during self-update** (matching
   the "in place, at upgrade" decision), but with a **full rollback guarantee**: create the new identity
   → chown → write **additive** sudoers covering both old and new identity → switch the pool → verify a
   real broker call as the new identity → only on success remove the old sudoers entry. On any failure,
   revert every step and fail loudly. A host must never be left with neither identity authorised.

Mechanically Part A also generalises the EL-only dedicated panel php-fpm master
(`azerioid-panel-php-fpm.service` + `--fpm-config /etc/azerioid-panel/php-fpm.conf`, see **A3**) to all
families. That is what makes items 1 and 2 possible and what finally lets site pools keep
`proc_open` disabled.

**Known residual risk until Part A ships (do not treat as closed):**

- On apt hosts the panel pool still shares one php-fpm master — and therefore one `php.ini` — with the
  site `www` pool. `deploy/lib/fpm.sh` removes `proc_open`/`proc_get_status` from that shared `php.ini`
  so the panel can spawn `sudo`. Per-pool `disable_functions` can only **append** to the global list,
  never remove from it (stock `www.conf` comment states this), so site pools keep process-spawning
  unless the operator opts into `--lockdown-site-pools` (behaviour-changing for hosted apps).
  **This is no longer a root path** — the sudo grant is gone — but it is weaker than SPEC intends.
- `caddy` now holds the broker grant, so a Caddy compromise reaches the broker. Narrower than "any
  hosted site", but not the fully isolated identity Part A delivers.

(Both residual risks above are closed on a host once Part A has migrated it; `azerioid panel identity
status` exits non-zero until it has.)

**Part A — as built (v2.0.0, 2026-09-27):**

One implementation, `PanelIdentityMigrator` (broker), produces the end state; nothing else writes it.

| Aspect | Decision |
|--------|----------|
| Account | `azerioid-panel`, `useradd --system --user-group`, shell `nologin`, home `/var/lib/azerioid-panel` (not created). Runs the pool, the queue worker and the scheduler; the only sudoers entry. |
| Config | New broker.json key `panel_user` (`Config::$panelUser`), falling back to `web_user` on hosts that predate it. `web_user` keeps meaning the web server / site PHP identity. Panel-identity consumers (self-update, DB snapshot restore, scheduler cron, zip handover, panel `.env`, SFTP forbidden list) now read `panel_user`. |
| PHP-FPM | `azerioid-panel-php-fpm.service` on every family: `<php-fpm> --nodaemonize -c /etc/azerioid-panel/php.ini --fpm-config /etc/azerioid-panel/php-fpm.conf`, pool in `/etc/azerioid-panel/php-fpm.d/`. apt uses the distro binary (so it still gets security updates on restart); EL keeps its SELinux `bin_t` copy. No `PrivateTmp`: the broker runs from this unit's pool and must keep seeing the real `/tmp`, as it did via `systemd-run` on apt. |
| php.ini | Seeded from the distro FPM php.ini with only `proc_open`/`proc_get_status` removed from `disable_functions`, so every other setting is unchanged for the panel. The distro php.ini gets back the `disable_functions` line from `<ini>.azerioid-panel.bak` (the pre-install copy), if the installer had loosened it — **only for an FPM-only ini** (Debian/Ubuntu `/etc/php/X/fpm/php.ini`). On EL and Remi the same file also serves the PHP CLI, which runs the queue worker and scheduler and needs `proc_open` to reach the broker, so it is left as it is; on those families site pools keep process-spawning unless the operator locks them down per pool. The ini is chosen in the installer's `fpm_ini()` order, so both sides mean the same file. |
| Caddy access | Caddy is no longer the panel, so it gets exactly: its group on `web/` and `/var/lib/azerioid-panel` (mode `0750`), world-read on `web/public`, and the socket group. `.env`, `storage/`, `panel.sqlite` stay unreadable to it. `PanelFileAccess` is re-applied by self-update after its `chown -R`. `/run/php` goes back to `root:root` so the web user cannot replace the panel socket. |
| Order | account → **additive** sudoers (every current holder + new) → php.ini, master conf, pool → files → queue unit, cron, tmpfiles → unit → cut-over (apt: pool leaves the distro master, which is reloaded, then the new master starts) → broker.json/runtime.json → distro php.ini → queue restart → **verify** (unit active; socket `azerioid-panel:<caddy>`; `runuser -u azerioid-panel -- sudo -n broker version.all`; `GET /login` through Caddy returns 200/302; queue active; the PHP CLI can use `proc_open` as `azerioid-panel`) → sudoers `azerioid-panel` only. Any failure reverts every journalled step, restarts the previous master and queue, removes the account it created, and records the failure. |
| Trigger: existing hosts | The release that first ships this is deployed by the *previous* release's updater, which cannot call it. So the trigger is the panel scheduler: `azerioid:identity-converge` every 5 minutes asks the broker (`panel.identity.converge`), which, when due, starts the migration in its own transient unit (`azerioid-panel-identity.service`) — it restarts the panel FPM master and the queue worker, so it must not run inside either. It waits while any operation is queued/running (rows touched in the last 30 minutes, so a stranded row cannot block it forever) and while a `panel.update.apply` process exists. |
| Trigger: fresh install | `install.sh` installs as before (panel on the web user), then its last step runs the same migrator (`migrate_panel_identity`). While it runs it holds `/run/azerioid-panel-installing` (removed by an EXIT trap; `/run` clears on reboot), and the scheduled converge refuses while that exists — the scheduler cron line is written early in the install, and a converge mid-install would race the installer for the same files. A re-run over a migrated host keeps the dedicated layout (installer `PANEL_USER`) and the migrator is then a no-op. |
| Failure | "Fail loudly": the state file `/etc/azerioid-panel/panel-identity.json` (root-only, so the panel cannot clear it) records `failed` with the error and log; automatic retries stop; `azerioid panel identity status` exits non-zero and says so; an operator retries with `azerioid panel identity apply --confirm` (`--dry-run` shows the plan). An attempt that died without rolling back (`running` with nothing running) is reported as interrupted and also waits for an operator. The installer exits non-zero. |
| Downgrade | Self-update refuses to move a migrated host below `v2.0.0`: older updaters would hand the broker grant back to `web_user` while the pool still runs as `azerioid-panel`, leaving the panel with no broker. |
| Uninstall | Removes the unit, master config and php.ini. The account goes only with `--drop-db`, because it owns the retained database. |

**Amendment A39-A1 (2026-09-27) — the shared PHP binary is the final state.** Part A decision 2
asked for a panel PHP "genuinely independent of the distro php-fpm version". As built, the panel has its
own php-fpm master, its own `php-fpm.conf`, pool and `php.ini`, and its own version pin
(`panel_runtime.php_version`), but on apt hosts that master **deliberately runs the distro's
`php-fpm8.4` binary** rather than a vendored or separately packaged build (EL keeps its SELinux `bin_t`
copy for A3's reason, not for independence). This is accepted as the final state, not a partial
implementation:

- The goal of A39 is identity and privilege separation: a distinct system account, a distinct sudoers
  grant, a distinct pool, and a php.ini the site pools do not share. The master/config separation
  achieves all of it. A separate binary adds no isolation: privilege comes from the account the pool
  runs as and the grant that account holds, not from which executable file the master was started from.
- A vendored binary would trade automatic distro security patches for a manual patch burden the panel
  would have to carry for every PHP CVE. That cuts against this project's rule of not adding
  maintenance burden without a real security gain.
- Accepted consequence: a distro PHP 8.4.x update reaches the panel the next time its master restarts,
  like any other php-fpm service. A distro PHP *minor* change (8.4 → 8.5) is still a separate panel
  step, because the pin selects the `php-fpm8.4` binary by name.
- **Known gap, EL only:** the EL panel master runs from the `bin_t` copy that `deploy/lib/fpm.sh` makes
  once at install time (`PREFIX/sbin/php-fpm`). Nothing refreshes it (not self-update, not the A39
  migration), so on EL the panel's PHP does **not** pick up distro PHP patches until a reinstall. The
  reasoning above holds on apt only. **Fixed in v2.0.1 (was KI-3):** every self-update now compares
  the copy with the distro binary (`PanelFpmBinary`). When they differ it re-copies, relabels
  (`restorecon`), config-tests with the unit's own arguments and restarts the panel master, putting the
  previous binary back if either step fails. A failed refresh is logged and never fails the update.
  **Corrected in v2.0.2:** self-update alone was not enough. It runs the updater that is already
  installed, so the v2.0.0 → v2.0.1 update could not run the refresh it introduced (confirmed on Rocky 9:
  the copy stayed stale). The panel scheduler now also calls `panel.fpm.refresh` hourly. It holds off
  while operations, a self-update or an identity migration are running. That covers the first hop and a
  `dnf update` landing between two self-updates; the self-update step stays as a second path.

**Found while building this — Part B defect:** `PanelHardener::rewriteSchedulerCron()` used a pattern
whose `\s` crossed newlines. On the installer's cron file it rewrote a word of the header comment and
left the scheduler job running as the old user, so on a host hardened by `harden apply` the scheduler
may still run as `www-data` — which no longer holds the broker grant, so broker-backed scheduled tasks
fail. Fixed (line-wise, job lines only, shared `PanelSudoers::cronUser()`); the Part A migration rewrites
the job line correctly and so repairs such hosts as it migrates them.

## A43 — Panel self-update snapshots the panel database before migrating

**Status:** Accepted (2026-09-26).
**Amends:** the A30-era updater behaviour, which rolled code back but not schema.

**Problem:** `PanelUpdater::apply()` rollback re-checks-out the previous commit and
then re-runs `runMigrations()`. But `artisan migrate` is **forward-only**: it cannot
undo what the failed update already applied. So if a migration succeeded and a
later step failed, rollback left the panel on **old code against a new schema** —
the one combination nothing exercises. There was no `migrate:rollback` and no
pre-update snapshot.

**Decision:** the panel database is a single SQLite file, so snapshot it
immediately before migrations and restore that snapshot on the rollback path
instead of re-running migrations.

| Aspect | Decision |
|--------|----------|
| Mechanism | `sqlite3 .backup`, **not** a file copy |
| Location | `/var/lib/azerioid-panel/db-snapshots/<stamp>-<operation-id>.sqlite`, mode `0600` |
| Retention | Newest **5**, pruned after a successful update |
| Missing `sqlite3` | **Refuse the update.** Do not fall back to a copy |
| No database yet | Skip with a warning (fresh install mid-bootstrap) |
| Rollback without a snapshot | Proceed, but warn loudly that the schema could not be rolled back |

**Why `.backup` rather than `cp`:** the panel runs SQLite in WAL mode (a live host
has `panel.sqlite-wal` and `-shm`). SQLite documents that copying a database file
while it may be written to is unsafe; with WAL you would have to copy the `-wal`
too, and the pair can still be caught mid-checkpoint. **Measured caveat, recorded
honestly:** on fleet host testing, a naive `cp` of the live panel database *did*
produce a consistent, integrity-ok copy, and a deliberate concurrent-writer test
also failed to tear it. That is the point — it works until the one time it matters,
and a rollback point you cannot trust is worse than none. `.backup` is consistent
by construction, needs no reasoning about sidecar files, and costs nothing extra.

**Restore also deletes `-wal`/`-shm`:** they belong to the database being replaced,
and leaving them would let SQLite replay a newer log over the restored file,
silently undoing the rollback.

**Not addressed here:** a downgrade across releases still carries the A30-era
warning that newer migrations are not automatically reversed. The snapshot protects
the *failed-update* path, not an intentional downgrade to an older tag.

## A44 — Per-vhost state projection in the panel database

**Status:** Accepted (operator decision 2026-09-26, "Variant C").
**Relates to:** A9 (config files own serving), A35/A37/A38 (runtime markers in the
managed comment).

**Problem:** per-vhost state lives in the web-server config as
`# azerioid-managed key=value`, parsed by regex (`CaddyParser`). That line already
carries a dozen keys, is flat, supports no nesting, and cannot hold history — so
features needing structured per-vhost state (container env, per-vhost cron, Node
version, backup schedules, deployment history) had nowhere to put it. Each one that
tried invented its own JSON sidecar under `/var/lib/azerioid-panel`.

**Decision:** add a `vhosts` table to `panel.sqlite` as a **projection**, never a
source of truth.

| Aspect | Decision |
|--------|----------|
| Authority | **Config files stay authoritative** for how traffic is served. The broker keeps validating against them; a projection row must never influence a privileged decision |
| Reconcile trigger | **On change only**, not per render. Hooked once in `BrokerClient::call()` against an explicit list of mutating `vhost.*` actions, because a new call site that forgot to reconcile would show up only as silent drift |
| Drift detection | **Content hash.** `vhost.list` now reports `config_sha256` per vhost; a row storing a different hash means the file was edited outside the panel. Chosen over mtime, which false-positives on a bare `touch` |
| Full audit | `azerioid vhost reconcile` reports and exits non-zero on drift; `--repair` rebuilds; `--dry-run` changes nothing. **Reporting is the default** — a status command should not silently rewrite panel state |
| Live probe data | **Never stored.** `tls_status` (issuer, expiry, reachability) comes from `CertProbe` at read time and would be stale the moment it was written |
| Projection failure | **Never fails the operator's action.** Reconcile errors are logged and swallowed; the config files are authoritative and `--repair` can always rebuild |
| Empty listing | **Never treated as "all vhosts deleted".** An empty result is far more likely a broker failure, and acting on it would destroy state the panel cannot rebuild until the broker is healthy |

**Deliberately not included:** the typed child tables (`vhost_secrets`,
`vhost_cron_jobs`, `vhost_databases`, `operations`) that later phases need. This
lands the foundation only; each child table arrives with the feature that requires
it, so the schema does not grow ahead of real use.

**Tradeoff accepted:** because reconciliation is change-triggered, an out-of-band
edit is invisible until something asks. That is the cost of not re-parsing every
config file on every page load, and it is why drift reporting exists at all.

## A40 — File Manager archive operations: zip-slip exclusion partially lifted

**Status:** Accepted (operator decision, 2026-09-26). **Conditional — see the gate below.**
**Amends:** `docs/SPEC.md` §"Per-vhost Terminal and File Manager", which stated *"Archive extract is
**not** implemented (zip-slip scoped out of v1)."*

**What changes:** archive **extract** (ZIP / TAR / TAR.GZ) and archive **creation in place** become
in-scope for the File Manager. The original v1 exclusion was made because the containment primitives
needed to do extraction safely did not exist yet. They now do, and are tested:

- `VhostPath::lexicalJoin` rejects NUL, absolute paths, and `..` segments climbing above the vhost root.
- `realpath()` confinement of the target (or of the parent, on create) must stay under the vhost root.
- Rename/move already validates **both** source and destination.
- Symlinks resolving outside the root are rejected; the helper never creates symlinks.
- All file ops execute as the vhost's `az-vh-*` identity (**A25**) via a setuid-dropping helper, never
  from the panel FPM process.

**Note on scope:** archive **creation** was never covered by the zip-slip exclusion — creation carries
none of extraction's path risks. It is unblocked unconditionally. Only **extraction** is gated.

**Hard gate (operator condition — not optional):** extraction is not considered complete, and must not
ship, until a dedicated adversarial test suite passes covering **all** of:

| Case | Must |
|------|------|
| Entry with `../` escaping the root | reject |
| Entry with an absolute path | reject |
| Symlink entry pointing outside the root | reject |
| **Hardlink entry** | reject |
| Nested archive | not auto-extracted |
| Zip bomb | refuse via uncompressed-size **and** entry-count caps |
| Entry with setuid/setgid bits | reject |
| Device-node entry | reject |

**Additional constraints:** extraction runs as `az-vh-*`, never root; total uncompressed output is
capped; a conflict policy (skip / overwrite / rename) is explicit rather than implied.

**Do not** treat `SPEC.md`'s original exclusion as still binding for extraction — but equally, do not
treat extraction as done until the table above is green.

## A41 — Git deploy accepted (narrow); full CI/CD pipeline permanently rejected

**Status:** Accepted (operator decision, 2026-09-26). **This ADR exists specifically so the CI/CD
question is not reopened again.**

This supersedes the earlier informal planning decision that excluded git-based deployment wholesale.
Two clearly separated propositions were evaluated; they get opposite verdicts.

### REJECTED — permanently: full CI/CD platform ("Proposition A")

Build pipelines, build environments and matrices, artifact storage, build caching, secret injection into
builds, **inbound webhook receivers**, provider OAuth token management, concurrent build isolation.

**Rejected for good, not deferred.** Reasons:

1. It is a separate product domain, not an extension of anything this panel owns — the same reasoning
   that keeps Octane/PM2/Docker on Supervisor + Caddy instead of new process managers (**A35/A37/A38**).
2. A webhook receiver is a **new public inbound attack surface** on a product whose security posture is
   localhost-first with an SSH tunnel by default (`SPEC.md` §Security model). **R1/A39** — where a single
   identity mistake turned every hosted site into a root path — is a direct argument for adding fewer
   public entry points, not more.
3. It duplicates Caddy's existing front-router/TLS role at the edges.

**Do not reopen without a new ADR that explicitly supersedes this one.**

### ACCEPTED — narrow: git deploy ("Proposition B")

`git pull` plus one post-deploy command in an existing vhost docroot, then the runtime reload that
**already exists**. Locked parameters:

| Parameter | Decision |
|-----------|----------|
| Providers | **Provider-agnostic over SSH**. Panel-generated deploy key; operator registers the public half. **No OAuth, no provider APIs.** |
| Triggers | **Manual + schedule only. No webhooks.** (Webhooks are the line where B becomes A.) |
| Deploy strategy | **In-place `git pull` for v1.** No release-directory + symlink swap — that would change the docroot contract and collide with the SFTP chroot question. |
| Rollback | **Code only** (`git checkout <previous-sha>` + re-run). **Not** code+DB. |
| Identity | Runs as the vhost's `az-vh-*` user (**A25**) — **never** root, never `azerioid-supervised`. |
| Reload | Reuses existing `vhost.octane.reload` / `vhost.pm2.reload` / `vhost.docker.restart`. |
| History | Uses the generalised operations model, not a bespoke tracker. |

**Why B is consistent with the discipline:** it adds one broker action plus state and reuses per-vhost
identity, Supervisor-based runtimes, the existing reload actions, and the queue. No new public endpoint,
no new process manager, no build system.

**Known accepted limitation:** in-place `git pull` is **not atomic** — a partially-updated tree is served
mid-pull. This is accepted for v1 and must be documented in the UI, not silently ignored.

**Security note:** the post-deploy command is arbitrary code execution as the vhost user. That is
acceptable **only** because the vhost's own PHP already runs as that user, so the blast radius does not
widen. Deploy keys must be read-only at the provider and root-owned on disk (not readable by the vhost
user).

## A42 — `php-sodium` is a panel runtime dependency on EL

**Status:** Accepted (operator decision, 2026-09-26).
**Driver:** encrypted-backup KDF (Argon2id via `sodium_crypto_pwhash`) for the planned LACMP2 archive
format. Investigated before implementation, per the project's "verify across the whole OS matrix first"
discipline.

**Finding (measured, not assumed):**

| Family | PHP source | `sodium` | Evidence |
|--------|-----------|----------|----------|
| Debian / Ubuntu | Sury | **Statically compiled into the PHP binary** | No `sodium.so` in `extension_dir`; `apt-cache policy php8.4-sodium` returns nothing — **no such package exists**. `SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13` present. Verified on a fleet host (Ubuntu 24.04, PHP 8.4.25). |
| EL (Alma / Rocky / CentOS Stream) | Remi | **Separate subpackage, and it was NOT installed** | `php-sodium-8.4.25-1.module_php.8.4.el9.remi.x86_64.rpm` exists in Remi's `php84` module repo; neither `deploy/lib/packages.sh` nor `registry/components/php-8.4.json` listed it. |

**Decision:** add `php-sodium` to the EL panel-runtime package set — `deploy/lib/packages.sh` (dnf
bootstrap) and `registry/components/php-8.4.json` (`distros.el.packages`). It comes from the **already
configured, GPG-pinned Remi repo** (**A10**), so this adds no new repository or trust root.

**Scope:** panel runtime (PHP 8.4) only. Site PHP versions (`php-8.1/8.2/8.3`) are **not** changed —
backup crypto runs in the broker under the panel runtime, so they have no such requirement. Adding
sodium to site versions for hosted-app convenience is a separate question, not decided here.

**Important consequence — the KDF must still degrade gracefully:** adding a package to the install list
does **not** retroactively install it. Existing EL hosts will keep running without `sodium` until they
are reinstalled. Therefore the archive format **must**:

1. Keep **PBKDF2-SHA256** (`hash_pbkdf2`, PHP core, present everywhere) as a guaranteed floor.
2. Record the **KDF identifier and its parameters in the archive header**, so restore is unambiguous and
   never silently falls back to a weaker derivation for an archive that was written with a stronger one.

**Also established — AEAD has no sodium dependency.** AES-256-GCM is reachable through
`openssl_encrypt` on all five targets (hardware-accelerated on the verified host:
`sodium_crypto_aead_aes256gcm_is_available()` → true, and OpenSSL uses AES-NI likewise). Only the KDF
choice ever depended on `sodium`, so the encryption half of LACMP2 required no new dependency at all.

## A36 — Mail server component

**Status:** Implemented and released in **v1.3.0** (2026-09-15). Spec: [`docs/mail-server-design.md`](./mail-server-design.md).  
**Decision:** Ship an opt-in, registry-driven **Postfix + Dovecot + OpenDKIM** component (not Exim) for panel-managed domains, following the same install/managed pattern as MariaDB/PostgreSQL/Redis.

**v1 product (locked):**
- Vhost-scoped mailboxes/aliases (delete vhost refuses while mail exists unless `--drop-mail` / typed confirm); full **send + receive** (MX → Maildir) together.
- Explicit **mail hostname** panel setting (A22-style); TLS prefers existing Caddy/Let’s Encrypt material, else dedicated certbot cert.
- **Direct outbound MX and smarthost/relay are equally first-class.** Live outbound TCP/25 probe (same pattern proven on fleet droplet `64.226.78.176`, where :25 was open) drives mandatory plain-language UI/CLI status: “Direct mail delivery: available” vs “blocked by your provider — configure a relay…”, with a prominent Configure-relay CTA when blocked. DO and peers document default SMTP blocks on newer accounts — most operators will need relay; do not treat smarthost as v1.1.
- **Smarthost DKIM/DMARC (fleet-proven 2026-09-15, `let.az` → Gmail via Brevo):** Content-modifying relays commonly invalidate the panel’s local OpenDKIM body hash (`dkim=neutral` on the local selector). That is expected, not a signing defect. DMARC still passes when the relay’s own domain-authenticated DKIM aligns with `From:` (`d=<domain>`). In smarthost mode, relay domain authentication is the deliverability signal; UI must not imply local DKIM authenticates mail after smarthost is active. Direct mode still depends on local OpenDKIM end-to-end.
- CLI **`azerioid mail …`** ships in v1 with full UI parity (including status probe wording).
- EPEL accepted for OpenDKIM on EL; foreign MTA removal **always** requires typed `REPLACE-MTA` (no Debian-Exim exception); panel Laravel alerts stay on external SMTP unless Settings opt-in; hardcoded ~25 MiB max message, no quota UI.
- Explicitly out: webmail, spam-filter tuning UI, mailing lists (same scoping discipline as Terminal/File Manager/Node A16).

**Security posture (unique vs other components):** No open relay (`mynetworks` localhost-only; SASL only on submission 587/465, never AUTH on port 25); rate limits + fail2ban on auth ports; open-relay self-test before “healthy”; DNS record generation for SPF/DKIM/DMARC (operator publishes); reputation/blocklisting and provider SMTP filters do not map to Adminer or DB components.

**Spec:** [`docs/mail-server-design.md`](./mail-server-design.md) — all former open questions resolved; ready for implementation.



## A45 — What gets queued as an operation, and what stays inline

**Status:** Accepted (2026-09-26, B5).
**Relates to:** A43 (panel DB snapshots), A44 (`operations` named as a later child table),
request #13.

**Problem:** B5 added an `operations` table, a queued job and an Operations page, and the
commit that landed it listed the remaining synchronous call sites — backups, Octane/PM2/Docker
enables, mail domain enable — as "mechanical follow-ups". Working through them showed that
framing was wrong: several of those calls should *not* be queued at all, and converting them
because they were on a list would have made the panel worse.

**Decision — queue only work that routinely takes minutes and returns nothing the operator
is waiting to read.** The current set is exactly:

| Action | Why queued |
|--------|-----------|
| `vhost.docker.build` | Builds an image; pulls base layers |
| `vhost.docker.enable` (standalone) | Compose and Dockerfile modes build; image mode pulls layers that are validated against the registry but may not be on the host yet |
| `backup.restore.db` | Streams a whole archive through decryption into the database engine |
| `backup.restore.files` | Unpacks a site tree, then moves it into place |

**Deliberately left inline, with reasons:**

- **`vhost.octane.enable` / `vhost.pm2.enable` / `mail.domain.enable`.** A 900-second broker
  timeout is a ceiling, not a duration: these wait for a worker to listen and normally finish
  in seconds. They also *return something the operator needs now* — the port the runtime is
  serving from. Behind a queue boundary that answer arrives on a different page, later.
  (The Docker enable gives up the same answer, and is queued anyway: it is the one of the four
  that can spend minutes fetching or building an image before anything listens.)
- **`backup.db` / `backup.files` / `backup.caddy`.** Genuinely slow, but they already maintain
  a `BackupJob` row that both the Backups page and the backup-staleness alert rule read.
  Queueing them means either duplicating that bookkeeping or leaving `BackupJob` at `running`
  — which the alert rule would read as a missed backup. They wait for the BackupJob/operations
  unification rather than being half-converted.
- **The create-time Docker enable.** A35/A38 make enable transactional with the vhost's
  serving mode. Split across a queue boundary the vhost would be reported created while its
  runtime was still unresolved.
- **`backup.restore.files` with `apply=false`.** A preview only reads a listing, and the
  operator is sitting in front of it waiting for the listing.
- **`backup.verify`.** Has no panel call site at all; it is a CLI command whose whole purpose
  is printing a verdict to the person who typed it.

**Consequence that required new code — a queued destructive action must still refuse
immediately.** Two guards stand between an operator and a destroyed production site: *target
database already exists* and *that vhost is read-only*. Both used to be enforced inside the
restore, which was fine while the restore ran inside the request. Queued, a refusal would have
surfaced on the Operations page minutes later, after the operator had walked away believing
the guard let them through.

So the guards moved into `Backup\RestorePolicy`, and a read-only preflight action
`backup.restore.check` evaluates them before anything is queued. It fetches nothing, decrypts
nothing, needs no passphrase, and writes only its audit entry; `BackupRestore` still enforces
the same policy through the same class, so the preflight is advisory and nothing depends on it
having run. It is **audited**, because before this change a refused restore attempt was an
audited broker call and that trace should not be lost.

**Rule for the future:** a new slow action is added to `OperationDispatcher::ASYNC_ACTIONS`
deliberately, per call site. There is no blanket hook in `BrokerClient` — one would recurse
(the job calls the same action) and would silently change the contract of every existing
caller that uses the returned data.

## A46 — Firewall management: additive rules, broker-side guards, self-closing revert window

**Status:** Accepted (2026-09-26, B2).
**Relates to:** A1/G2 (firewalld reporting parity), A23 (per-DB remote access rules),
A36 (mail ports), A22 (panel port), request #2.

**Problem:** A1 fixed firewall *reporting* — the Security page had claimed no firewall
existed on EL hosts while the broker was writing firewalld rules through
`DbAccessFirewall`, `MailFirewall` and `SiteHttpFirewall`. It did not add
*management*: an operator could see rules and not change them, so every real firewall
change still happened over SSH, outside the panel, with no audit trail.

Managing a firewall from a web interface is the most lockout-prone feature in the
roadmap after SFTP. The dangerous change is not exotic — it is `deny 22/tcp` typed by
someone who meant to close something else, on a host whose only access is that port.

**Decisions:**

| Aspect | Decision |
|--------|----------|
| Rule model | **Additive**, never declarative. The panel adds and removes individual rules and leaves every other rule alone. A declarative "make the host match this list" model would delete the operator's own rules, the provider's, and any other tool's, the first time someone pressed Save |
| Expressiveness | Only the **intersection of both backends**: allow/deny, one port, optional single source. A model that can express everything either backend can do is a model whose two drivers cannot be kept equivalent, and B2 exists so a rule means the same thing on Debian and on Rocky. Anything more complex stays the operator's own, shown as unmanaged and never rewritten |
| Guards | **In the broker, not the UI.** The same action is reachable from the CLI, a second session, and anything that can call the broker. A hidden button is not a guard |
| Protected ports | SSH (**read from `sshd_config` and its drop-ins**, not assumed to be 22 — an operator who moved SSH to 2222 is exactly who a hardcoded 22 would lock out), the panel port, and 80/443. Denying them is refused, and so is *deleting the rule that allows them*, which closes the port just as effectively under default-deny |
| Deny with a source | **Also refused** on protected ports. "Deny SSH from that one address" reads as narrow, but the panel cannot know the operator is not behind it, and that is the case where the mistake cannot be undone |
| Hostname sources | **Refused.** They resolve at write time; a rule that silently means something else after a DNS change is not a rule an operator can reason about |
| Unsafe-but-legal changes | **Self-closing revert window.** The rule set is snapshotted, the change applied, and a one-shot systemd timer restores the snapshot unless the operator confirms. Verification is *the operator reaching the panel*, because a host cannot meaningfully test its own inbound reachability — it sees loopback and its own interfaces, not the path the operator's packets take |
| Why systemd, not PHP | The window must survive PHP-FPM restarting, the queue worker dying and the operator's session ending — all of which happen during exactly the network trouble it exists for. All five targets are systemd |
| No systemd-run | The change is **refused** unless the operator types `I-HAVE-CONSOLE-ACCESS`. Same for explicitly declining the window |
| Concurrency | **One unconfirmed change at a time.** A second window would snapshot the unconfirmed state as if it were known-good |
| Rule deletion on ufw | **By specification, never by the printed index.** `ufw delete 3` deletes whatever happens to be third when it runs — a race between listing rules and confirming a deletion, with a firewall rule as the prize |
| firewalld writes | Always `--permanent` **plus** `--reload`. A runtime-only rule works all week and vanishes during an unrelated restart |
| firewalld deny | `reject`, not `drop`. A refused connection fails fast and says so; a silent drop looks like a network fault |
| Ownership marking | ufw: its own **comment** field. firewalld: a **sidecar index**, because rich rules have nowhere to record a comment. Known cost: if the sidecar is lost, panel rules keep working but stop being recognised as panel-written — which is why the index is keyed by rule identity, so it can be rebuilt |
| Feature-owned rules | Rules written by another part of the panel (database remote access, mail, site serving) are **not editable here**. Deleting one leaves that feature believing it is still reachable, and it would write the rule back anyway |

**Deliberately not in this phase:** changing the default incoming policy, interface- and
zone-scoped rules, rate limiting, and IPv6-specific rules. Each is a separate lockout
surface and none is needed for "open a port for my app".

## A47 — Cron: structured jobs, running as the vhost identity

**Status:** Accepted (2026-09-26, B2).
**Relates to:** A25 (per-vhost `az-vh-*` identity), A9/A44 (config is truth, database is a
projection), A30 (marker discipline), request #4.

**Problem:** the panel's only cron feature was a textarea holding the whole root crontab.
Three defects, one shape:

1. **Everything ran as root**, including a site's own queue worker — a job whose command
   that site's code can often influence.
2. **Saving replaced the entire file.** Two operators editing at once silently lost one
   set of changes, and one stray keystroke could delete every job on the host.
3. There was **no way to disable one job, run one job, or see what a job printed.**

**Decisions:**

| Aspect | Decision |
|--------|----------|
| Run-as | A job belongs to a vhost and runs as **that vhost's identity** (`az-vh-*`). This is the security win of the phase: a privilege *reduction* for the common case. **Until A49 (v2.0.6) it reduced privilege relative to root only — a site's job could still read and write every other site; see A47-E1** |
| Root jobs | Still possible — host maintenance needs them — but require a typed `RUN-AS-ROOT` **every time, including on re-enable**. The asymmetry is deliberate |
| State | A **broker-owned file**, not the panel database, for the same reason vhosts use config files (A9, A44): the thing that runs must be the thing that is true. cron reads crontabs, so the crontab is rendered from state and the panel reads state back through the broker |
| Existing lines | **Preserved byte for byte**, outside a marked block (A30). A host that already had root cron jobs — a provider image's backup script, a certbot hook — keeps them. Not politeness: the difference between a feature and an outage |
| Truncated block | A block missing its end marker **does not** cause the rest of the crontab to be treated as panel content and deleted |
| No jobs | **No empty block** left behind for an operator to wonder about |
| Disabled jobs | Stay in the crontab, commented, so *disabled* and *deleted* are distinguishable on the host itself |
| `@reboot` | **Refused.** It is a startup hook, not a schedule; accepting one would give a site a way to run code on every boot that appears in no schedule anyone reviews |
| Commands | Pipes and redirection are fine (cron uses `sh`), but a **line break** (ends the crontab line and starts another) and **`%`** (cron's escape, which turns the rest of the line into stdin) are refused |
| Output | Captured through a generated wrapper, per job, with the exit code. cron's default is to mail output to a local mailbox nobody opens, which is why a failing cron job is normally discovered by its consequences |
| Log ownership | **One directory per identity, owned by that identity.** Found the hard way: a single root-owned directory makes every site job fail at its own redirection, before its command runs — a feature that looks like "cron is broken" |
| Log rotation | `copytruncate`, because rotation must not hand a site's log back to root; a lost line of job output is diagnostics, not an integrity control like the audit log. 14 days, `maxsize 20M` |
| Run now | Uses the **same wrapper and identity** as the schedule, so "it works when I run it" and "it works at 3am" are the same statement |
| Legacy actions | `cron.list` / `cron.set` are **kept**: released UI and CLI call them, and an operator's scripts may too. The Security page now points at the new page and says why |

**Alerting (added in the same phase, after the above was written):** `cron.failed`, on by
default, one incident **per job** so two failing jobs are two problems and fixing one
resolves one. It reads the **exit code** the wrapper recorded, not the job's output —
"did it work" is a status, and grepping output for the word *error* is how you get alerts
that fire on a log line mentioning errors. A job that has **never run** raises nothing: a
job added a minute ago has not run, and a `@monthly` job must not be reported as broken
for a month. Disabled jobs are skipped, because the operator turned it off and its last
failure is probably why.

**Deliberately not in this phase:** per-job schedules expressed in the panel's own
vocabulary ("every 5 minutes") on top of cron syntax, catch-up runs for a job missed while
the host was down, and alerting on a job that *should* have run and did not — which needs
the panel to evaluate cron expressions against wall-clock time, a different and much
easier thing to get subtly wrong than reading an exit code.

## A48 — SFTP posture: no chroot, keys only, drop-in configuration

**Status:** Accepted (operator decision 2026-09-26). Implementation pending — this records
the decisions before the code exists, because both are hard to reverse once operators
have credentials.
**Relates to:** A25 (per-vhost `az-vh-*` identity), B3 / request #11.

| Question | Decision | Why |
|----------|----------|-----|
| Confinement | **No chroot for v1** | `ChrootDirectory` requires the chroot root to be owned by root and not group-writable, which fights A25's ownership model; getting that wrong silently breaks login for one site while working for another. Confinement comes from the account's home and `internal-sftp`. **Wrong — see A48-E1: neither confines; until A49 (v2.0.6) an SFTP login could read and write every other site** |
| Authentication | **Key-only by default** | A password for SFTP is also an SSH **shell** credential on these accounts, since `az-vh-*` has `/bin/bash` for the Terminal feature. Keys keep the blast radius of a leaked credential to file transfer |
| Shell access | **Denied in the `Match` block**, explicitly | See above: the shell exists for Terminal, which the panel brokers. SFTP must not become a second, unbrokered way in |
| Configuration | **`sshd_config.d` drop-in only**, never edits to `sshd_config` | The distro owns that file; an upgrade that replaces it must not take the panel's changes with it, and the panel must never be the reason a host cannot be upgraded |
| Validation | **`sshd -t` before every reload**, and **reload, never restart** | A restart with a bad config drops existing sessions *and* fails to come back — locking the operator out of the machine entirely. A reload with a config that fails validation is never applied |
| Admin SSH | Any change that would affect the administrator's own access is **refused** | This is the single most lockout-prone change in the roadmap; a test asserting admin SSH survives is part of the definition of done |

**Amendment (2026-09-27), before implementation: the `Match` group must be a new one.**

The obvious choice was `Match Group azerioid-vhosts`, the group A25 already puts every vhost
identity in. Checked against the live host first, and it is wrong:

```
azerioid-vhosts:x:986:www-data,caddy,azerioid-supervised
```

`az-vh-*` accounts have that group as their **primary** group, so they do not appear in the member
list at all — while `www-data`, `caddy` and `azerioid-supervised` are **supplementary** members and
do. `Match Group azerioid-vhosts` therefore covers the panel's own pool user and the web user, and
would quietly apply `ForceCommand internal-sftp` plus a shell denial to accounts that have nothing
to do with SFTP. sshd would accept the configuration; the damage would only appear the next time
something used one of those accounts.

**Decision:** a dedicated group, `azerioid-sftp`, containing only the vhost identities an operator
has explicitly enabled. This also gives per-vhost enable/disable its natural implementation —
`gpasswd -a` / `gpasswd -d` on one group — instead of overloading the identity group A25 owns for a
different purpose.

**Also confirmed on the host:** `/etc/ssh/sshd_config` carries
`Include /etc/ssh/sshd_config.d/*.conf`, so the drop-in approach works there, alongside the
distro's own `50-cloud-init.conf` and `60-cloudimg-settings.conf`. The panel's file must sort
**after** those to win on `Match`-block ordering, and must never edit them.

**Build order for B3 (agreed):** file operations (copy, chmod presets, recursive search,
multi-upload) → ZIP download moved out of panel PHP into the broker (G12) → archive extract
behind the mandatory adversarial suite (A40) → SFTP last, on its own.
## A46-E1 — Erratum: the default site's `:443` catch-all never worked as released

**Status:** Erratum (2026-09-27). Amends the default-site decision (request #14, shipped v1.7.1),
fixed in **v1.8.1**.

**What was claimed:** that a hostless pair of blocks — `http://:80` and `https://:443` with
`tls internal` — answers any hostname no vhost claims, on both protocols. The v1.7.1 release
notes said so, and this document reasoned from **A22**: the `:3169` catch-all had closed the
same hole on the panel port, so the same shape was assumed to close it on the public ports.

**What was true:** only `:80` worked. On `:443`, an unmatched SNI failed the TLS handshake
outright — the exact symptom the feature existed to remove. It stayed that way through v1.7.1,
v1.7.2, v1.8.0 and was found only by running `curl -k -H "Host: nonexistent.invalid"` against a
live host on 2026-09-27.

**Why the A22 precedent did not transfer.** A22's catch-all serves a **known name**: the panel's
own address, for which a certificate exists. This one must answer an **arbitrary SNI**, for which
none does. Those are different problems, and the shared phrase "catch-all" hid that. A precedent
is only a precedent for the case it actually covered.

**Two distinct causes, either of which alone leaves the feature broken:**

1. A hostless `https://:443` block **provisions no certificate**. `tls internal` signs names Caddy
   knows about; it does not invent one. There was nothing to present.
2. Naming the block instead fixes the handshake and serves nothing: Caddy matches site blocks on
   the HTTP **`Host` header, not SNI**, so a visitor asking for an unknown name matches no site
   and receives Caddy's empty `200`.

**The corrected shape (v1.8.1) is three parts, each doing one job:** a hostless `https://:443`
block for routing; a named block under `.invalid` (RFC 2606 — unregistrable, so it can never
collide with a real site) whose only purpose is to make Caddy provision a certificate; and the
global `default_sni` pointing at that name. The global must live in the **main Caddyfile**,
because Caddyfile globals cannot appear in an imported file — which is the structural reason a
feature designed as "one more snippet in `conf.d`" could not have worked on `:443` at all.

**Rejected: on-demand TLS.** The page is a static neutral notice, identical for every hostname, so
one certificate serves all of them. On-demand issuance would let anyone trigger certificate
generation by requesting arbitrary names.

**Consequence for the snapshot/rollback rule:** because the global lives in a file every site on
the host shares, `apply()` and `disable()` snapshot **both** it and the snippet and restore both
on failure. Rolling back only the snippet would leave a `default_sni` pointing at a site that no
longer exists.

**Consequence for `vhost.list`:** the provisioning block is a real named site in `conf.d`, so it
appeared in the operator's vhost listing as a site they never created and nothing routes to. It is
now filtered out — listing it invites someone to tidy away the thing that makes unmatched HTTPS
work.

**The testing lesson, which is the part worth carrying forward.** `DefaultSiteTest` asserted the
generated snippet's *shape* and that Caddy accepted the config. Both broken forms were valid
configurations: `caddy adapt` accepted all three attempts, including the two that did not work.
Config validity cannot see this class of defect. A real handshake check now lives in
`deploy/verify-release.sh` — it asserts `200` **and** the page body, because `000` was the first
defect and `200` with an empty body was the second, so either assertion alone would have passed a
broken build. One of the original tests also encoded the *wrong* invariant
(`test_blocks_are_hostless_so_named_vhosts_still_win`), requiring the very shape that cannot hold
a certificate; the real invariant is that the catch-all must never match a name a vhost could
claim, which `.invalid` satisfies more strongly than hostlessness does.

This is the same seam as two other defects found the same week — a broker action registered but
unreachable, and a cron log directory whose permission chain the filesystem double treats as a
no-op. In each case two fakes agreed with each other and the host disagreed with both.

## A49 — Every vhost identity has a group of its own

**Status:** Accepted (operator decision 2026-09-28), shipped as the security release **v2.0.6**.
**Supersedes:** the group half of **A25**. **Corrects:** A25, A47, A48 (errata below).

**Problem.** Every `az-vh-*` identity was created with `useradd --gid azerioid-vhosts`, and every
docroot was `2770 az-vh-X:azerioid-vhosts`. The group bits exist so the web server and the site PHP
pool can read a site's files — but the identities were members of that same group, so each one was a
**group member on every other site's docroot**, with read and write. Anything that runs as a site's
identity could open every other site: its Terminal, its SFTP login, its cron jobs. Found 2026-09-28
while designing B4's Docker data directory, and reproduced on both OS families before the fix:

| Path (attacker `xvh-a`, victim `xvh-b`) | Ubuntu 24.04, v2.0.5 | Rocky 9 Enforcing, v2.0.4 |
|---|---|---|
| Terminal (`runuser -u <identity> -- bash`, what ttyd runs) | read + write | read + write |
| SFTP (real key login, `internal-sftp`) | read + write | read + write |
| Cron (job owned by `xvh-a`, run through the panel wrapper) | read + write | read + write |
| File Manager (panel API, `../` and a planted symlink) | refused by path containment | refused by path containment |

The model dates from the first Terminal commit (2026-09-02); every release up to v2.0.5 has it.

**Decision.**

| Aspect | Decision |
|--------|----------|
| Group | Each identity's primary group is a **group of its own**, named after it (`az-vh-X:az-vh-X`) |
| Docroot | `2770 az-vh-X:az-vh-X` — unchanged mode, own group |
| Readers | Web server user, site PHP pool users and `azerioid-supervised` are members of **every** vhost group; no identity is a member of any group but its own. They serve or run every site, so their reach does not change |
| Why not one shared reader group | Files a reader creates — PHP uploads, Laravel logs, Octane caches — carry the directory's group (setgid). With a shared reader group the identity would not be a member and could not read, rename or delete its own site's files. The vhost group has to contain both the identity and the readers. (Editing such a file in place still needs its group write bit, which PHP's default umask does not set — as before A49) |
| Why not ACLs | They depend on the `acl` package (absent on the Ubuntu test host) and on every tool preserving them; group ownership is what the tree already uses |
| New vhost | Group created, readers added, php-fpm/Apache/nginx reloaded (each spawns workers with `initgroups()`, so a graceful reload suffices). **Caddy is restarted 3 s later** from a transient unit: it runs as its own user, only a restart gives it a new group list, and restarting it inside the request that created the vhost would drop the panel's own response |
| Legacy identity touched by a request | Left as it is. Converting one identity inside a File Manager or Terminal request would skip the migration's verification and rollback |

**Migration** (`VhostIsolationMigrator`, the A39 standard):

1. **Additive.** Create every vhost group, add the readers, reload php-fpm/Apache/nginx, restart Caddy and
   every running supervised program. Readers now hold the legacy group *and* every new one, so no site
   loses its web server, pool or runtime while files move, however long that takes.
2. **Regroup.** Per identity: `find <root> -xdev -group azerioid-vhosts -exec chgrp -h az-vh-X {} +`
   (never follows a symlink a site planted, never walks into another filesystem), then
   `usermod -g az-vh-X`. Homes that are system directories (`/`, `/etc`, `/var`, …) stop the migration.
3. **Verify for real:** every identity is refused a read of every other docroot (world-readable
   docroots are noted and skipped — the group model neither grants nor removes that); every reader can
   open every docroot with a fresh group list; no site that answered 2xx/3xx before answers with an
   error after (retried while Caddy warms up).
4. **Commit:** end the processes each identity was already running (Terminal shells, SFTP sessions, a
   cron job mid-run) — they carry the old group list until they exit.
5. **Any failure** replays the journal backwards, refreshes the readers again and records the failure in
   `/etc/azerioid-panel/vhost-isolation.json` (root-only). Automatic retries stop until an operator runs
   `azerioid vhost isolation apply --confirm`.

**Trigger:** the scheduler, every five minutes, hands off to the transient unit
`azerioid-vhost-isolation.service` — the release that ships the migration is deployed by the previous
release's updater, which cannot call it (same reasoning as A39). `azerioid vhost isolation status` exits
non-zero until a host is isolated, so a fleet check can gate on it.

**What this does not close — site PHP.** The site PHP pool runs as one shared account: `www-data` on
apt, `apache` on EL. It is a reader, so it is a member of every vhost group, and one site's PHP code can
still read and write every other site's files — measured on both hosts before and after the fix. This
is not new and not caused by the group model: the pool must write every site (uploads, caches, SQLite),
and it is one process identity for all of them. Closing it means **one PHP-FPM pool per vhost running
as that vhost's identity**, which changes pool management, sockets, SELinux labelling and the Octane
hand-back. Recorded as the next step for isolation, not done here. A49 closes every path where the
panel *hands a person or a schedule* a site's identity: Terminal, SFTP, cron and the File Manager.
*Closed by A55 (v2.5.0): each PHP-FPM site now runs in a pool of its own as its identity.*

**Also not closed:** `azerioid-supervised` (Octane, PM2, Docker, custom programs) is one account for
every site, as A37/A38 already record.

### A25-E1 — Erratum: the per-vhost identity was not per-vhost for files

A25 gave each site "a dedicated `az-vh-*` user" and described it as isolation for Terminal and the File
Manager. The user was dedicated; the **group was shared**, and the docroots granted that group
everything. The identity separated who owned a file, not who could open it. The File Manager held only
because its path containment refuses anything outside the site before the kernel is asked.

### A47-E1 — Erratum: cron jobs could reach every site

A47 called running a site's job as its identity "a privilege *reduction*". Relative to root it was; but
through the shared group a site's job could read and write every other site, which a reader of A47
would not have expected. Fixed by A49 (v2.0.6).

### A48-E1 — Erratum: SFTP was not confined to the site

A48 said "confinement comes from the account's home and `internal-sftp`". Neither confines anything:
the home is only where a session starts, and `internal-sftp` without `ChrootDirectory` can open any
path the account's permissions allow. Through the shared group those included every other site, for
reading and writing. The claim was reasoned, not tested — the B3 acceptance criterion "confined to that
vhost's tree" was never run against a second site. A49 (v2.0.6) makes it true by permissions; chroot
remains out of scope for the reasons A48 gives.

### A49-E1 — Erratum: A49 closed the docroot, not the site, and left deleted sites open

**Status:** Erratum (2026-09-28), fixed in **v2.0.7**. Found while verifying v2.0.6 on the Ubuntu host.

**1. The site is more than the docroot.** A49 regrouped each identity's *home*, which is its docroot.
For a Laravel site the docroot is `<app>/public`; the application above it — `.env` with `APP_KEY`,
`storage/`, the SQLite database — is outside the home. On the test host that directory was
`www-data:azerioid-vhosts 775`, and after v2.0.6 a different site's identity still read
`octane-demo/.env`. Neither A25 nor A49 had ever looked above the docroot.

**2. Deleted sites were handed to strangers.** Deleting a vhost removes its identity but keeps its
files (deliberately). The files keep the numeric uid and gid, and `useradd --system` gives those
numbers to the next account created. On the test host fifteen leftover directories were owned by
unrelated accounts: other sites' identities read them, and one was `azerioid-adminer-tool:azerioid-adminer-tool 2770`,
writable by Adminer's PHP. Cron log directories of deleted identities had the same problem.

**Decision (v2.0.7):**

| Aspect | Decision |
|--------|----------|
| The unit of isolation | The site's **top directory**: the directory directly under www_root that holds the docroot (`/data/www/app.test` for `/data/www/app.test/public`), or the docroot itself when it lives elsewhere or when two sites share one directory |
| Top directory | Regrouped with the rest (`azerioid-vhosts` → own group), given the site's group if it has another one, and closed to everyone else (`o-rwx`). Only the top directory's mode changes: nothing beneath it is reachable once the gate is closed, whatever mode PHP gave a file, and the operator's file modes are left alone |
| Orphans | A directory under www_root that no vhost root, identity home, Supervisor program (the panel's or hand-written), systemd unit, web server config or crontab refers to, and that root does not own, is **quarantined**: `root:root 0700`, top directory only, so the contents are recoverable exactly as they were. Cron log directories of identities that no longer exist, likewise |
| On delete | `vhost.del` quarantines the site's top directory (unless another site or program uses it) and its cron log directory. Best effort: whatever it cannot close, the next converge run finds |
| Restarts | Only when groups were created or readers added. A run that only closes directories restarts nothing and ends no sessions |
| Verification | Added: each identity can still open its own top directory and docroot; each orphan is `0:0 700`; the cross-identity check targets top directories |

The same migrator and the same trigger as A49: hosts already on v2.0.6 converge again automatically.

**v2.0.8 correction.** v2.0.7 quarantined with `chmod 0700`. GNU `chmod` keeps a directory's setuid and
setgid bits when given a numeric mode, so a `2770` directory became `2700`, verification refused it and
the migration rolled back cleanly on the first host it ran on. The quarantine mode is now symbolic
(`u=rwx,go=,ug-s`), checked on Ubuntu and Rocky. A failure recorded by an older release no longer
blocks automatic convergence: a new release tries once on its own, and only a failure of the running
release waits for an operator.

## A50 — Docker workload depth: compose service, environment, data, restart policy, private registries

**Status:** Accepted (operator decisions 2026-09-28, roadmap Qrup 9), shipped in **v2.1.0** (B4 part 1).
**Amends:** A38 (per-vhost rootless Docker). **Relates to:** A21 (secrets as root-only files), A44
(config is truth), A47 (broker-owned state).

**Problems (roadmap G8, G7, #6):**

1. **G8 — the port went to the wrong service.** `firstComposeService()` scanned the compose file line by
   line and returned the first key under `services:`, so a file listing `db:` before `web:` published the
   site's port on the database. Found while fixing it: **compose mode had never started on a real host.**
   The ports override was written to `/var/lib/azerioid-panel/docker-meta` (root 0750), which
   `azerioid-supervised` — the account `docker compose` runs as — cannot enter (`Permission denied`,
   confirmed on the Ubuntu host). Every compose enable since A38 (v1.5.0) failed at build.
2. **G7 — image mode was stateless and unconfigurable:** `docker run --rm --name … -p … <image>` with no
   environment, no volumes, no restart choice. Any stateful image lost its data on every restart.
3. **#6 — private images could not be pulled** at all.

**Decisions:**

| Aspect | Decision |
|--------|----------|
| Service selection | Asked of **compose itself**: `docker compose config --services`, run as `azerioid-supervised` in the app directory. It resolves profiles, `include`, `extends` and interpolation, which no YAML parser of ours would. The roadmap proposed parsing panel-side with a YAML library; compose's own answer is strictly better and needs no parser anywhere. An explicit choice must be in the list; with no choice only a single-service file is accepted, otherwise enable is refused with the list |
| Where workload settings live | **Broker-owned files** under `/var/lib/azerioid-docker/<vhost>/`, not the panel database and not the managed comment. Supervisor restarts a container without asking the panel, so what it starts from must already be on the host (the A47 reasoning). The roadmap's `vhost_secrets` table would have been a second copy of the same secrets, with nothing to reconcile it against — so it is **not built**; the managed comment keeps only what serving needs |
| Layout | Directory `root:azerioid-supervised 0750`; `settings.json` root 0600 (service, restart, volumes, registry); `env` and `ports.yml` `root:azerioid-supervised 0640` — readable by the docker CLI, **not writable** by it, so a container with a bind mount cannot rewrite its own configuration |
| Environment | `KEY=VALUE` file; image mode passes `--env-file <path>`, compose mode writes `environment:` for the chosen service into the override (YAML double-quoted, `$` doubled so compose does not interpolate). Values never on a command line (`ps`), redacted from both audit logs (`env` key), dropped from the operations table, and the panel saves them with their own synchronous call so they never sit in a queued job's payload. Single-line values only: a line break would start another variable |
| Readability of `env` by `azerioid-supervised` | Accepted, inside A38's stated blast radius: `docker inspect` already shows every container's environment to that account. Encryption at rest on the host would need a key on the same host; it would be theatre |
| Data | **Bind mounts from inside the app directory** (operator decision: not named volumes), so the data is part of the site's files, its backups and its File Manager. `host` is relative (letters, digits, `.`, `_`, `-`; no `..`, no absolute path), `container` absolute; at most five; a missing host directory is created `azerioid-supervised:<vhost group> 2770`; a symlink that resolves outside the app is refused |
| `--rm` | **Kept** (operator decision). With data on bind mounts nothing of value lives in the container layer |
| Restart policy | `always` / `on-failure` / `never`, mapped to Supervisor's `autorestart=true` / `unexpected` / `false` — the container runs in the foreground under Supervisor, so Supervisor *is* the restart policy. Supervisor's program spec gained the three-valued setting |
| Registry credentials | **Named, host-wide**, referenced per vhost (operator decision). Stored like DNS tokens (A21): `/etc/azerioid-panel/docker-registries/<name>.json`, root 0600 in a 0700 directory, from broker stdin only. **Never** written to `azerioid-supervised`'s `~/.docker/config.json` (base64, readable by every Docker site's tooling, A38). A pull that needs one runs `docker login --password-stdin` into a throwaway `DOCKER_CONFIG` directory, does its work, logs out and deletes the directory — also when the login fails |
| Which registries | Standard v2 login: Docker Hub, GHCR, GitLab, Harbor and similar. **ECR refused** (`*.amazonaws.com`): its tokens expire every 12 hours and need the AWS API |
| Compose + private images | `docker compose pull --ignore-buildable` runs during enable/rebuild while the credential exists, because Supervisor's later `up` runs without it |
| Deleting a registry | Refused while any vhost refers to it |

**Surfaces:** `vhost.docker.services|settings|settings.set|env|env.set`, `docker.registry.list|set|delete`;
Vhosts page (enable panel and a *Container settings* panel for running containers, with registry
management); CLI `azerioid vhost docker services|settings|env|env-set` (`--service`, `--restart`,
`--volume host:container[:ro]`, `--registry`, `--env-file` — values never as arguments; `env` shows
names unless `--reveal`) and `azerioid docker registry list|add|del` (password from
`AZERIOID_REGISTRY_PASSWORD` or a prompt, never an argument).

**v2.1.1 addendum — the daemon's group list.** Verifying v2.1.0 on the Ubuntu host, a bind mount from a
new vhost failed with `mkdir …: permission denied`. The rootless daemon resolves bind mounts itself, as
`azerioid-supervised` with the group list it started with — and since A49 every vhost has its own group,
created after the daemon started. It inherits that list from the account's systemd `--user` manager,
so restarting `docker.service` alone changes nothing: `user@<uid>.service` restarts (measured: groups
`984 986` → every vhost group). This takes every container down, so before enable/build/settings the
broker checks whether the daemon holds the vhost's group and restarts it **only if not** — once per new
group. Supervisor usually restarts the Docker sites by itself as their `docker run` clients lose the
daemon; restarting them again raced the old container's `--rm` removal ("name already in use", FATAL —
seen on the test host). So after a daemon restart the broker waits, then starts only the Docker
programs that are still down, after removing a stale container of the same name. The A49 migration
restarts the daemon the same way instead of restarting Docker programs twice.

**v2.1.2 addendum — data directories are 2775.** A data directory the panel creates was `2770`; a
container's non-root process (nginx's worker, uid 101 inside the namespace, an unmapped subuid outside)
is "other" to it and got `403`. It is now `2775`. That opens nothing on the host: a bind mount starts
inside the site's top directory, which A49-E1 closes to everyone else — verified on the Ubuntu host,
another site's identity is still refused the file.

**v2.1.3 addendum — starting through a wrapper.** Supervisor restarts a `docker run --rm --name X`
before the daemon has finished removing the old X, and the new run fails ("name already in use",
`spawn error`) — seen on the Ubuntu host after a settings change, and latent in every restart of an
image-mode site since A38. Supervisor commands cannot chain (`;` is refused by the command validator),
so image and Dockerfile modes now start through `/usr/local/lib/azerioid-panel/sbin/azerioid-docker-run`
(root 0755): it checks its arguments (name `docker-*`, the rootless `DOCKER_HOST`, the docker binary),
runs `docker rm -f <name>`, then `exec`s the unchanged `docker run`. Compose mode needs nothing: `up`
recreates its own containers.

## A51 — Node.js majors side by side, chosen per PM2 vhost

**Status:** Accepted (operator decisions 2026-09-28: Approach A, versioned prefixes; 20, 22 and 24 all
offered), shipped in **v2.2.0** (B4 part 2, request #7). **Amends:** A16, A37.

**Problem:** one Node.js per host (NodeSource `nodejs`, `/usr/bin/node`). Two sites needing different
majors could not both run, and changing the version changed it under every PM2 site at once.

**Decisions:**

| Aspect | Decision |
|--------|----------|
| Where a major lives | `/opt/azerioid-node/<major>`, one registry component each (`nodejs-20`, `nodejs-22`, `nodejs-24`). NodeSource and distro packages cannot be co-installed — they all own `/usr/bin/node` — so these are the **official nodejs.org Linux tarballs** (x64 and arm64) |
| Trust | Version and SHA-256 **pinned in the registry entry** (the Adminer pattern): the anchor is this repository. The pins were taken from `SHASUMS256.txt` after `gpgv` verified its signature against the Node.js release keys (`nodejs/release-keys`), 2026-09-28. The installer accepts only `https://nodejs.org/dist/v<pinned>/…` for the host's architecture and refuses a checksum mismatch before unpacking. A Node security release is picked up by a panel release that moves the pin |
| Install | Download → verify → unpack into `.<major>.new` (`--no-same-owner`, `root:root`, `go-w`) → run `node --version` and require the pinned version → install `pm2@7` **into the prefix** → atomic rename over the old prefix. Nothing lands in `/usr/bin` |
| PM2 per major | `pm2-runtime` must run under the Node it manages, so each prefix has its own. The Supervisor command sets `PATH=<prefix>/bin:…` first: `pm2-runtime` and `npm` start with `#!/usr/bin/env node`, and without it they would run under the system Node |
| System Node | The NodeSource `nodejs` component stays, shown as **Node.js (system)**, and is the `system` choice. **PM2 vhosts enabled before v2.2.0 keep running on it; nothing is moved automatically** — moving a site to another major can break native modules, so it is only ever the operator's choice. (The roadmap spoke of migrating the host-wide component; converting running sites silently is exactly what A16/A37 refuse, so "migration" is: both kinds coexist and each site is moved by hand) |
| Default for a new PM2 vhost | `system` when installed (what PM2 always used), otherwise the newest installed major |
| Where the choice is stored | The broker-owned PM2 metadata (`/var/lib/azerioid-panel/pm2-meta/<vhost>.json`, key `node`), next to what PM2 enable already records — not the managed comment; the Supervisor command is what actually runs (the A50 reasoning) |
| Switching | `vhost.pm2.node`: rewrites the command, restarts, waits for the port; if it does not listen the error names the likely cause (native modules) and the way back. **`node_modules` are not rebuilt** (operator decision): the panel says `npm rebuild` may be needed |
| Removal | A major, or system Node, cannot be uninstalled while a PM2 vhost runs on it |
| Node 20 | Offered (operator decision) and labelled end-of-life (April 2026) |

**Surfaces:** Components (three new cards); Vhosts → PM2 enable gains a Node.js choice, and running PM2
vhosts get *Node.js version*; CLI `azerioid vhost pm2 enable --node=…`, `azerioid vhost pm2 node --node=…`.

## A52 — Backup depth: site bundles, per-target schedules and retention, restore verification

**Status:** Accepted (operator decisions 2026-09-28, roadmap Qrup 11: manifest + parts, age-based retention
with per-target override, verification in scope, no incremental/PITR, no restore into another domain),
shipped in **v2.3.0** (B6, requests #8 and #9). **Builds on:** A2 (LACMP2, streaming, engines, verify).

**Site bundles.** One site as a manifest plus independently restorable parts, each LACMP2:

| Part | Contents |
|------|----------|
| `files` | The site's **top directory** under www_root (the whole Laravel app, not only `public/` — the A49-E1 lesson), `node_modules` and `storage/logs` excluded |
| `config` | The vhost config file; Docker settings, environment and compose override (A50); PM2/Docker runtime metadata; the site's Supervisor programs; the site's cron jobs; its database list |
| `db-<engine>-<name>` | One dump per database the operator associated with the site |
| `manifest` | Parts with size and SHA-256, root, top, identity, runtime — encrypted like the rest |

Layout `vhost/<domain>/<stamp>/<part>.lacmp2.bin` locally and under `azerioid/` in Spaces. A bundle is
complete or absent: a failing part deletes what was written. The count-based `backup.prune` and
`backup.list` now skip `vhost/` — counting a bundle's parts one by one would delete half a bundle.

**Deliberately not in a bundle:** TLS private keys — Caddy re-issues HTTP-01 certificates by itself and
the panel re-issues DNS-01 ones (B1), so a restored key is a liability rather than a convenience, and
certbot's symlinked layout does not survive a copy; and mail — Maildirs have their own lifecycle (A36).

**The vhost↔database association** did not exist anywhere (`db.list` and `vhost.list` are unrelated).
It is a broker-owned file per site (`/var/lib/azerioid-panel/vhost-bundles/`), for the same reason as A50:
the bundle is made by the broker, and a scheduled run must not depend on the panel database. It travels
inside the `config` part, so a restore brings it back. The roadmap's `vhost_databases` table is not built.

**Restore** (same domain only; typed domain confirm): preview reads only the manifest. Apply, in order:
`files` through the existing file restore (live tree moved aside as a snapshot, A2 hardened `tar`);
`config` — the vhost config is written and the web server reloaded through `CaddyApply`, which validates
first; if it is refused the previous config is put back — then the site identity (A49), Docker settings,
runtime metadata, Supervisor programs (created or updated, restarted), cron jobs not already present, the
database list; `db-*` through the existing database restore, overwriting an existing database only with
`OVERWRITE`. Any subset of parts can be restored.

**Schedules:** a `backup_schedules` table (target type, target, engine, destination, daily/weekly, hour,
weekday, retention days, enabled, last run) next to the existing global schedule, which is unchanged
(its once-a-day check now ignores scheduled targets' jobs). After each successful run the target's own
retention applies: `backup.prune.age` removes archives (or whole bundles) older than the schedule's days,
or the global default `backup.retention_days` (30), **always keeping the newest copy of each target**,
however old — a site that stopped being backed up keeps its last good copy.

**Restore verification** (`backup.verify` with `deep`): restores a database dump into a scratch database
`azv_verify_<random>`, counts its tables, and drops it — in a `finally`, so a failed restore drops it too.
Guard rails, each because the alternative writes to a live database:

- whole-server dumps (`mysqldump --all-databases`, `pg_dumpall`) are **skipped** — they carry their own
  `CREATE DATABASE`/`\connect` and would restore over the databases they came from, whatever target is named;
- a dump that names a database (`USE`, `CREATE/DROP DATABASE`, `\connect`) is refused;
- only authenticated (LACMP2) archives — an unauthenticated one could have been rewritten to do the above;
- MongoDB uses `mongorestore --dryRun` (parses the whole archive, writes nothing) instead of a scratch
  restore, which would need namespace remapping and so verify something other than the real restore.

**Surfaces:** Backups page — *Site bundles* (databases of a site, back up now, bundle list, restore with
part selection) and *Schedules per target* (with the default retention). Bundles, bundle restores and
panel-started verifications are queued operations. CLI `azerioid backup bundle|bundles|bundle-restore|
bundle-dbs`, `azerioid backup verify --deep`. Queued bundle work has the queue's 30-minute ceiling;
scheduled bundles run from cron with an hour.

**v2.3.1 addendum — site file restore had never worked.** The first real bundle restore on the Ubuntu host
failed with `Archive rejected: setgid entry (…/public/)`. `ArchiveGuard` refused every setgid entry, and
every directory of every panel docroot has been `2770` — setgid — since the first Terminal commit
(2026-09-02). So `backup.restore.files` could not restore a panel-managed site at all; no test built an
archive the way a real docroot looks. setgid is now refused on files only (a privilege grant) and
accepted on directories (group inheritance). Two more defects in the same path, fixed with it: tar runs as
root with `--no-same-owner`, so a restored tree was root's — the site's identity could not write its own
files, and a `0755` top directory reopened the site to every other one; the tree now takes the owner of
the live tree it replaces (`chown -R --reference`) and the top directory its mode. And a vhost created
with a nested docroot (`…/app/public`) left `…/app` root's `0755` until the isolation converge closed
it; `VhostUser::ensure` now gives a root-owned top directory to the site's identity (`2770`).

**v2.3.2 addendum.** The disaster test on the Ubuntu host (vhost deleted, site restored from its bundle
alone) brought the site, its identity and its database back, but restoring the cron jobs failed on a
constructor called with its arguments swapped — no test restored a config part with jobs in it; one does
now. And a bundle's database part (`vhost/<domain>/<stamp>/db-<engine>-<name>`) was not recognised as a
database by `backup.verify`, so `--deep` silently checked integrity only; bundle parts now carry their
kind, database name and engine.

**v2.3.3 addendum.** `verify-release` on the Ubuntu host after the disaster test: the restored site's
identity existed again, but its cron log directory was still quarantined `root 0700` from the delete — its
jobs' output redirection would fail and they would never run. `VhostUser::ensure` now hands an existing
`/var/log/azerioid-cron/<identity>` back to the identity (`0750`), and the cron manager sets the mode as
well as the owner whenever it prepares a directory.

## A53 — Git deploy, as built

**Status:** Accepted, shipped in **v2.4.0** (B8, request #12). **Implements:** A41 (Proposition B only;
provider-agnostic SSH; manual and schedule, no webhooks; in place; code-only rollback; the site's
identity, never root or `azerioid-supervised`).

**The key/identity split.** A41 wants the deploy key unreadable by the site's identity *and* the pull
run as that identity. Both hold because the work is split at a local mirror:

1. root fetches the branch into a bare mirror, `/var/lib/azerioid-deploy/<site>/mirror.git`, with the key
   (`ssh -i … -o IdentitiesOnly=yes -o BatchMode=yes`, host keys trusted on first use into the site's own
   `known_hosts`);
2. the mirror is made `root:<site group>`, group-readable, so the identity can read it and not change it;
3. the identity fetches from that local path and checks out the commit in the site's **top directory**
   (the whole app, not `public/`), `umask 007` so what the site's PHP must write stays group-writable and
   nothing is opened to other accounts; `safe.directory` for the root-owned mirror;
4. the post-deploy command runs as the identity, in the same directory, with `php`/`composer` bound to the
   site's own PHP version;
5. the runtime reloads through the existing Octane/PM2 reload or Docker restart; PHP-FPM needs nothing.

The key directory is `root:<site group> 0750` with the key `0600` root; `/var/lib/azerioid-deploy` is `0711`.

| Aspect | Decision |
|--------|----------|
| Repository URL | `git@host:path`, `ssh://user@host[:port]/path`, or public `https://`; never credentials in a URL, never `file://`, `ext::`, a local path, an option (`--upload-pack=…`) or `..` |
| Post-deploy command | Presets: none, `composer install --no-dev`, Laravel (composer, `migrate --force`, `optimize`), `npm ci && npm run build`. A custom command needs the typed `RUN-AS-SITE`: it is arbitrary code, acceptable only because it runs as the site (A41) |
| Schedule | off / hourly / daily at an hour, evaluated by the panel scheduler; a scheduled deploy of the commit already deployed does nothing |
| History | The last 20 deploys (commit, time, trigger, status, error) in the site's `state.json`; deploys and rollbacks are queued operations with their log on the Operations page |
| Failure | Recorded, reported with "roll back or deploy again"; the checked-out files stay (in place, A41), `current` does not move |
| Rollback | The previous deployed commit, checked out the same way, the command re-run. Code only: migrations are not undone |
| Delete | Deleting the vhost removes its deploy settings, key and mirror |

**v2.4.1 addendum.** The first real deploy on the Ubuntu host failed at the checkout: `git -c
safe.directory=<mirror> fetch <mirror>` as the site's identity still hit "dubious ownership", because for
a local path git removes `-c` settings from upload-pack's environment. The setting now goes on
upload-pack's own command line (`--upload-pack='git -c safe.directory=… upload-pack'`). Making the mirror
the site's instead was rejected: a site could then plant hooks or config in it that root would run on
the next fetch. The failure message now says whether the files were changed at all.

## A54 — Elasticsearch: single node, loopback, password, and a hard physical-RAM floor

**Status:** Accepted (operator decisions 2026-09-26/28: Elasticsearch under SSPL, single node, host-wide;
hard RAM block with swap not counted; minimum **2 GB**; security on; heap half the RAM), B7 / request #1.
**Exception to:** A31 (combined MemAvailable + swap headroom).

| Aspect | Decision |
|--------|----------|
| Source | Elastic's own repository, **9.x**, signing key refused unless its fingerprint is `46095ACC8548582C1A2699A9D27D666CD88E42B4` (checked with `gpg --show-keys` before the key is trusted; A10 pattern) |
| Licence | SSPL, stated in the registry description as for MongoDB |
| Preflight | New registry field `preflight.min_physical_ram_mb` (2048): **MemTotal**, not MemAvailable, and swap not counted — a JVM heap under swap pressure is killed, not slowed. The message states what was measured. This is the documented exception to A31, which stays the rule for everything else |
| Binding | `network.host`, `http.host`, `transport.host` 127.0.0.1; `http.port` 9200; `discovery.type: single-node` |
| Security | On, with a generated password for `elastic` (`elasticsearch-reset-password -b -s`), stored root-only (`/etc/azerioid-panel/elasticsearch.json`, 0600), passed to curl on stdin (`-K -`), shown to the operator only when (re)set. Loopback alone is not a boundary: every site on the host can reach 127.0.0.1 (R1) |
| HTTP TLS | Off: the traffic never leaves the host, and applications then need no CA file. Transport TLS as the package configured it |
| Config | `elasticsearch.yml` rewritten line by line: the package's security auto-configuration adds `cluster.initial_master_nodes` (refused alongside `discovery.type: single-node`), `http.host: 0.0.0.0` and an `xpack.security.http.ssl:` block — these and any other occurrence of a setting the panel owns are removed, then one managed block is appended. Idempotent |
| Heap | `jvm.options.d/azerioid-heap.options`: half the physical RAM, at least 512 MB, at most 31 GB (compressed pointers) |
| Surfaces | Components card; **Search** page (cluster health, heap, shards, indices, set a new password — shown once); `azerioid search status|indices|password-reset`. No index editor, query console or snapshots (out of scope) |
| Uninstall | Packages removed, unit disabled, stored credential deleted; data in `/var/lib/elasticsearch` kept (A29) |

**Verification status:** the preflight refusal is verified on both test hosts (961 MB and 453 MB). A
full install needs a host with ≥2 GB, requested from the operator; B7 is not released until that
passes.

**Addendum (v2.8.0) — lab override.** The operator's test host is a 1 GB droplet ("it is a lab server"),
which reports about 765 MB MemTotal. The 2 GB floor stays the default and still refuses it. A test host may
override it explicitly: install options `low_memory=true` and the typed confirm `LOW-MEMORY-LAB`
(`azerioid component install elasticsearch --option low_memory=true --option confirm=LOW-MEMORY-LAB`), down
to a second registry floor, `preflight.lab_min_physical_ram_mb` (700). The preflight then warns loudly
instead of refusing, and below 2 GB the heap is fixed at **256 MB** instead of half the RAM — half of 765 MB
would leave the panel, PHP and the web server nothing. Not for production: under load the kernel will kill
the JVM. The override is recorded in the managed manifest's install options.

## A55 — One PHP-FPM pool per site, running as the site

**Status:** Accepted, shipped in **v2.5.0**. **Closes:** the site PHP residual A49 recorded. **Operator
decisions (2026-09-28):** migrate every existing site automatically, A49-style, with rollback;
`pm = ondemand`; `open_basedir` on by default, switchable per site; `disable_functions` left at distro
defaults.

**Problem.** Every site's PHP ran in the distro's shared `www` pool as one account (`www-data` on apt,
`apache` on EL). That account had to write every site (uploads, caches, SQLite), so it was a member of
every vhost group, and any site's PHP code could read and change every other site — including its `.env`.
A49 closed Terminal, SFTP, cron and the File Manager; the web request path stayed open.

**Decision.** Each PHP-FPM site gets a pool `azv-<site>` on the distro master of its PHP version
(`/etc/php/<v>/fpm/pool.d/` on apt, `/etc/php-fpm.d/` on EL, Remi's directory for SCL versions):

| Aspect | Decision |
|--------|----------|
| Process identity | `user`/`group` = the site's identity and group (A49). The site's PHP can reach exactly what its Terminal can |
| Socket | `<run dir>/azv-<site>-<version>.sock`, `listen.owner` = web user, `listen.group` = the site group, `0660`. The web server (and a backend Apache/Nginx, a reader) connects; another site's identity cannot. The version is in the name, so during a version change the old pool keeps serving until the web server has moved |
| Process manager | `ondemand`, at most 5 children, 10 s idle timeout, 500 requests per child: an idle site costs nothing on a 1 GB host |
| Sessions | `/var/lib/azerioid-php-sessions/<site>`, `0700` identity-owned (the parent `0711`), labelled `httpd_sys_rw_content_t` on SELinux hosts. Moving a site logs its users out once |
| `open_basedir` | The site's top directory, `/tmp`, its session directory, `/usr/share/php`, `/usr/share/pear`. On by default; `azerioid vhost php-pool set --domain= --open-basedir=off` or the vhost editor turns it off for applications that need other paths. It is defence in depth — the account boundary is what isolates |
| `disable_functions` | Distro default. PHP runs as the site, so `exec()` grants nothing the site's Terminal does not |
| Timeouts | The site PHP limits of `SitePhpTimeouts` (`request_terminate_timeout`, `max_execution_time`) are written into every site pool |

**Safety of a pool change.** One bad pool file stops the master every other site on that PHP version runs
in. So a pool is written, `php-fpm<v> -t` is run, and a refused pool is taken out *before* any reload; a
reload is graceful (`systemctl reload`, restart only if reload fails), and a pool whose socket does not
appear is taken out again. The pool is created while the vhost is rendered, so the driver's own
validate-and-restore covers the web server side; the identity is created first when a new site needs it.
A site whose identity is still on the pre-A49 shared group, or that the migration put back, is rendered
against the shared pool.

**Files.** The first pool for a site runs `chown -R -h <identity>:<site group>` and `chmod -R g+rwX` on
the site's top directory: what the shared account wrote (uploads, caches) becomes the site's, and group
write stays, so the shared pool could still serve the site if it has to go back. Octane disable hands the
Laravel writable directories to the identity, not to the shared account.

**Migration.** `vhost.phppool.converge`, started by the scheduler every five minutes in a transient unit
(`azerioid-site-php-pools.service`), moves pending sites one at a time: HTTP probe → re-render the vhost
(pool, files, web server) → probe again (three retries). A site that answered below 500 before and 0 or
5xx after is put back on the shared pool at once, marked with the reason, its pool removed; the other
sites carry on. A put-back site is not retried automatically; the operator fixes it and runs
`azerioid vhost php-pool apply --confirm` (typed `ISOLATE-PHP` at the broker, optionally `--domain=`).
State: `/etc/azerioid-panel/site-php-pools.json` (root, `0600`); per-site switches:
`/var/lib/azerioid-panel/site-php/<site>.json`. `azerioid vhost php-pool status` is non-zero until every
site has its pool, and `verify-release.sh` checks the status and that every `azv-*` pool runs as an
`az-vh-*` account.

**Lifecycle.** A version edit prunes the site's other pools after the web server has moved; deleting a
site removes its pools, sessions and settings *before* its account (php-fpm will not reload a pool whose
user is gone); the converge also takes out pools no site uses. `uninstall.sh --drop-db` removes every site
pool before deleting the identities.

**Not changed / residuals.**
- The distro `www` pool stays, idle; readers (`www-data`, `apache`, …) stay in every vhost group because
  the web servers run as them. No site code runs as them any more.
- `azerioid-supervised` (Octane, PM2, Docker, custom programs) is still one account for every site and a
  member of every group (A37/A38/A49); as such it can also connect to any site's PHP socket.
- Adminer keeps its own pool.

## A56 — Site programs run as their site; Docker gets a daemon per site

**Status:** Part 1 (Octane, PM2, operator programs) shipped in **v2.6.0**; part 2 (a rootless Docker
daemon per Docker site) and part 3 (azerioid-supervised leaves every vhost group) in **v2.7.0**.
**Closes, when complete:** the azerioid-supervised residual recorded in A37/A38/A49/A55. **Operator
decisions (2026-09-28):** a rootless `dockerd` per Docker site under the site's identity; programs bound
to a site always run as that site, with no run-as choice.

**Problem.** Every Supervisor program — each site's Octane worker, PM2 app, Docker container and the
operator's own programs — ran as one account, `azerioid-supervised`, which A49 made a member of every
vhost group so it could reach each site's files. Any one of those processes could therefore read and
change every site (measured on the Ubuntu host before this change: it read and wrote another site's
files), and could connect to every site's PHP socket (A55).

**Part 1 — decision.**

| Aspect | Decision |
|--------|----------|
| Run-as account | `ProgramIdentity::userFor`: a program bound to a site runs as the site's identity (`az-vh-*`); a program bound to no site, a Docker program (until part 2), a site still on the pre-A49 group, or a site put back by the migration runs as `azerioid-supervised`. Nothing else is accepted: a request for another account — root, a system account, another site's identity, or even `azerioid-supervised` for a site program — is refused |
| Octane | composer, `artisan octane:install`, `octane:reload` and the worker run as the site. Instead of an ACL for the shared account, the app is handed to the site (`chown -R -h <identity>:<site group>`, `chmod -R g+rwX`) |
| PM2 | runs as the site with `PM2_HOME=/var/lib/azerioid-pm2/<site>` (`0700`, the site's; the base `root:root 0711`) — the old home under `/var/lib/azerioid-supervised` is `0750` to that account alone |
| Operator programs | bound to a site: run as it, no ACL for the shared account. Bound to none: unchanged |
| Logs | stay in `/var/log/azerioid-supervised`; supervisord (root) writes them, not the program |

**Migration.** `program.identity.converge`, started by the scheduler every five minutes in a transient unit
(`azerioid-program-identity.service`), moves one program at a time: note its health (the site's HTTP
answer for Octane/PM2, the Supervisor state for an operator's program), hand the site's top directory to
the identity (what the shared account wrote there is its own), re-render and restart the program as the
site, look again (five retries). A program that worked before and does not now goes back to
`azerioid-supervised` at once, the reason recorded in `/var/lib/azerioid-panel/program-identity.json`,
and is not retried automatically; `azerioid process identity apply --confirm` (typed `ISOLATE-PROGRAMS`,
optionally `--domain=`) retries after a fix. State: `/etc/azerioid-panel/program-identity-migration.json`.
`azerioid process identity status` is non-zero until every site program runs as its site;
`verify-release.sh` checks it and that no Octane/PM2 program names the shared account.

**Part 2 — a Docker daemon per site (v2.7.0).** Measured on the Ubuntu host before: a container on the
shared daemon could not read another site through the account's groups (rootless runc drops supplementary
groups in the container), but it *could* bind-mount the shared daemon's own socket — the daemon owner is the
container's root — and from there list, `docker exec` into and read the environment of every other site's
container. Now each Docker site has a rootless daemon of its own, run by its identity (`SiteDocker`):

| Aspect | Decision |
|--------|----------|
| Daemon | `dockerd-rootless-setuptool.sh` as the identity; systemd user unit + linger; socket `/run/user/<uid>/docker.sock` |
| Where its state lives | The identity's home is its docroot, so a drop-in for its `user@<uid>.service` sets `XDG_CONFIG_HOME`/`XDG_DATA_HOME` to `/var/lib/azerioid-docker-home/<site>` (`0700`, the site's; base `root:root 0711`). Nothing lands in the web root |
| Subordinate ids | 65 536 of its own, after every range in use |
| CLI and program | every docker call for the site runs as the site against its daemon, `HOME` = its Docker home (also `environment=HOME=` of the Supervisor program); the container shell's ttyd too |
| Settings files | `/var/lib/azerioid-docker/<site>` `root:<site group> 0750` (env, ports.yml `0640`); base `root:root 0711` |
| Cost | measured about 170 MB RSS for a site's daemon stack right after an image pull on the 1 GB host (operator accepted ~80–150 MB; noted) |
| Lifecycle | disabling Docker on a site or deleting the site stops its daemon and removes its home, drop-in, linger and subordinate ids; `uninstall.sh --drop-db` does the same for every site |

A new Docker site gets its daemon at enable. Existing sites move through the same migration as part 1:
the new daemon gets the image while the old container still serves, the old container stops, files the old
containers wrote in the site are renumbered — the old daemon owner (a container's root) becomes the site's
identity, ids of the shared subordinate range move to the same offset in the site's range — and the program
restarts as the site. A compose project with **named volumes** is not moved automatically (their data is
inside the shared daemon); it is put back with the volume names in the reason. A move that breaks the site
is undone: files renumbered back, program back on the shared daemon, the site's daemon removed.

**Part 3 — the shared account leaves the site groups (v2.7.0).** When the migration finds no site program
left on `azerioid-supervised`, it removes that account from every `az-vh-*` group and writes
`/var/lib/azerioid-panel/supervised-detached`; from then on new sites do not add it (`VhostUser::readerUsers`).
A site put back later lets it into that one site's group again, and out when it is retried successfully.
`verify-release.sh` checks that the account is in no site group once the migration reports done.

**Still shared.** The shared rootless daemon itself stays installed (idle once every site has moved; put-back
sites use it). Image checks before enable (`manifest inspect`) still run as `azerioid-supervised`, which needs
no daemon and no site files.

**v2.7.1 addendum.** On the Ubuntu host `verify-release` read `migrated:true` in the second between the last
program move and the removal from the site groups, and reported the account still in 3 groups (it left all
10 a moment later). Status now counts part 3 as part of done: `migrated` needs the detach marker too, and
a host with every program moved but the marker missing is still eligible for the automatic run, which
finishes the removal (it also covers a host that never had a site program at all).

**v2.7.2 addendum.** Removing an account from `/etc/group` does not change a process that is already
running: on the Ubuntu host the idle shared Docker daemon (started before the removal) still held four site
groups, and two leftover test programs running as `azerioid-supervised` held the pre-A49 `azerioid-vhosts`
group, which one leftover test directory (not a vhost, so never quarantined) still used. The removal now
also takes the account out of `azerioid-vhosts` when no site identity still has it as its own group, then
restarts the account's user manager (the shared daemon) and every Supervisor program whose config runs as
it, managed or not. The marker records the removal's version (2); a host whose marker is older does the
removal again automatically. `verify-release.sh` now also checks that no running process of the account
holds a site group.

## A57 — The product's name and links on the sign-in, default and welcome pages

**Status:** Accepted (operator decision 2026-09-30), shipped in **v2.8.1**. **Revises:** A46's "names no
software" for the default site page.

**Decision.** The pages a visitor can see without signing in carry the product's name and two links —
`https://azerioid.dev` and the GitHub repository:

| Page | What it shows |
|------|---------------|
| Sign-in, setup and 2FA pages (`layouts/guest`) | the links under the form |
| Default site for unmatched hostnames (A46) | "Served by AZERIOID Stack Manager · azerioid.dev · GitHub" |
| A new site's welcome page | "Hosted with AZERIOID Stack Manager · azerioid.dev · GitHub" |

**What stays out: the version.** The name tells a scanner which product answers; the version would tell
it which advisories apply, and that is the part worth withholding. No page shows the panel's version,
the web server or the PHP version (the welcome page's live PHP line is the site's own, and the operator's
to remove with the page). Tests enforce this for the default page and the sign-in page.

**Existing hosts.** The default page is written when the feature is applied, so a host that already
enabled it keeps the old page until `azerioid panel default-site set --mode=page` is run again. A
custom page (`--html`) is never replaced. Welcome pages already seeded into sites are the sites' files and
are not touched.

### A46-E2 — Erratum: the HTTPS catch-all still failed for every real hostname

**What was claimed (A46-E1, v1.8.1):** that the global `default_sni` hands the default site's internal
certificate "to any unknown SNI", so a hostname no vhost claims completes the TLS handshake.

**What was true:** Caddy uses `default_sni` only when the ClientHello carries **no SNI at all** — a client
connecting to a bare IP address. A browser always sends the name it asked for, and for a name without a
certificate Caddy aborts the handshake (`tlsv1 alert internal error`; Chrome: `ERR_SSL_PROTOCOL_ERROR`). So
every real unknown hostname still failed, from v1.8.1 to v2.8.1. Found on 2026-09-30 when
`www.azerioid.dev` was pointed at the Ubuntu host before any vhost claimed it.

**Why the check did not see it:** `verify-release.sh` requested `https://127.0.0.1/` with a `Host:` header —
which sends no SNI, the one case `default_sni` does cover. The check tested the fix's own premise.

**Fix (v2.8.2):** the managed global block sets both `default_sni` (no SNI) and `fallback_sni` (an SNI no
certificate matches) to the `.invalid` provisioning name. Proven on the host before the release: an unknown
name went from a failed handshake to the default page. `verify-release.sh` now connects with a real SNI
(`--resolve nonexistent.invalid:443:127.0.0.1`), and a unit test requires both options. A host that already
enabled the default site gets the fix when `azerioid panel default-site set --mode=page` runs again.

**Lesson, again:** a check must reproduce the failing client, not a convenient stand-in for it.


## A58 — Broker hardening pass from the first source audit (`broker/src`)

The first full source audit of the root-privileged broker (scope `broker/src`, no prior runs) produced 18
`needs_validation` leads: paths where a **lower-trust principal** — a site identity (`az-vh-*` code, its
SFTP/cron/programs/containers), the Caddy service account, a backup-storage writer, or another local user —
could reach root or another tenant. None reached `confirmed`: the audit host was macOS, which cannot provide
the OS-enforced sandbox (no memory limit, no Docker, no PHP image) the workflow requires to run target code,
so every lead is source-grounded but not yet host-demonstrated. The panel user is root-equivalent by design
(A39: `cron.set`, `component.install`, `panel.update.apply`), so panel-only paths are same-principal and were
not treated as findings.

This release fixes the subset that is a clear, contained, conservative source change with a FakeRuntime
regression test. The fixes assert the broker emits the safe behaviour; they are **not** validated on a real
host (see the open items below).

**Fixed (v2.8.3):**

1. **Root children no longer use a lower-trust HOME.** `PosixRuntime::childEnv` pinned HOME/XDG to
   `/var/lib/caddy` when present — a directory the Caddy account owns, so root `git`/`curl`/`gpg` would read
   its config. HOME is now the root-owned `/var/lib/azerioid-broker`, with `GIT_CONFIG_GLOBAL=/dev/null`,
   `GIT_CONFIG_NOSYSTEM=1`, `CURL_HOME`, `GNUPGHOME`, `PYTHONNOUSERSITE=1`. The caddy CLI keeps its own env
   via `CaddyCli::dataEnvPrefix`.
2. **Secret files are created at their final mode.** `PosixRuntime::writeFile` wrote content then chmod'd,
   leaving `sasl_passwd` and the Dovecot passwd-file briefly 0644. It now writes a private temp file in the
   same directory and renames it over the target.
3. **Web-root / supervised-directory values reject control characters,** on input and on the
   `realpath()`-resolved result, so a site-planted symlink whose target name contains a newline cannot break
   out of a line in a rendered Caddy/Apache/nginx or supervisor config.
4. **Database restore refuses unauthenticated legacy archives** (LACMP1/LCMP1) unless an explicit
   `allow_legacy_unauthenticated` opt-in is set, matching the deep-verify control. An IV-flip of a legacy DB
   dump could otherwise become a `\!` shell escape in the root `mysql`/`psql` client.
5. **File restore stages into a fresh, unique directory** (removed after apply) instead of a fixed
   `restore-<site>` path, so symlinks left by an earlier archive cannot defeat `ArchiveGuard`'s lexical,
   empty-root link check.
6. **The supervised log directory stays root-owned** (`root:azerioid-supervised`, group-readable). root
   `supervisord` writes those logs itself; the account owning the directory let it swap a logfile for a
   symlink and have root append to any host file.
7. **Vhost creation rejects an identity-name collision.** Distinct domains that slug to the same `az-vh-`
   account (e.g. `blog.example.com` / `blog-example.com`) are refused rather than silently sharing one uid,
   group, pool, SFTP key file and deploy/Docker state.
8. **Vhost deletion revokes SFTP keys and ends the identity's processes.** `deprovision` now deletes
   `KEY_DIR/<username>` (so a re-created domain does not inherit a former tenant's keys) and runs
   `pkill -TERM/-KILL -u` before `userdel` (so a surviving session does not carry into the reused uid).

**Open — require a disposable-VM validation pass before a fix ships** (behaviour depends on real OS/tool
semantics, or the sink is high-blast-radius self-update code; all remain `needs_validation`):

- Symlink-following root `chown`/`chmod`/`setfacl`/write in site-owned Docker/PM2 homes, Docker volume dirs,
  supervised program dirs and reused stored docroots (needs `lchown`/`-h`/`openat` redesign).
- `find … -exec chmod` TOCTOU race in `VhostUser::applyOwnership`.
- `ttyd` on shared loopback with no credential and the session id in argv.
- `pg_restore --no-owner` as the superuser admin (restore as the owning role).
- DB admin passwords on `mongosh`/`mariadb`/`psql` argv.
- Composer `COMPOSER_HOME` in shared `/tmp` during panel self-update and Octane enable.
- Shared-top directory handed to one site by `claimTop`/`SitePool`.
- Panel `bootstrap/cache/config.php` left world-readable under a Caddy-traversable tree after self-update.
- Caddy access-log creation following a web_user-planted symlink (needs the attacker to run as `caddy` first).

The full audit (`REPORT.md`, `NEEDS-VALIDATION.md`, coverage ledger, per-lead traces and validation plans)
is kept outside the repo at `~/audits/azerioid-panel/run-1/`. A follow-up run should start with the three
critic-accepted units deferred for budget, chiefly the Adminer / shared-pool PHP-FPM socket → panel identity
path.

## A59 — Panel FPM socket reachable by the Caddy group: drop /tmp from the panel pool open_basedir

The deferred audit unit from A58's second coverage critic: a path from a lower-trust, Caddy-group
process to root.

**The chain.** The panel PHP-FPM socket is `listen.owner = azerioid-panel`, `listen.group = caddy`,
mode 0660 — the group must stay `caddy` because after the A39 migration Caddy reaches the panel through the
socket **group**, not the owner (PanelIdentityMigrator). So any process with gid `caddy` can open the
socket. The Adminer tool pool runs with group `caddy` (its account is also added to the group), and its
`disable_functions` leaves `stream_socket_client`/`fsockopen`/`fwrite`, so it can speak raw FastCGI. A
FastCGI client can set `SCRIPT_FILENAME` and `PHP_VALUE[auto_prepend_file]`; `open_basedir` is locked by
`php_admin_value` but `auto_prepend_file` is not, so a payload staged anywhere inside the panel pool's
`open_basedir` would execute **as the panel user**, which holds the NOPASSWD broker sudo grant → root.

**Confirmed on the fleet host (read-only):** panel socket `srw-rw---- azerioid-panel caddy`;
`azerioid-adminer-tool` is in group `caddy`; the panel pool's `open_basedir` contained `/tmp`.

**Fix (v2.8.3+1).** Remove `/tmp` — the only attacker-writable entry — from the panel pool `open_basedir`,
in all three authoritative places: `deploy/php-fpm/azerioid-panel.conf`, `deploy/lib/fpm.sh` (install), and
`PanelUpdater::syncPanelFpmOpenBasedir` (self-update enforces it on existing hosts). After removal the
remaining entries are not writable by gid `caddy`: `web` and `/var/lib/azerioid-panel` are
`azerioid-panel:caddy 0750` (group read/traverse only), `/var/log/azerioid-panel` is `root:azerioid-panel`,
and `/dev/urandom` / `/usr/bin/sudo` are not writable. With no writable path in `open_basedir`, the FastCGI
client cannot stage an `auto_prepend_file`/`SCRIPT_FILENAME` payload, so code execution as the panel user is
closed. The panel's own temp stays `storage/framework/tmp` (`sys_temp_dir`/`upload_tmp_dir`).

**Not changed (why).** The socket group cannot move off `caddy` without breaking Caddy→panel after A39.
Taking the Adminer pool out of the `caddy` group is the stronger defence-in-depth (it would stop that pool
reaching the socket at all), but the Adminer tool directory lives under the panel-owned
`/var/lib/azerioid-panel` tree (group `caddy`, 0750), so the pool needs the group to traverse to its own
code. Removing it requires relocating the tool directory out of the panel tree — a migration-sensitive
change with fleet-outage risk — and is deferred to its own validated pass. The `/tmp` removal already closes
the root-execution path; the group reach is a remaining defence-in-depth gap.

## A60 — Panel FPM socket: web-user-owned, panel-user-grouped (completes A59)

A background security review flagged A59 (dropping `/tmp` from the panel pool `open_basedir`) as an
**incomplete** fix, and it was right. The real issue is reachability: the panel FPM socket was grouped to
`caddy`, so any gid-`caddy` pool (the Adminer tool pool) could open it. A reachable PHP-FPM socket is code
execution regardless of pool hardening — a malicious FastCGI client can send `PHP_ADMIN_VALUE` to override
`open_basedir`/`disable_functions`/`auto_prepend_file` for its own request. So no pool-level lock closes it;
only removing the reach does.

**Why the socket was group-`caddy`.** After the A39 migration the panel worker runs as `azerioid-panel`,
and `listen.owner` was set to `azerioid-panel` too, so Caddy could only reach the socket through the
**group** (`caddy`). That group is shared by every web-tier pool.

**Fix (v2.8.5).** Own the socket by the **web user** and group it to the **panel user**:
`listen.owner = <web user (caddy)>`, `listen.group = azerioid-panel`, mode 0660. Caddy reaches the socket as
its owner; nothing in the `caddy` group does. The worker still runs as `azerioid-panel`.

- `PanelIdentityMigrator::writePool` sets this for fresh migrations; its verify now expects
  `<web user>:azerioid-panel`.
- `PanelUpdater` heals already-migrated hosts on self-update: when the pool worker is the panel user
  (migrated) and differs from the web user, it rewrites `listen.owner`/`listen.group` and, because a
  graceful reload does not re-create the listen socket, forces a full FPM **restart** so the new ownership
  takes effect.
- Fresh pre-migration installs keep `owner=caddy group=caddy` with a `caddy` worker — same principal, no
  cross-pool escalation there.

The A59 `/tmp` removal stays as defence in depth. Taking the Adminer pool out of the `caddy` group (so it
cannot even see the socket) is still worthwhile but needs relocating its tool directory out of the
panel-owned tree; deferred. With A60 the socket is unreachable by that pool regardless.

**Validate before trusting on a host:** confirm Caddy still serves the panel (`verify-release.sh`
`CHECK_PANEL_HTTP`) and that a gid-`caddy` process can no longer `connect()` the panel socket.

## A61 — Root file ops in site-owned Docker/PM2 homes must not follow planted symlinks

A58 deferred lead h02. `SiteDocker::ensure` and `Pm2Manager::ensurePm2Home` create a per-site home under a
root-owned base (`/var/lib/azerioid-docker-home`, `/var/lib/azerioid-pm2`, both 0711), hand it to the site
(0700), then on every later call (re-enable, A56 migration, PM2 setNode/reapply) loop over fixed subpaths
(`.config`, `.local`, `.local/share`; `logs`, `pids`, `modules`) and `chown`/`chmod`/`writeFile` them. Those
used PHP `chown`/`file_put_contents`, which follow symlinks. The site owns the home, so it can replace a
subpath with a symlink and have root chown/overwrite an arbitrary target (e.g. `.config -> /etc`), or write
the `.azerioid-ready` marker over any file.

**Fix (v2.8.6).** Before each such op, `VhostUser::assertNoSymlinkUnder($base, $path)` walks every component
beneath the root-owned base and refuses if any (final or intermediate) is a symlink (new `Runtime::isLink`,
`lstat`-based, modelled in FakeRuntime). Ownership is then applied with `chown -h` (exec, no dereference) as
defence for the final component. A real tree is unaffected; a planted symlink aborts the action.

**Still deferred (same family, separate passes):** `DockerManager::ensureVolumeDirs` mutates (mkdir/chown)
before `resolveUnderBase` and has a mkdir→chown TOCTOU; `VhostUser::applyOwnership`'s `find … -exec chmod`
race; `OctaneManager` composer `/tmp`. These need a disposable-VM race/behaviour pass; A61 covers the
straightforward fixed-path symlink-follow in the two home builders.

## A62 — A shared www_root top must not be handed to one site (claimTop / SitePool)

A58 deferred leads `claimTop:shared-top-claimed-by-one-site` and `sitepool-shared-top-chown`. When two
vhosts live under one directory (e.g. `/data/www/app/public` and `/data/www/app/admin`),
`VhostUser::claimTop` chowned the shared `/data/www/app` to whichever site was created, and
`SitePool::ensure` ran `chown -R`/`chmod -R g+rwX` over it on first pool creation and set the pool's
`open_basedir` to it. Either let one site own and rewrite the other's whole tree. ADR A49-E1 already says
the docroot — not the shared directory — is the isolation unit in that case, and `VhostIsolationMigrator`
honours it; the creation paths did not.

**Fix (v2.8.7).** `VhostUser::topSharedByAnotherSite(domain, top)` consults the recorded site roots
(`vhost-users.json`) and reports whether another site's docroot is the top or sits under it. `claimTop`
skips claiming a shared top; `SitePool::ensure` confines both the one-time ownership handover and
`open_basedir` to the site's own docroot instead of the shared top. A sole site under its own top is
unaffected.

## A63 — Erratum: A62's shared-top guard was order-dependent

A background review found A62 incomplete. `claimTop` records the site (`record()`) *after* it runs, and the
guard only made the *later* site skip claiming. The site created **first** under a top had already claimed
it before the second existed, so it kept ownership of the now-shared directory — the isolation held only if
sites were created in one order.

**Fix (v2.8.8).** When `claimTop` sees the top is shared, it does not just skip: it resets the top to
`root:root` mode 0711 (neutral, traversable, owned by no site). Whichever site's `ensure()` runs once the
top has become shared reverts a prior claim, so the outcome no longer depends on creation order; each site
keeps only its own docroot. The reset is withheld only when the top is itself another site's exact docroot
(a nested-docroot misconfiguration — resetting would strip that site's own root; that overlap is a
creation-validation gap, out of scope here). `SitePool`'s A62 confinement already handles the pool side.

## A64 — Docker volume dirs: confine before creating/owning them

A58 deferred lead `h02:DockerManager.ensureVolumeDirs:mutate-before-confine`. For each configured bind-mount
`ensureVolumeDirs` ran `mkdir`/`chown`/`chmod` on `<app_dir>/<host>` and only *afterwards* called
`resolveUnderBase`. The app directory is site-owned, so a symlink the site planted in it let root create and
own a directory outside the app tree before the confinement check fired.

**Fix (v2.8.9).** Confine first: `VhostUser::assertNoSymlinkUnder(app_dir, path)` and `resolveUnderBase`
run *before* any mutation and reject an escaping or symlinked path; the create is followed by a second
symlink + confinement re-check (in case `mkdir` followed a component swapped in the window); and ownership
is applied with `chown -h` so a swapped leaf symlink is never dereferenced. A narrow mkdir→chown race
remains theoretically but no longer yields ownership of a path outside the app dir. The compose file itself
still runs on the per-site rootless daemon.

## A65 — Octane Composer home off world-writable /tmp

A58 lead (h02 variant / supply-chain). `OctaneManager::installOctanePackage` used
`COMPOSER_HOME=/tmp/azerioid-octane-composer-<pid>`: a predictable, world-writable path. Another local user
could pre-create it (`PosixRuntime::mkdir` accepts an existing dir; the chown then hands it to the site
identity), planting a global Composer plugin that runs as that site when `composer require laravel/octane`
executes — a cross-site / site-escalation vector. `--no-plugins` was also not set.

**Fix (v2.8.10).** Create the Composer home as a fresh, unguessable (`random_bytes`) directory under a
root-owned 0711 base (`/var/lib/azerioid-octane`): others can traverse it but cannot create entries, so the
target cannot be pre-staged. Chown the per-run dir to the site with `-h`, and pass `--no-plugins` to
`composer require`. The PanelUpdater self-update Composer `/tmp` variant remains open (it runs in the
self-update path; see the bootstrap note) and is tracked separately.

## A66 — Installer supply chain: verify Composer and pin repo keys

Run-2 audit (web/ + deploy/) leads `deploy/composer-installer-no-integrity-check` and
`deploy/repo-trust-root-unpinned-tofu`. Two root-executed acquisitions in the installer trusted whatever
bytes a network fetch returned:

- `packages.sh` piped `curl https://getcomposer.org/installer | php --` with no integrity check, so a
  compromised origin/CDN, an active TLS MITM, or a redirecting resolver meant arbitrary code as root at
  install time.
- `repos.sh` defined `verify_gpg_key()` (and `REMI_GPG_URL`) but **never called them**: the Caddy and Sury
  keys were `curl | gpg --dearmor` trust-on-first-use, and the EL Remi release RPM was installed by URL with
  `|| true` swallowing failure. This directly contradicted **A10** (GPG fingerprint verification), which had
  been dead code since it was written.

**Fix (v2.8.11).**
- Composer: fetch the installer to a temp file and compare its SHA-384 against Composer's published signature
  (`composer.github.io/installer.sig`, a separate origin) before running it; abort on mismatch.
- apt (Caddy, Sury) and EL (Remi): `extract_pinned_key` imports the fetched material into a throwaway keyring
  and **re-exports only the key whose fingerprint equals the pin**; that exported keyring is what gets
  installed (apt `signed-by`) or `rpm --import`ed. The installed keyring is thus *constructed by gpg* to hold
  exactly the pinned key rather than being the fetched bytes passed through a check — so no second parser
  (`--show-keys`, awk) can disagree with what apt/rpm later consume, and a fetch that appends, pads, or
  shadows keys cannot smuggle an extra trusted key through (apt `signed-by` trusts every key in the keyring).
  Verified on a real host: a "legit key + appended attacker key" input exports only the pinned primary; an
  absent pin is rejected. An attacker cannot forge different key material under the pinned fingerprint (hash
  preimage), and subkeys without a valid binding signature are dropped on import.

  **Erratum (v2.8.12).** A gpg key selector also matches *subkey* fingerprints, so `--export <pin>` alone did
  not guarantee the exported *primary* equalled the pin — it rested on SHA-1 preimage resistance. After the
  export, `extract_pinned_key` now asserts directly on the reconstructed keyring that there is exactly one
  primary (`pub`) key and its fingerprint equals the pin (case-insensitive), and exports with
  `--export-options export-minimal`. The dest keyring is removed on any failure.
  Pinned fingerprints — Caddy `65760C51EDEA2017CEA2CA15155B6D79CA56EA34`, Sury
  `15058500A0235D97F5D10063B188E2B695BD4743` — were verified with gpg against both the live upstream key and
  the key already trusted on the production host.
- EL (Remi): import the key that actually signs `remi-release` — fingerprint
  `B1ABF71E14C9D74897E198A8B19527F1478F8947` from `RPM-GPG-KEY-remi2021` (verified on a real Rocky 9 host:
  `remi-release-9.rpm` is signed by key id `B19527F1478F8947`; the old `RPM-GPG-KEY-remirepo` URL 404s and
  `RPM-GPG-KEY-remi2018` is a different key) — as an **armored** key (`rpm --import` rejects a binary export;
  `extract_pinned_key` takes a format argument), then install remi-release with `localpkg_gpgcheck=1` so dnf
  verifies the RPM against that key; the `|| true` is removed so the step fails closed. Validated end to end
  on Rocky 9.8: `rpm -K` reports `signatures OK` and a wrong pin is refused. The pin tracks Remi's current
  signing key and must be updated if Remi rotates it (the failure is loud, not silent).
- `verify-release.sh` gains `CHECK_REPO_KEYS_PINNED`: the installed Caddy/Sury keyrings must carry the pinned
  fingerprints on the host.

The `|| true` removal makes a previously-silent EL failure loud; this is the intended fail-closed posture and
is exercised on the disposable EL host before release. The PanelUpdater self-update Composer `/tmp` variant
(A65 note) remains tracked separately.

## A67 — Panel self-update Composer home off world-writable /tmp

A58 deferred lead (self-update variant of the A65 Composer finding). `PanelUpdater::deployFileSync` ran
`composer install` with `COMPOSER_HOME=/tmp/azerioid-composer-<pid>` (predictable, pre-creatable in the
world-writable sticky `/tmp`) and `composer dump-autoload` with a **fixed** `COMPOSER_HOME=/tmp`. A local user
could seed `/tmp/config.json` / `/tmp/auth.json` (or pre-create the pid dir) that Composer reads while running
as the **web user** during `panel.update.apply` — high blast radius (A39). `--no-plugins` was not set.

**Fix (v2.8.14).** `panelComposerHome()` creates a fresh, unguessable (`random_bytes`) home under a root-owned
0711 base (`/var/lib/azerioid-panel-composer`) — others can traverse but not create entries, so the target
cannot be pre-staged — hands only that per-run dir to the web user (`chown -h`), and both Composer calls now
pass `--no-plugins`. Mirrors `OctaneManager` (A65). Note the self-update bootstrap gap (A60): this runs under
the in-process broker code during an update, so it takes effect on the release *after* the one that ships it;
the installed code heals on the subsequent update.

## A68 — MongoDB admin password off child-process argv

A58 deferred lead (DB credentials on argv). `MongoDriver::mongoshArgv` ran `mongosh -u <user> -p <password>
--eval <js>`, putting the MongoDB admin password on the child's command line — readable via
`/proc/<pid>/cmdline` by any local user (a site identity, the web user) for the life of the call. MariaDB
already used a `--defaults-extra-file` and PostgreSQL authenticates over the local socket, so Mongo was the
remaining argv exposure.

**Fix (v2.8.15).** `mongoshArgv()` is now just `mongosh --quiet`; the operation and credentials travel on
**stdin** as a script that authenticates in-session
(`if (!db.getSiblingDB("admin").auth(user, pass)) throw …`). Neither the password nor the operation appears
in argv or on disk. Validated against a real MongoDB 7.0 on a disposable host: the piped-stdin script
authenticates and runs (`listDatabases.ok = 1`), a wrong password throws `AuthenticationFailed` and runs
nothing, and the password never reaches argv (unit test asserts the invocation is exactly
`['/usr/bin/mongosh','--quiet']`). This is a broker action (fresh process), so it applies on the release that
ships it — no self-update bootstrap gap.

## A68 erratum — redact the Mongo password from surfaced errors

Follow-up to A68 (v2.8.15). With the password moved into the stdin script, a mongosh error that echoes the
offending source line could carry the secret into its stderr/stdout, which `MongoDriver` surfaces in a
`BrokerException` (and thence the operation log). `redact()` now strips the known password from any mongosh
output before it is placed in an exception message; raw output is still used for JSON parsing. Regression test
asserts a mongosh error whose stderr contains the password is surfaced as `[redacted]`.

## A68 erratum 2 — Mongo credentials via env + `--file /dev/stdin` (fail-closed, no redaction dependency)

Two gaps in the first A68 cut (v2.8.15/16):
1. The password was a literal in the stdin script. If mongosh echoed a source line on error the secret could
   reach stderr → a `BrokerException`/log. Redaction (erratum 1) only matched the raw string, not the
   `json_encode` form actually present in the script — a parser differential.
2. mongosh runs bare piped stdin as a **REPL, statement by statement**, so a `throw` in the auth guard did not
   stop the following operation statement (the server still rejected it, but the guard was not itself
   fail-closed).

**Fix (v2.8.17).** The admin credentials are passed through the **child environment** (`Runtime::exec` gained
an optional `$env` merged into the hardened `childEnv()`; a process environment is readable only by the same
user or root, unlike world-readable argv), and the script references `process.env.AZ_MONGO_USER/PW` — so no
admin secret is a literal in argv or the script, and there is nothing for redaction to miss. The script is run
with `mongosh --quiet --file /dev/stdin`, which executes the piped bytes as a **single program**: a thrown
auth guard aborts before the operation (validated against a real MongoDB 7.0 — a wrong password runs no
operation). Tenant passwords in mutation scripts still travel only on stdin (never argv or disk). `redact()`
remains as defense in depth but is no longer load-bearing.

## A69 — Cached config not readable by the web user

A58 deferred lead, confirmed on the live fleet. `artisan config:cache` inlines APP_KEY and the DB/mail
secrets from `.env` into `bootstrap/cache/config.php`, and Laravel (re)creates that directory `0755` with the
file `0644`. The panel web root is `root/azerioid-panel` owned but **group `caddy`** with `r-x` so the web
user can traverse it to serve `public/` — and `bootstrap`/`bootstrap/cache` at `0755` plus `config.php` at
`0644` meant **caddy could read `config.php`** and recover the panel's encryption key. That defeats the A59/A60
boundary (APP_KEY → forge panel sessions / decrypt panel data → escalate to the root-equivalent panel user).
`.env` itself was already `0640` (caddy could not read it); the cache re-exposed the same secrets. Verified:
`sudo -u caddy cat .../bootstrap/cache/config.php` succeeded on the fleet.

**Fix (v2.8.18, refined v2.8.19).** `PanelUpdater::rebuildCaches` calls `lockBootstrapCache()` after building
the caches: `chmod 0750 bootstrap/cache` (the directory only). caddy reaches `config.php` solely by traversing
this directory, so denying its "other" execute bit blocks the read whatever mode the files carry — a single
chmod on one known, panel-owned path rather than a recursive `chmod -R` dereferencing whatever symlinks a tree
walk meets as root (v2.8.18 used `-R o-rwx`; not exploitable since only the panel user can write the cache,
but the narrower op is the correct privileged pattern).
`verify-release.sh` gains `CHECK_CADDY_CANNOT_READ_PANEL_CONFIG_CACHE`. **Self-update bootstrap gap (A60):**
`rebuildCaches` runs under the in-process broker during `panel.update.apply`, so the update that *ships* A69
still re-locks with the old (no-op) code; the fix takes effect on the next update. A host already carrying a
`0644` cache is remediated out of band (`chmod -R o-rwx bootstrap/cache`) until then.

## A70 — Terminal ttyd on a per-session UNIX socket, not a loopback TCP port

A58 deferred lead. `TerminalManager` spawned `ttyd -p <port> -i 127.0.0.1 -W … /bin/bash -l` as the session
identity with **no credential**, fronted only by Caddy `forward_auth` (per-session `reverse_proxy
127.0.0.1:{port}`). A loopback TCP port is reachable by **any** local process, and the port + session id are
world-readable (argv / `systemctl`). So during an active terminal session another site identity (via its
cron/program) or a gid-caddy process could connect straight to the ttyd port, bypass the panel auth, and get
an interactive shell as the session identity — a cross-principal RCE within the session window.

**Fix (v2.8.20).** ttyd now binds a **per-session UNIX socket** (`-i <dir>/ttyd.sock`, ttyd 1.7.7). The broker
(root) pre-creates `/run/azerioid-panel/terminal/<id>` owned `<ttyd-user>:<web-user group>` mode **2750**
(setgid): the unprivileged ttyd (owner) creates the socket, which **inherits the web group** via setgid and
lands `0660`, so only the web user (the Caddy reverse proxy) and the shell owner can connect — every other
local user and site identity is denied at the `2750` directory. The Caddy snippet proxies `unix/<socket>`
(the same mechanism already used for the panel and PHP-FPM sockets) and keeps the `forward_auth` gate as
defense in depth. Validated on a real host: a web-group member connects through the socket while an unrelated
user is refused (`srw-rw---- user:webgroup`). Port allocation (`allocatePort`/`portListening`) is removed; the
socket dir is torn down with the session. This is a broker action (fresh process), so it applies on the
release that ships it.

## A71 — PostgreSQL restore runs under the target's owning role

A58 deferred lead. `PostgreSqlBackupEngine::restoreCommandFor` restored a custom-format dump with
`pg_restore -d <db> --no-owner` connected as the PostgreSQL **superuser**. A tenant's own database can contain
a `SECURITY DEFINER` function (or similar); `pg_dump` captures it, and a `--no-owner` restore as the superuser
creates every object **owned by the superuser**. The tenant could then call that function and execute as the
superuser — full PostgreSQL compromise, and potentially OS command execution (`COPY … TO PROGRAM`, untrusted
PLs). The restore is triggered by the operator, but the dump content is tenant-controlled.

**Fix (v2.8.21).** `restoreCommandFor` looks up the target database's owning role
(`pg_catalog.pg_get_userbyid(datdba)`) and adds `pg_restore --role=<owner>`, which issues `SET ROLE` after the
superuser connects, so objects are created owned by the tenant and carry only the tenant's privileges — a
`SECURITY DEFINER` object in the dump is owned by the tenant, not the superuser. `--role` is omitted only when
the owner is unknown or is the admin role itself. Validated on a real PostgreSQL: a `SECURITY DEFINER`
function in a `-Fc` dump restores owned by `postgres` without `--role` and owned by the tenant with it. The
`pg_dumpall`/`psql` path is a cluster-level operator restore (not tenant-scoped content) and is unchanged.

### A71 erratum — fail closed when the owner is unknown

The first A71 cut omitted `--role` when `ownerOf` returned nothing, silently falling back to the superuser
restore — the exact escalation the guard prevents (fail-open state drift). `prepareTarget` runs before
`restoreCommandFor`, so the database exists and a healthy lookup always returns an owner; an empty result now
**aborts the restore** instead. `--role` is still dropped only when the resolved owner is the admin role
itself (an admin-owned database restored by the admin — same principal). Regression test asserts an unknown
owner throws.

### A71 erratum 2 — fresh targets owned by an unprivileged role; refuse admin-owned targets

The owner==admin branch was still a hole: a restore into a **new** database (a supported flow) had
`prepareTarget` create it with `createdb` owned by the connecting superuser, so the restore ran as the
superuser and a `SECURITY DEFINER` object became superuser-owned. New PostgreSQL databases grant PUBLIC
CONNECT and PUBLIC EXECUTE by default, so any tenant could then call it. **Fix (v2.8.23):** a dedicated
unprivileged role `azerioid_restore` (NOSUPERUSER/NOLOGIN, ensured idempotently) owns every fresh restore
target (`createdb -O azerioid_restore`), and the restore runs under the target's owning role; an owner that is
unknown **or the connecting admin** now aborts the restore. So objects always land owned by a role with no
privileges a tenant could abuse. Validated end to end on a real PostgreSQL: a `SECURITY DEFINER` function in a
dump restored into a fresh database is owned by `azerioid_restore` (rolsuper = false).

### A71 erratum 3 — refuse any superuser-owned target

The owner check only excluded the connecting admin, so a target owned by a *different* superuser role would
still get `--role=<that superuser>` and restore as a superuser. The owner lookup now also reads `rolsuper`
(`pg_roles.rolsuper`) and the restore aborts when the owner is **any** superuser (or the admin, or unknown).
Query output format verified on a real PostgreSQL (`tenant|f`, `postgres|t`).

### A71 erratum 4 — per-target restore role, revoke PUBLIC

A single shared `azerioid_restore` role owned every fresh tenant's restore database, so a SECURITY DEFINER
object owned by it had the same owner as other tenants' restore databases (cross-tenant privilege sharing;
bounded by PostgreSQL's per-database isolation, but avoidable). Fix (v2.8.25): each fresh target gets its own
unprivileged role `azerioid_rst_<sha1(db)[:24]>` (NOSUPERUSER/NOLOGIN, ensured idempotently, dropped with the
database), and `prepareTarget` runs `REVOKE ALL ON DATABASE <target> FROM PUBLIC` so a fresh target is not
reachable by any role until the operator grants access (new databases grant PUBLIC CONNECT by default).
Validated on a real PostgreSQL: the per-target role is unique, and the database ACL loses its PUBLIC CONNECT
entry after the revoke.

## A72 — Caddy file_server cross-site symlink reads; per-site content hardening

A58 deferred lead, confirmed on caddy v2.11.4: `file_server` **follows symlinks that escape the docroot**
(validated serving a sibling-dir file and `/etc/passwd` through planted symlinks). A site identity owns its
docroot and caddy is a member of every `az-vh-*` group, so a symlink from site A's docroot to
`/data/www/siteB/.env` (or any file caddy can read) is served to site A's operator over HTTP — a cross-tenant
secret read. Caddy has no option to refuse symlinks (`file_server` only exposes `--reveal-symlinks` for
browse display), and a `nosymfollow` mount would break legitimate in-site symlinks such as Laravel's
`public/storage` → `../storage/app/public`.

**Fix (v2.8.26).** `VhostUser::hardenContent`, run from `ensure` whenever a site is (re)touched (provision,
restore, deploy, terminal, SFTP), bounded by the **site top** (`wwwRoot/<first component>`):
  1. quarantines symlinks whose resolved target escapes the site top (within-site links, incl. the Laravel
     storage link, are kept) by moving the link out of the served tree into `/var/lib/azerioid-panel/quarantine`;
  2. forces `.env` files to `0600` so caddy (group member) cannot read a site's secrets even via a within-site
     path.
Validated on a real host: the Laravel storage link survives, escaping links to another site's `.env` and to
`/etc/passwd` are quarantined, `.env` lands `0600`.

**Accepted ceiling (the "accept" in 1+2+accept).** Enforcement is event-driven (at `ensure`), not a continuous
sweep — the converges early-return once migrated, so a dedicated every-5-minute job would be a separate
subsystem; a site can re-plant a symlink between touches, and an app can recreate `.env` at a looser mode
until the next `ensure`. Within-site symlinks are still followed by caddy (a site reading its own files is not
a boundary crossing). Full containment would need an upstream caddy option (`openat2(RESOLVE_BENEATH)`) or a
per-site web server. A later continuous sweep can reuse `hardenContent` if the residual window proves to matter.

### A72 erratum — run the content sweep as the site user, newline-safe

The first A72 cut ran the symlink `mv` and `.env` `chmod` as **root** over a site-controlled tree, parsing
`find` output on newlines: a filename with an embedded newline could inject a second path (arbitrary root file
move), and a raced parent-directory swap could make root `mv`/`chmod` an arbitrary path (TOCTOU). Fixed
(v2.8.27): `hardenContent` runs the whole sweep **as the site user** via `runuser` (the user owns the tree
after `applyOwnership`), so a race can only reach files the site user could already touch — never a root
arbitrary write; iteration uses `find -print0` with `read -d ''` (newline-safe); escaping symlinks are
`rm`'d (the link only, target untouched) rather than moved to a root-owned quarantine. Validated on a real
host: within-site links kept, escaping links removed with their targets intact, `.env` set 0600, and a
site-user `chmod /etc/shadow` is refused (Operation not permitted) — confirming the sweep cannot escalate.

### A72 erratum 2 — fail closed on an unresolvable symlink target

The site-user sweep fell back to the link's own path when `realpath` failed (`|| printf '%s' "$l"`), so a
symlink whose target the site user could not resolve — e.g. one pointing into another site's `0750` tree that
caddy (in that site's group) *can* read — was treated as within-site and **kept** (fail open). The sweep now
uses `realpath -e` and **removes** any symlink whose target cannot be resolved. Validated on a real host: a
within-site link the site user resolves is kept, while an escaping link into a directory the site user cannot
traverse (but that holds a readable secret) is removed, target intact. The sweep remains non-fatal to the
`ensure` it rides on (ADR A72 accepted best-effort ceiling): coupling it to `ensure` success would break
Terminal/File Manager/restore on any hiccup, which is disproportionate for a defense-in-depth control layered
over the isolation model.

## A73 — apt source lists world-readable (quiet command-not-found in site shells)

`repos.sh` wrote `/etc/apt/sources.list.d/{caddy-stable,php-sury}.list` with a plain `echo >`, so their mode
followed the install-time umask. On a host provisioned under a restrictive umask they landed `0600`; apt reads
them as root so packaging worked, but Ubuntu's `command-not-found` handler runs as the invoking (site) user,
could not read them, and printed `WARNING:root:could not open file … Permission denied` into every vhost
Terminal session on an unknown command. These files hold only a public repo URL (no secret) and are
conventionally `0644`. **Fix (v2.8.29):** `repos.sh` now `chmod 0644` each list explicitly, independent of the
ambient umask. Existing fleet hosts were remediated in place with the same `chmod`.

## A74 — Vhost bundle restore verifies each part against the manifest checksum

A58 deferred lead. A vhost backup bundle is an authenticated `manifest.lacmp2.bin` (parts list with a plaintext
`sha256` per part, plus domain/identity) and independently LACMP2-encrypted parts (`files`, `config`,
`db-*`). Restore checked the manifest's `domain` but **never verified a part's recorded sha256** before
decrypting and applying it. Each part is AES-256-GCM, so tampering without the passphrase already fails — but
the GCM AAD binds only the per-part header (its own random salt/nonce) and chunk index, **not the part's
name, domain or stamp**. So a backup-storage writer (a lower-trust principal) could **substitute** one valid
same-passphrase blob for another — an older `config`, or a different domain's `files` — and the restore
accepted it, injecting that content (cross-context / cross-tenant) into the target. The manifest's per-part
sha256 is the binding of which content belongs to a part; it just was not enforced.

**Fix (v2.8.30).** `BackupRestore::handle` verifies the decrypted part against an `expected_sha256` when the
caller supplies one (constant-time `hash_equals`), and `VhostBundle::restore` passes each part's manifest
sha256 (`files`, `db-*`) and checks the inline-decrypted `config` the same way; `requirePartSha` fails closed
if a bundle part lacks a valid 64-hex checksum. The manifest is itself AEAD-authenticated, so its sha256
values are trustworthy. Direct single-archive `backup.restore.files/db` (no manifest) are unaffected — they
rely on AEAD, there being no sibling parts to substitute. Regression tests: a swapped `config` and a swapped
`files` part are both refused.

## A75 — Adminer pool: private temp (no /tmp); tool-dir relocation assessed and deferred

A58 deferred lead. Two parts:

**Done (v2.8.31) — drop /tmp from the Adminer pool.** The `azerioid-adminer-tool` FPM pool's `open_basedir`
included world-writable `/tmp` (shared with every other local user). It now points at a private temp dir
inside the tool dir (`TOOL_DIR/tmp`, owned by the Adminer user 0700), and the pool sets `sys_temp_dir`,
`upload_tmp_dir` and `session.save_path` to it — so the gid-caddy Adminer tool no longer reads/writes shared
`/tmp`. Mirrors A59 (panel pool). Takes effect when the Adminer component is next (re)installed/updated.

**Deferred — relocating the tool dir out of `/var/lib/azerioid-panel`.** The goal was to let the panel state
dir drop the `caddy` group. Assessed on the live fleet and **found non-exploitable**: the state dir is
group-caddy *traversable* (so the Adminer pool can reach its nested tool dir and caddy can read the
`caddy-*-routes.conf`), but every sensitive file in it is group-protected and unreadable by caddy/Adminer —
`panel.sqlite` is `azerioid-panel:azerioid-panel 0660`, the `*.json` state files are `root:root 0640`,
`broker.json` lives in `/etc/azerioid-panel`. The caddy group can read only non-secret files (source
`VERSION`/`README`, the already-world-readable route confs, default-site HTML). Fully dropping the caddy group
would require relocating **both** the tool dir *and* the route-conf files (caddy traverses the dir to read
them) — a two-part live migration for no current exposure. Deferred as disproportionate; revisit if the state
dir ever needs to hold a group-caddy-readable secret.

### A75 erratum — Adminer temp dir is a sibling under the root-owned parent

The first cut put the private temp dir at `TOOL_DIR/tmp` — inside the adminer-owned tool dir, so the adminer
user (gid-caddy) could plant a symlink there and root's `mkdir`/`chown`/`chmod` of it on a component re-run
would follow it (local privilege escalation). Moved to a sibling `…/tools/adminer-tmp`: its parent `tools` is
`root:caddy 0750`, which the adminer user cannot write, so the entry cannot be swapped and root's operations
are symlink-safe.

## A76 — Caddy access-log created as the web user, not root (symlink-safe)

A58 deferred lead. `CaddyDriver::ensureAccessLog` chowns `/var/log/caddy` to the web user, then as **root**
created, chowned and chmodded `access_<domain>.log` inside that web-user-owned directory. Because the web
user owns the directory, it could plant a symlink at the log path, and root's `chown`/`chmod` would follow it
to an arbitrary target — a local privilege escalation from code-exec as the web user (caddy) to root. Lower
priority because it requires code-exec as caddy first, but a real escalation path.

**Fix (v2.8.33).** The log file is created and moded **as the web user** via `runuser` (`[ -L ] && rm`;
create only when absent so a live log is never truncated; `chmod 0640`). A planted symlink can then never
exceed the web user's own privileges, so root never follows one. The directory `chown` stays as root but is
safe — `/var/log` is root-owned, so the `/var/log/caddy` entry cannot be swapped. Regression test asserts the
log is touched via `runuser -u <web user>` and never via a root `chown`/`chmod` of the log path.

## A77 — applyOwnership find/chmod race: analyzed, accepted (no clean fix)

A58 deferred lead. `VhostUser::applyOwnership` runs `chown -R` to the site user then, as root,
`find <root> -type {d,f} -exec chmod {2770,0660}`. After the chown the site owns the tree, so between find's
`lstat` (which excludes symlinks via `-type`) and chmod's open the site can swap a file for a symlink, making
root chmod an arbitrary target.

**Impact is limited:** chmod changes mode only — never owner or group — so it cannot grant the site access to
a file whose group the site is not already in. The meaningful case is a file already in a group the site
belongs to (e.g. `azerioid-vhosts`) being made group-writable; a root-owned/`root`-group file made `0660`
stays unreadable to the site. It also requires the site to win a tight race during an admin-triggered
operation.

**No clean fix available.** Running the chmod passes as the site user (as A72/A75/A76 do for their analogous
ops) was implemented and **reverted**: `applyOwnership` is also called from the isolation migrator mid-group-
transition, where the site user is not yet in the target group and cannot set the setgid bit, which broke the
migration and its verification. A symlink-safe root chmod would need `openat2(RESOLVE_NO_SYMLINKS)` /
`fchmodat`, which the `find`/`chmod` shell toolchain does not expose. Accepted as a residual with the limited
impact above; revisit if the broker gains an `openat2`-based file helper.

## A78 — Push-to-deploy webhook

New feature (post-audit roadmap). A site with git deploy configured (A41/A53) gains a push-to-deploy webhook
so a `git push` to the deploy branch redeploys automatically.

- **Secret model.** `GitDeploy::configure` generates and persists (in the root-only `deploy.json`) a
  `webhook_secret` (HMAC key) and a public `webhook_token` (the unguessable id in the URL), preserved across
  re-configures; `deploy.webhook.rotate` replaces both. `deploy.config` returns them for the admin-only panel
  to display (the operator pastes the secret into GitHub/GitLab).
- **Unauthenticated, HMAC-gated endpoint.** `POST /hooks/deploy/{token}` is **outside** the `['auth','2fa']`
  group — a webhook has no panel session. It bypasses the IP allowlist (the forge is not the operator),
  idle-timeout and setup gates, and CSRF (external POST), and is throttled. The web controller is a thin
  relay: it caps the body (1 MiB), reads the signature header (GitHub `X-Hub-Signature-256`, GitLab
  `X-Gitlab-Token`), and hands `{token, provider, signature, body}` to the broker. **The secret never reaches
  the web layer** — `GitDeploy::webhook` does the constant-time (`hash_equals`) verification with the secret
  from `deploy.json`, checks the pushed branch equals the configured branch, and launches the deploy out of
  band via `systemd-run` (fast 202). It returns a generic rejection (never revealing whether the token or
  signature failed). The deploy itself still runs as the site identity (A41). Tests cover HMAC accept/forge,
  branch filter, body cap, and token shape.

### A78 erratum — webhook fails closed on an unknown branch; secret shown once

Two commit-review findings on the first A78 cut:
- **Fail-open:** `webhook()` only rejected a *different* branch (`$pushed !== '' && $pushed !== $configured`), so a
  signed payload with no determinable branch — GitHub's `ping`, any non-push event, a malformed ref — skipped the
  check and deployed. Now it deploys only when `$pushed === $configured` (empty/unknown ref ⇒ rejected).
- **Secret exposure / logs:** `config()` (called on every page load) returned `webhook_secret`, spreading it
  through Livewire page snapshots and any logging of the config. The secret is now **show-once**: `config()`
  returns `webhook_configured` + `webhook_token` only; `configure()`/`rotateWebhook()` return the secret the one
  time it is created, and the panel displays it once. `signature` is added to the broker/web audit redaction
  denylist (the webhook body/token/secret were already redacted).

## A79 — Staging clone (`vhost.clone`)

New feature (post-audit roadmap). `vhost.clone <src> <dst>` copies an existing site to a new domain for
staging. Building in increments; **increment 1** covers the vhost and the file tree.

- **Isolated clone, not a shared mount.** The clone is provisioned as its own vhost via `WebServers::addVhost`
  with a fresh `az-vh-<dst>` identity (A49); it does not share files, pool or identity with the source. The
  source site tree is copied with `rsync -a --one-file-system --exclude .git/` (symlinks kept as symlinks, not
  followed), then `VhostUser::ensure` hands the copy to the clone's identity, whose A72 sweep quarantines any
  symlink that escapes the clone's own tree.
- **Same-operator path only.** This is the operator cloning their own site, not the cross-domain restore B6
  forbids. The dst domain is validated and refused if it is read-only/`default`/`azerioid-panel` or already a
  vhost; the source must exist.
- **FPM only for now.** A non-FPM (Octane/PM2/Docker/proxy-with-runtime) source is refused — increment 3 will
  replicate the runtime. Databases are not cloned yet — increment 2.
- **Tree-isolation guards (from commit-review on the first cut).** A vhost root set by hand can sit under the
  target top `/data/www/<dst>` (no vhost is *named* `<dst>`, so the name check misses it) or nested inside the
  source tree. Cloning would then delete-and-own a bystander site's files, or copy a nested site's files into
  the clone. Before provisioning, `vhost.clone` now refuses when `$dstTop` already exists (file or symlink),
  when any vhost's root is `$dstTop` or under it, or when any *other* vhost's root is under `$srcTop`. `--delete`
  was dropped from the rsync (the target is freshly created, nothing to prune). Tests cover replication + file
  copy, same/existing-domain refusal, FPM-only refusal, and the target/source tree-sharing refusals.
