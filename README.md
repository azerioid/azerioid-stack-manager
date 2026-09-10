# AZERIOID Stack Manager

Self-contained Linux stack manager and web control panel. On a supported host with no pre-existing web stack, one installer command bootstraps **Caddy**, **PHP 8.4 FPM**, and a **SQLite**-backed admin UI. Everything else — web servers, databases, cache, and extra runtimes — is installed on demand from the **Components** page using packages from official upstream repositories (Caddy, Sury/Remi, MongoDB, NodeSource, distro packages). **No third-party base stack is required** — you do not need LCMP, LAMP, or any external install script before running this project.

For operators who want a single host they can bootstrap from scratch and then extend through a dashboard, without shelling into `apt`/`dnf` for every component.

## Quick start

**Requirements:** root on a [supported OS](#supported-operating-systems), outbound HTTPS, `git`.

```bash
git clone https://github.com/azerioid/azerioid-stack-manager.git stack-manager && cd stack-manager
chmod +x stack-manager.sh
sudo ./stack-manager.sh --non-interactive
```

The installer waits until the panel HTTP endpoint, FPM socket, and queue worker are actually ready before printing success.

### Default credentials

If you create an admin during install and accept the prompts’ defaults (or use non-interactive `--create-admin=true` without overriding email/password), the account is:

| | |
|--|--|
| **Email** | `admin@example.com` |
| **Password** | `password` |

That default is fine for **local / tunnel-only** use.

**⚠ SECURITY:** On any **public-mode** install (`--access=public`), this default password **must be changed immediately after first login** (Settings → Password). The installer prints the same warning at the end of a public install when the default password is in use. Do not leave a public panel on the documented default.

To set a custom email/password at install time instead, use the interactive prompts or the installer flags (`--create-admin=true`, `--admin-email=…`, and `ADMIN_PASSWORD` via environment — see `sudo ./stack-manager.sh --help`).

### What happens next

1. Open the URL printed at the end of the install (default panel port **3169**).
2. If an admin was created during install, sign in at **`/login`** with those credentials (defaults above unless you chose otherwise).
3. If no admin was created, complete **`/setup`** in the browser first — login does not work until an admin exists.

**Default access mode is `tunnel`:** the panel listens on `http://127.0.0.1:3169`. Reach it remotely with an SSH tunnel:

```bash
ssh -L 3169:127.0.0.1:3169 user@host
# then open http://127.0.0.1:3169/setup
```

**Public access (optional):** re-run or install with `--access=public` to add an HTTPS vhost on the host’s detected public IP (internal/self-signed TLS). The localhost tunnel block remains as a fallback. Example:

```bash
sudo ./stack-manager.sh --non-interactive --access=public
```

**Optional hardening flags** (off by default): `--firewall=true` (ufw/firewalld: panel port **plus 80/443** so public sites stay reachable), `--fail2ban=true` (panel auth-fail jail), `--require-totp=true` (mandatory 2FA enrollment during setup).

Run `sudo ./stack-manager.sh --help` (via `deploy/install.sh --help`) for all installer options.

## What gets installed when

### At bootstrap (automatic)

| Piece | Role |
|-------|------|
| **Caddy** | Panel web server (vhost snippet on port **3169**) |
| **PHP 8.4 FPM** | Dedicated panel pool (`/run/php/azerioid-panel.sock`) — not shared with site vhosts |
| **SQLite** | Panel state at `/var/lib/azerioid-panel/panel.sqlite` |
| **Broker** | Privileged host driver (`/usr/local/lib/azerioid-panel/broker`, invoked via sudo) |
| **Queue worker** | `azerioid-panel-queue.service` for background component jobs |

Bootstrap packages are recorded in `/etc/azerioid-panel/bootstrap.json` for selective removal during uninstall.

### On demand (Components page)

Install, adopt, or remove these from the dashboard (queued broker jobs; packages come from registry metadata, not arbitrary shell):

| Category | Components |
|----------|------------|
| **Web servers** | Nginx, Apache httpd |
| **Databases** | MariaDB, PostgreSQL, MongoDB |
| **Cache / KV** | Redis, Memcached |
| **Runtimes** | PHP 8.1, 8.2, 8.3 (site pools); Node.js 20 / 22 / 24 (runtime only — no PM2 in v1) |

**System components (always present after bootstrap, not removable):** Caddy (panel instance), PHP 8.4 (panel runtime).

Managed databases and cache services are provisioned to bind **localhost** by default when installed through the broker.

### Known limitations

- **Apache and Nginx** are optional backend engines. Caddy always owns `:80`/`:443`; each vhost chooses Caddy, Apache, or Nginx independently (see [Architecture](#architecture-brief) and `docs/port-ownership.md`).
- **Node.js** is runtime install only (no process manager integration).

## Supported operating systems

The installer gates on `deploy/lib/detect-os.sh`:

| OS | Installer support | Verification status |
|----|-------------------|---------------------|
| **Ubuntu 24.04** | Yes | **Re-verified 2026-09-08** on 201.79.10.81. Uninstall `--full` was **not** run there (only remaining Ubuntu proof box). |
| **Debian 12** | Yes (deb code path) | **Still not verified** — this pass used Debian **13**, not 12. |
| **Debian 13** | Yes (`detect-os.sh` accepts 12 and 13) | **Verified 2026-09-08** on 165.227.82.79 — full chain + live `uninstall.sh --full` + re-bootstrap. SELinux N/A. |
| **Alma / Rocky / RHEL / Oracle Linux 9+** | Yes (dnf, Remi PHP, SELinux helpers in `deploy/lib/selinux.sh`) | **Not verified** — no EL9 host; SELinux enforcing e2e remains open. |

## Security model (summary)

- **Least-privilege broker:** the Laravel UI never runs package managers directly; privileged work goes through the broker binary and sudoers rules.
- **Registry-gated installs:** only components defined in `registry/components/*.json` can be installed; no arbitrary package lists from the UI.
- **Panel isolation:** dedicated PHP 8.4 FPM pool, locked-down `disable_functions`, separate from site PHP versions.
- **Localhost-first:** panel default bind `127.0.0.1:3169`; managed DB/cache defaults to loopback. Per-database remote access (localhost / specific IPs / global) is explicit and audited — see [Databases](#databases). Optional white-label hostname on `:443` (Settings / `azerioid panel domain set`); IP:3169 and the tunnel always stay as fallback. Unrelated site Host headers on `:3169` get **421**.
- **Auth:** optional TOTP (`--require-totp=true`), rate limiting and account lockout in the app; optional **fail2ban** jail and **ufw**/firewalld rules (panel port **and** HTTP/HTTPS 80/443) via installer flags.
- **Hung PHP:** site FPM pools terminate a request after **30s** (`request_terminate_timeout` + `max_execution_time`). Reverse-proxy read timeouts are **35s**. A single infinite loop or `sleep()` cannot take a site down indefinitely.
- **SELinux (EL):** installer can label port 3169 and panel paths when enforcing (see `docs/DECISIONS.md` A3).
- **TLS secrets:** DNS provider API tokens for DNS-01 are never accepted on argv; the broker writes them root-only (`0600`) under `/etc/azerioid-panel/dns-credentials/`. Rotate from **Settings** or via CLI env `AZERIOID_DNS_API_TOKEN` — existing tokens are never re-displayed.

Details: `docs/SPEC.md`, `docs/port-ownership.md`, ADR **A21**–**A25** in `docs/DECISIONS.md`.

### TLS / HTTPS (user vhosts)

| Mode | When to use | Mechanism |
|------|-------------|-----------|
| **Automatic (HTTP-01)** | Real domain already pointed at this host | Native Caddy automatic HTTPS on the front door (every vhost, regardless of engine). |
| **DNS challenge** | Wildcard, or domain not yet pointed here | certbot DNS plugins (**Cloudflare**, **DigitalOcean**). Cert files wired as static `tls <cert> <key>` on **Caddy**. |
| **Self-signed** | Local / IP / non-public names | Caddy `tls internal`. |

Renewal: Caddy renews its own HTTP-01 certs; certbot’s timer plus a deploy-hook reloads Caddy for DNS-01 static certs. Prefer Let’s Encrypt **staging** (`--staging` / UI checkbox) for repeated tests to avoid rate limits.

Vhost list (UI and `azerioid vhost list --json`) shows issuer type, expiry, and pending/failed — not a bare yes/http.

## Architecture (brief)

```
Browser → Caddy :80/:443 (TLS) ─┬─ engine=caddy  → php_fastcgi / file_server
                                ├─ engine=apache → reverse_proxy 127.0.0.1:8081
                                └─ engine=nginx  → reverse_proxy 127.0.0.1:8082

Browser → Caddy (panel vhost :3169, IP fallback + 421 catch-all)
         → optional white-label :443 → PHP 8.4 FPM → Laravel/Livewire UI
                                      ↓ sudo
                                 broker.php → registry + systemd + apt/dnf
```

- **Registry** (`registry/components/`) — component metadata, conflicts, ports, per-distro packages.
- **Panel DB** — SQLite for users, settings, jobs, component operations (not your site databases).
- **Site data** — default web root `/data/www` (configurable); uninstall does not delete site trees.

Deep dive: [`docs/SPEC.md`](docs/SPEC.md), [`docs/DECISIONS.md`](docs/DECISIONS.md).

## Paths

| Path | Purpose |
|------|---------|
| `stack-manager.sh` | Bootstrap entrypoint |
| `/usr/local/lib/azerioid-panel` | Installed panel (broker, web, registry) |
| `/etc/azerioid-panel/` | `broker.json`, `runtime.json`, `bootstrap.json`, `access.env` |
| `/var/lib/azerioid-panel/` | SQLite DB, staging, managed-component manifest |

## Uninstall

Panel artifacts only:

```bash
sudo ./deploy/uninstall.sh --drop-db --remove-bootstrap
```

Full teardown (panel + broker-managed components + added repos; still skips `/data/www`):

```bash
sudo ./deploy/uninstall.sh --full
```

`--full` / `--drop-db` also removes `/usr/local/bin/azerioid`, per-vhost `az-vh-*` users, `azerioid-supervised`, DNS-01 credential files, and Apache/Nginx front-router drop-ins. **Live `--full` proof on a throwaway host is still outstanding** — do not treat the Ubuntu 201.79.10.81 box as an uninstall target.

## CLI (`azerioid`)

`azerioid` is the system-wide operator CLI for AZERIOID Stack Manager. Bootstrap installs it to `/usr/local/bin/azerioid` automatically — no extra step after `stack-manager.sh`.

It is a **thin wrapper** over the same privileged **broker** path the dashboard uses. Every mutating command goes through the same validate → apply → rollback and audit trail as the UI (`origin=cli` in audit args), so the terminal is not a second, looser control plane.

```bash
azerioid help
azerioid status
azerioid vhost list --json
```

### JSON output

Read commands accept **`--json`** for scripting/CI: `status`, `version`, `vhost list`, `db list`, `component list`, `process list`, and `audit tail`. Human-readable tables are the default when `--json` is omitted.

### Secrets

Generated database passwords are printed **once** in the command’s stdout at create/reset time, with an explicit “will not be shown again” warning. `db list` never prints passwords. The CLI **never** accepts a plaintext password as an argv flag (same reasoning as installer secrets via env, not process list). Reset always generates a new value via `--reset-password`; there is no `--password=` option.

DNS-01 provider API tokens use the same rule: set `AZERIOID_DNS_API_TOKEN` (or `DNS_API_TOKEN`) in the environment before `vhost add`/`edit` with `--tls=dns`. Tokens are stored by the broker and never echoed back.

Backup passphrase: saved via the Backups UI, or set `AZERIOID_BACKUP_PASSPHRASE` for CLI create/restore. TOTP re-auth: `AZERIOID_ADMIN_PASSWORD` and `AZERIOID_TOTP_CODE`. None of these may be passed as argv flags.

### Security guarantees (CLI = UI)

- The panel’s own vhost (readonly / managed externally) **cannot** be deleted or edited via CLI — same refusal as the dashboard.
- Supervisor processes created via CLI always run as the dedicated unprivileged supervised user (`azerioid-supervised`), **never root** — same broker rule as the Processes page.
- Per-vhost **Terminal** and **File Manager** are refused for read-only/system vhosts (panel, reverse-proxy) — broker-enforced, not just hidden in the UI.

Deeper rationale: [`docs/SPEC.md`](docs/SPEC.md), [`docs/DECISIONS.md`](docs/DECISIONS.md).

### Status / version

| Command | Description |
|---------|-------------|
| `azerioid status [--json]` | Panel runtime plus controlled/observed services and component overview |
| `azerioid version [--json]` | Panel version file plus installed stack/component versions |

```bash
azerioid status
azerioid version
# AZERIOID Stack Manager 0.2.0
azerioid version --json
```

### Services

| Command | Description |
|---------|-------------|
| `azerioid service <unit> start\|stop\|restart\|status [--json]` | Broker-mediated systemd control (same path as the Services page) |

```bash
azerioid service caddy status --json
azerioid service redis-server restart
```

### Virtual hosts

| Command | Description |
|---------|-------------|
| `azerioid vhost list [--engine=caddy\|apache\|nginx] [--json]` | List managed vhosts; JSON includes `tls_status` (issuer, expiry, pending/failed). `--stack=` is an alias of `--engine=` |
| `azerioid vhost add --domain=<d> --type=php\|static\|proxy [--php=<v>] [--root=<path>] [--upstream=<host:port>] [--engine=caddy\|apache\|nginx] [--tls=off\|auto\|internal\|dns01] [--dns-provider=…] [--wildcard] [--staging]` | Create a vhost; optional TLS issuance |
| `azerioid vhost edit --domain=<d> [--php=<v>] [--root=<path>] [--engine=caddy\|apache\|nginx] [--tls=…] [--dns-provider=…] [--wildcard] [--staging]` | Update PHP version, docroot, engine, or TLS mode |
| `azerioid vhost del --domain=<d>` | Delete a vhost (site files left in place) |
| `azerioid vhost files list --domain=<d> [--path=<rel>] [--json]` | List a vhost directory (broker-mediated; same containment as the UI) |
| `azerioid vhost files read --domain=<d> --path=<rel>` | Print file content (stdin/stdout; traversal/symlink escape rejected) |
| `azerioid vhost files write --domain=<d> --path=<rel>` | Write file from **stdin** (not argv) |
| `azerioid vhost files mkdir --domain=<d> --path=<rel>` | Create a directory |
| `azerioid vhost files rename --domain=<d> --path=<rel> --dest=<rel>` | Rename/move inside the vhost |
| `azerioid vhost files delete --domain=<d> --path=<rel> [--recursive]` | Delete a file or empty (or recursive) directory |

**Upload/download is UI-only.** Binary file transfer does not map cleanly onto a single flag-based CLI invocation without extra plumbing (chunking, progress, content-type). Use the Files page, or `write`/`read` for text/scriptable payloads. There is **no** archive extract in v1 (zip-slip is scoped out rather than a naive unzip).

`--tls` alone means `auto`. Canonical modes match the broker: `off`, `auto`, `internal`, `dns01`. Aliases: `dns`→`dns01`, `self`→`internal`, `on`→`auto`. For `--tls=dns01`, pass `--dns-provider=cloudflare|digitalocean` and set `AZERIOID_DNS_API_TOKEN` (or `DNS_API_TOKEN`) in the environment — never argv. Use `--staging` against Let’s Encrypt staging during tests.

```bash
azerioid vhost list --json
# {"vhosts":[{"domain":"example.com","tls_status":{"issuer_type":"lets_encrypt","label":"Let's Encrypt (HTTP-01) · exp …",…},…}, …]}

azerioid vhost add --domain=app.example.com --type=php --php=8.4 --root=/data/www/app.example.com --tls=auto
azerioid vhost edit --domain=app.example.com --tls=internal
azerioid vhost edit --domain=app.example.com --tls=off

# DNS-01 (token via env only):
export AZERIOID_DNS_API_TOKEN='…'
azerioid vhost add --domain=prepoint.example.com --type=static --tls=dns01 --dns-provider=cloudflare --staging
azerioid vhost del --domain=app.example.com

# Panel / readonly vhost is refused:
azerioid vhost del --domain=127.0.0.1:3169
# This vhost is managed externally and cannot be deleted by the panel.
```

### Panel domain (white-label)

| Command | Description |
|---------|-------------|
| `azerioid panel domain show [--json]` | Current hostname, `APP_URL`, TLS status, IP/tunnel fallback URLs |
| `azerioid panel domain set --domain=<d> [--tls=auto\|internal\|dns01] [--dns-provider=…] [--staging]` | Bind the panel to a hostname on **:443** (Let's Encrypt via A21). IP:3169 and the SSH tunnel stay up. |
| `azerioid panel domain clear` | Drop the hostname; continue on IP/tunnel. Old cert left to expire. |

Refused if `<d>` is already a site vhost. Switching names re-issues for the new domain; the previous hostname no longer serves the panel.

```bash
azerioid panel domain show
azerioid panel domain set --domain=panel.example.com --tls=auto
azerioid panel domain clear
```

See [TLS / HTTPS](#tls--https-user-vhosts) above.

### Per-vhost Terminal and File Manager

Eligible vhosts (not the panel’s own vhost, not reverse-proxy/read-only sites) get **Terminal** and **Files** actions on the Virtual hosts page.

Both share the **same privilege boundary**: the Laravel/PHP-FPM process never opens files under `/data/www`. Every operation goes through the **broker**, which drops to that vhost’s dedicated unprivileged user (`az-vh-…`) before touching the filesystem. An operator already has a scoped shell via Terminal; the File Manager is the same boundary with a friendlier UI.

Defenses (summary — see [`docs/SPEC.md`](docs/SPEC.md) for the full path-resolution rules):

- **Traversal:** every path is lexically joined (reject `..` that climbs above the vhost root, reject absolute paths), then **`realpath()`**’d. The resolved location must stay under the vhost root.
- **Symlink escape:** a link whose real target is outside the vhost is rejected on read/write. The File Manager **does not create symlinks**. Escaped links can be unlinked (the link inode only — never the outside target).
- **Zip-slip:** archive extraction is **not implemented** in v1.
- **Uploads** land owned by the vhost user; PHP/executables are allowed (same threat model as Terminal). Default per-request cap is **20 MiB** (`vhost_files_max_bytes` in `broker.json`).

```bash
azerioid vhost files list --domain=app.example.com --json
azerioid vhost files read --domain=app.example.com --path=index.php
printf '%s' '<?php echo "ok";' | azerioid vhost files write --domain=app.example.com --path=index.php
```

### Databases

| Command | Description |
|---------|-------------|
| `azerioid db list [--engine=mariadb\|postgresql\|mongodb] [--json]` | List databases (no passwords); includes the current remote-access mode |
| `azerioid db add --engine=<e> --name=<db> [--user=<u>]` | Create DB + user; password printed once |
| `azerioid db del --engine=<e> --name=<db>` | Drop a non-protected database |
| `azerioid db edit --engine=<e> --name=<db> --reset-password` | Generate a new password (revealed once) |
| `azerioid db access show --engine=<e> --name=<db> [--json]` | Show remote-access mode, IPs, and enforcement layer |
| `azerioid db access set --engine=<e> --name=<db> --mode=localhost\|specific\|global [--ip=<ip>[,<ip>...]] [--confirm]` | Set remote access. `--mode=global` **requires** `--confirm` |

Remote access has three modes, default **Localhost only**:

| Mode | MariaDB | PostgreSQL | MongoDB |
|------|---------|------------|---------|
| **Localhost only** | User remains `'user'@'localhost'` / `'127.0.0.1'` | No remote `pg_hba.conf` lines for that database | Port 27017 stays loopback + no remote firewall rule **unless another database on this instance is remote** |
| **Specific IP(s)** | New `'user'@'<ip>'` (or MariaDB `/8,/16,/24` wildcards) granted **before** old remote hosts are dropped. The engine itself refuses other client IPs. | `host "<db>" "<user>" <cidr> scram-sha-256` in `pg_hba.conf` for **that database only**, then `systemctl reload postgresql` | Firewall allow-from those IPs on **port 27017 for the whole mongod**. Not per-database — MongoDB has no host-pattern auth. |
| **Global (any IP)** | `'user'@'%'` plus the engine port reachable from any IPv4 | `host … 0.0.0.0/0` for that database | Port 27017 allowed from any IPv4 (instance-wide) |

**Two layers (do not conflate them):**

1. **Network reachability** — `bind-address` / `listen_addresses` / `bindIp` plus tagged ufw (or firewalld) rules on 3306 / 5432 / 27017. Opening remote access for **any** database on an engine binds that engine publicly and opens the port for the **union** of specific IPs, or fully open if any database is Global. Closing the last remote database returns bind + firewall to localhost. Activating ufw for this layer **always keeps 80/443 open** so public sites stay reachable.
2. **Engine-level auth** — genuine per-database host restriction on **MariaDB** (GRANT host) and **PostgreSQL** (`pg_hba.conf`). **MongoDB has no equivalent:** user/role auth is not host-based, and one `mongod` serves every database on one port. Specific IP / Global for MongoDB is instance-wide firewall control only. If two Mongo databases request different IP scopes, the firewall uses the union (or Global if any database is Global); the UI labels that conflict instead of pretending isolation.

**Global confirmation:** the UI requires typing the database name **and** an “I understand” checkbox. The CLI requires `--confirm` in addition to `--mode=global`. The broker rejects Global without confirm phrase `OPEN-GLOBAL`. IP/CIDR input is validated (`IPv4` or `IPv4/prefix`) before it reaches `GRANT`, `pg_hba.conf`, or firewall commands.

```bash
azerioid db add --engine=postgresql --name=appdb --user=appdb
# Created database appdb (engine=postgresql, user=appdb).
# One-time password (will not be shown again):
# <generated-secret-shown-once>

azerioid db list --engine=postgresql --json
azerioid db access set --engine=postgresql --name=appdb --mode=specific --ip=203.0.113.5
azerioid db access set --engine=postgresql --name=appdb --mode=global --confirm
azerioid db access set --engine=postgresql --name=appdb --mode=localhost
azerioid db edit --engine=postgresql --name=appdb --user=appdb --reset-password
azerioid db del --engine=postgresql --name=appdb
```

### Components

| Command | Description |
|---------|-------------|
| `azerioid component list [--json]` | Registry components and install status |
| `azerioid component install <id> [--version=<v>] [--option=key=value]...` | Install a registry-gated component |
| `azerioid component remove <id>` | Uninstall a panel-managed component (system components refused) |

```bash
azerioid component list --json
azerioid component install redis
azerioid component install not-a-real-component
# Unknown component id.
azerioid component remove redis
```

(`--version=` on the shell wrapper maps to Artisan `--pkg-version=`; Symfony reserves `--version` for the framework.)

There is no blanket “restart the whole stack” — `azerioid service` takes a single systemd unit.

### Processes (Supervisor)

| Command | Description |
|---------|-------------|
| `azerioid process list [--json]` | List managed Supervisor programs |
| `azerioid process create --command=<cmd> (--vhost=<domain>\|--freeform) [--name=<n>] [--directory=<path>]` | Create a program (runs as `azerioid-supervised`) |
| `azerioid process start\|stop\|restart\|del <name>` | Control or delete a program |
| `azerioid process logs <name> [--follow] [--lines=100]` | Stdout/stderr from the same broker log source as the UI |

```bash
azerioid process create --vhost=app.example.com --command='node server.js' --name=app-node
# Created process app-node.
#   runs as user: azerioid-supervised

azerioid process list --json
azerioid process logs app-node --lines=50
azerioid process restart app-node
azerioid process del app-node
```


### Panel self-update

Updates the stack manager itself (not OS packages) from **semver git tags** (`vX.Y.Z`). Omitting `--v` installs the **latest tag by real semver comparison** (not lexical order, not `main` HEAD). `--v <tag>` pins to that tag (including intentional downgrades).

The live install under `/usr/local/lib/azerioid-panel` is **not** a git working tree. Markers: `COMMIT` + `TAG`. The broker keeps a managed checkout at `/var/lib/azerioid-panel/src`, checks out the target tag, then re-deploys (rsync + `composer install --no-dev` + `artisan migrate` + cache rebuild + PHP-FPM reload). Queue worker restart is deferred a few seconds so the background job can finish recording status.

| Command | What it does |
|---------|----------------|
| `azerioid panel update check [--json]` | Fetch tags, show deployed/latest tag, available tags, oneline summary |
| `azerioid panel update apply --confirm` | Queue apply to **latest** semver tag. **Requires `--confirm`** |
| `azerioid panel update apply --v=v0.2.1 --confirm` | Queue apply (or downgrade) to a specific tag |

Guarantees:
- Dirty source working tree → apply refused (no silent discard of local changes).
- Unknown `--v` tag → refused with a clear error (and a close-match suggestion when possible).
- Downgrade via `--v` is allowed, with an explicit warning that newer migrations are not auto-reversed.
- Any failure after the pre-update commit/tag is recorded → check out prior commit, redeploy, leave the panel on last-known-good.
- Registry-only updates (components JSON without panel code) are out of scope for now.

```bash
azerioid panel update check
azerioid panel update check --json
azerioid panel update apply            # refused without --confirm
azerioid panel update apply --confirm
azerioid panel update apply --v=v0.2.1 --confirm
```

### OS package updates


Pending host OS packages (apt or dnf) — **not** panel self-update. Same broker actions as the Updates page.

| Command | Description |
|---------|-------------|
| `azerioid updates check [--json]` | Pending count, security count, package manager, sample package list |
| `azerioid updates apply --confirm [--security]` | Apply all pending OS updates, or security-only with `--security`. **Requires `--confirm`** (maps to `APPLY-ALL` / `APPLY-SECURITY`) |

```bash
azerioid updates check
azerioid updates check --json
# Refused without confirm:
azerioid updates apply
# Security-only (unattended-upgrade / dnf update --security):
azerioid updates apply --security --confirm
```

### Backups (local / Spaces)

Encrypted archives via the same `backup.*` broker path as the Backups page. Default destination is **local** (`/var/lib/azerioid-panel/backups/`). Passphrase comes from the saved Backups UI secret or `AZERIOID_BACKUP_PASSPHRASE` — never argv.

| Command | Description |
|---------|-------------|
| `azerioid backup create [--local|--spaces] [--db=<name|all>] [--keep=N]` | Run `mysqldump`, encrypt, store locally (default) or upload to Spaces; local mode prunes to keep-last-N |
| `azerioid backup list [--local|--spaces] [--json]` | List local files or Spaces objects |
| `azerioid backup restore --file=<path|key> --target=<db> --confirm [--overwrite] [--local|--spaces]` | Restore into a DB name. **Requires `--confirm`**. `--overwrite` sends broker `OVERWRITE` |

```bash
azerioid backup create --local --db=azerioid_proof_src --keep=2
azerioid backup list --local --json
azerioid backup restore --file=/var/lib/azerioid-panel/backups/db/azerioid_proof_src/….bin \
  --target=azerioid_restore_tmp --confirm
```

### TOTP (admin 2FA)

Panel-app controls (same `TotpService` / Settings policy — not broker). Re-auth uses env secrets only.

| Command | Description |
|---------|-------------|
| `azerioid totp status [--email=] [--json]` | Enrollment status and `PANEL_REQUIRE_TOTP` |
| `azerioid totp disable --email=<admin>` | Disable TOTP (blocked when `PANEL_REQUIRE_TOTP=true`) |
| `azerioid totp reset --email=<admin>` | New secret (printed once, unconfirmed) |
| `azerioid totp confirm --email=<admin>` | Confirm pending secret with a code from the **new** authenticator |

```bash
export AZERIOID_ADMIN_PASSWORD='…'   # never argv
export AZERIOID_TOTP_CODE='123456'   # current code when enrolled
azerioid totp disable --email=admin@example.com
azerioid totp reset --email=admin@example.com
# Then set AZERIOID_TOTP_CODE from the NEW secret and:
azerioid totp confirm --email=admin@example.com
```

### Log rotation

Panel logs under `/var/log/azerioid-panel/*.log` are rotated by **system `logrotate`** (`/etc/logrotate.d/azerioid-panel`, installed at bootstrap). There is **no** `azerioid logs rotate` command: the Updates/Backups/Settings UI has no force-rotate action either, and wrapping `logrotate -f` would be a second control plane outside the broker. Operators who need an on-demand rotation use `logrotate -f /etc/logrotate.d/azerioid-panel` as root.

### Audit

```bash
azerioid audit tail --lines=50
azerioid audit tail --follow
azerioid audit tail --lines=20 --json
```

Same panel audit stream the dashboard’s Audit page uses (broker `logs.tail` / `panel-audit`), including CLI-originated actions.

### Not in the `azerioid` wrapper (UI / broker only)

These broker actions exist in the panel but have **no** `azerioid` subcommand. This pass confirmed the gap against live `azerioid help` on 201.79.10.81:

| Area | Broker actions | Why CLI-omitted (v1) |
|------|----------------|----------------------|
| Terminal | `terminal.session.*` | Needs a PTY + websocket; File Manager CLI covers the same Unix identity |
| Logs search/tail | `logs.tail`, `logs.search` | Dashboard Logs page (`azerioid audit` covers panel-audit) |
| Log rotation | n/a (system logrotate) | Installed at bootstrap; no UI force-rotate either — use `logrotate -f` as root if needed |
| PHP | `php.versions`, `php.ini.*`, `php.opcache.*` | Settings / PHP pages |
| Firewall / fail2ban | `firewall.*` | Settings |
| Cron | `cron.*` | Settings |
| Metrics | `metrics.system` | Dashboard |
| TLS DNS credentials | `tls.dns-credential.*` | Settings / env `AZERIOID_DNS_API_TOKEN` |
| MariaDB bind helper | `mariadb.bind.*` | Internal / Settings |
| Spaces-only schedule | `azerioid:backup-scheduled` | Cron/scheduler; interactive create/list/restore are under `azerioid backup` |

## Smoke tests

After install on a VM:

```bash
sudo ./deploy/test/smoke-p1.sh    # bootstrap
sudo ./deploy/test/smoke-p2.sh    # registry / broker list
# … smoke-p3.sh through smoke-p6.sh for component and adopt checks
```

UI setup + login check (HTTP/Livewire, no artisan):

```bash
python3 deploy/test/ui-flow-verify.py
```

## License

MIT (see `web/composer.json`).
