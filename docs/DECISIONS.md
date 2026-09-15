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
**Decision:** Terminal and File Manager drop to a dedicated `az-vh-*` user in group `azerioid-vhosts` (same identity for every engine: Caddy / Apache / Nginx). Supervisor programs run as `azerioid-supervised`, never root / `caddy` / `www-data`. Read-only and system vhosts (panel snippet, reverse-proxy) refuse Terminal, Files, and delete/edit. Uninstall `--full` / `--drop-db` must remove these identities; `/usr/local/bin/azerioid` is removed on every uninstall.

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

### Runtime pattern

- **Caddy** `reverse_proxy` → `127.0.0.1:N` with the same forwarding headers as proxy / Octane / PM2.
- Managed comment: `type=proxy root=… runtime=docker docker_port=N docker_internal_port=M` plus image or compose markers so Files/Terminal keep the host build context.
- **Inputs:** (a) pull a pre-built image + internal listen port, or (b) build/run from `Dockerfile` / `docker-compose.yml` the operator placed in the vhost docroot via File Manager.
- **Lifecycle** (start/stop/restart/rebuild/logs) via Supervisor + the rootless CLI; long builds use the existing queue/job path.
- **Disable:** stop/remove the vhost’s container(s), remove the Supervisor program, restore the prior serving mode recorded at enable (static / prior proxy / php) — same restore-meta pattern as PM2. Docker-only sites with no prior mode leave a clearly non-serving / restored static stub rather than inventing a parallel “disabled container” router.
- **Component uninstall:** refuse while any vhost still has `runtime=docker` (typed confirm / drop discipline analogous to Mail’s vhost-scoped tenancy).
- Port range **`37000–37999`** (see `docs/port-ownership.md`), distinct from Octane / ttyd / PM2.
- Install from **Docker’s official apt/dnf repos** (`docker-ce`, `docker-ce-cli`, `containerd.io`, `docker-buildx-plugin`, `docker-compose-plugin`, `docker-ce-rootless-extras`) — not distro `docker.io` as the primary path. After package install: **disable and mask** rootful `docker.service` / `docker.socket`, then configure rootless for `azerioid-supervised` only.

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


