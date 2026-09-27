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
   break the panel unexpectedly.
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
| php.ini | Seeded from the distro FPM php.ini with only `proc_open`/`proc_get_status` removed from `disable_functions`, so every other setting is unchanged for the panel. The distro php.ini gets back the `disable_functions` line from `<ini>.azerioid-panel.bak` (the pre-install copy), if the installer had loosened it. |
| Caddy access | Caddy is no longer the panel, so it gets exactly: its group on `web/` and `/var/lib/azerioid-panel` (mode `0750`), world-read on `web/public`, and the socket group. `.env`, `storage/`, `panel.sqlite` stay unreadable to it. `PanelFileAccess` is re-applied by self-update after its `chown -R`. `/run/php` goes back to `root:root` so the web user cannot replace the panel socket. |
| Order | account → **additive** sudoers (every current holder + new) → php.ini, master conf, pool → files → queue unit, cron, tmpfiles → unit → cut-over (apt: pool leaves the distro master, which is reloaded, then the new master starts) → broker.json/runtime.json → distro php.ini → queue restart → **verify** (unit active; socket `azerioid-panel:<caddy>`; `runuser -u azerioid-panel -- sudo -n broker version.all`; `GET /login` through Caddy returns 200/302; queue active) → sudoers `azerioid-panel` only. Any failure reverts every journalled step, restarts the previous master and queue, removes the account it created, and records the failure. |
| Trigger: existing hosts | The release that first ships this is deployed by the *previous* release's updater, which cannot call it. So the trigger is the panel scheduler: `azerioid:identity-converge` every 5 minutes asks the broker (`panel.identity.converge`), which, when due, starts the migration in its own transient unit (`azerioid-panel-identity.service`) — it restarts the panel FPM master and the queue worker, so it must not run inside either. It waits while any operation is queued/running (rows touched in the last 30 minutes, so a stranded row cannot block it forever) and while a `panel.update.apply` process exists. |
| Trigger: fresh install | `install.sh` installs as before (panel on the web user), then its last step runs the same migrator (`migrate_panel_identity`). A re-run over a migrated host keeps the dedicated layout (installer `PANEL_USER`) and the migrator is then a no-op. |
| Failure | "Fail loudly": the state file `/etc/azerioid-panel/panel-identity.json` (root-only, so the panel cannot clear it) records `failed` with the error and log; automatic retries stop; `azerioid panel identity status` exits non-zero and says so; an operator retries with `azerioid panel identity apply --confirm` (`--dry-run` shows the plan). An attempt that died without rolling back (`running` with nothing running) is reported as interrupted and also waits for an operator. The installer exits non-zero. |
| Downgrade | Self-update refuses to move a migrated host below `v2.0.0`: older updaters would hand the broker grant back to `web_user` while the pool still runs as `azerioid-panel`, leaving the panel with no broker. |
| Uninstall | Removes the unit, master config and php.ini. The account goes only with `--drop-db`, because it owns the retained database. |

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
| Run-as | A job belongs to a vhost and runs as **that vhost's identity** (`az-vh-*`). This is the security win of the phase: a privilege *reduction* for the common case |
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
| Confinement | **No chroot for v1** | `ChrootDirectory` requires the chroot root to be owned by root and not group-writable, which fights A25's ownership model; getting that wrong silently breaks login for one site while working for another. Confinement comes from the account's home and `internal-sftp` |
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
