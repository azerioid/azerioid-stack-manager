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

**Optional hardening flags** (off by default): `--firewall=true` (ufw/firewalld panel port), `--fail2ban=true` (panel auth-fail jail), `--require-totp=true` (mandatory 2FA enrollment during setup).

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

- **One site web server per type** on `:80` / `:443`. If the panel’s Caddy instance holds those ports, installing Nginx (or Apache) requires releasing site ports first (`web.release-site-ports` from the Components UI). After release, Caddy serves **only** the panel on `:3169`; there is **no one-click path today to put Caddy back on `:80`/`:443` for site hosting** — site snippets are parked under staging (see `docs/port-ownership.md`).
- **Apache** is in the registry and installable from the UI; **Nginx** is the web-server path exercised most heavily in current smoke testing.
- **Node.js** is runtime install only (no process manager integration).

## Supported operating systems

The installer gates on `deploy/lib/detect-os.sh`:

| OS | Installer support | Verification status |
|----|-------------------|---------------------|
| **Ubuntu 24.04** | Yes | **Fully exercised** — bootstrap, plug-and-play setup/login, P1–P7 smoke chain, uninstall/reinstall |
| **Debian 12** | Yes (same deb code path as Ubuntu) | Implemented; **full end-to-end verification pending** |
| **Alma / Rocky / RHEL / Oracle Linux 9+** | Yes (dnf, Remi PHP, SELinux helpers in `deploy/lib/selinux.sh`) | Implemented; **full end-to-end verification pending** on enforcing SELinux |

Debian **13** is accepted by the OS gate but has not been targeted in smoke tests.

## Security model (summary)

- **Least-privilege broker:** the Laravel UI never runs package managers directly; privileged work goes through the broker binary and sudoers rules.
- **Registry-gated installs:** only components defined in `registry/components/*.json` can be installed; no arbitrary package lists from the UI.
- **Panel isolation:** dedicated PHP 8.4 FPM pool, locked-down `disable_functions`, separate from site PHP versions.
- **Localhost-first:** panel default bind `127.0.0.1:3169`; managed DB/cache defaults to loopback.
- **Auth:** optional TOTP (`--require-totp=true`), rate limiting and account lockout in the app; optional **fail2ban** jail and **ufw**/firewalld panel-port rule via installer flags.
- **SELinux (EL):** installer can label port 3169 and panel paths when enforcing (see `docs/DECISIONS.md` A3).
- **TLS secrets:** DNS provider API tokens for DNS-01 are never accepted on argv; the broker writes them root-only (`0600`) under `/etc/azerioid-panel/dns-credentials/`. Rotate from **Settings** or via CLI env `AZERIOID_DNS_API_TOKEN` — existing tokens are never re-displayed.

Details: `docs/SPEC.md`, `docs/port-ownership.md`, ADR **A21** in `docs/DECISIONS.md`.

### TLS / HTTPS (user vhosts)

| Mode | When to use | Mechanism |
|------|-------------|-----------|
| **Automatic (HTTP-01)** | Real domain already pointed at this host | **Caddy:** native automatic HTTPS. **Apache/Nginx:** `certbot certonly --webroot` (broker owns vhost files — not certbot’s apache/nginx installers). |
| **DNS challenge** | Wildcard, or domain not yet pointed here | certbot DNS plugins (**Cloudflare**, **DigitalOcean**). Cert files wired statically into the active driver. |
| **Self-signed** | Local / IP / non-public names | Driver internal/self-signed TLS (`tls internal` on Caddy). |

Renewal: Caddy renews its own HTTP-01 certs; certbot’s timer plus a deploy-hook reloads Apache/Nginx (or Caddy for DNS-01 static certs). Prefer Let’s Encrypt **staging** (`--staging` / UI checkbox) for repeated tests to avoid rate limits.

Vhost list (UI and `azerioid vhost list --json`) shows issuer type, expiry, and pending/failed — not a bare yes/http.

## Architecture (brief)

```
Browser → Caddy (panel vhost) → PHP 8.4 FPM → Laravel/Livewire UI
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

### Security guarantees (CLI = UI)

- The panel’s own vhost (readonly / managed externally) **cannot** be deleted or edited via CLI — same refusal as the dashboard.
- Supervisor processes created via CLI always run as the dedicated unprivileged supervised user (`azerioid-supervised`), **never root** — same broker rule as the Processes page.

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

### Virtual hosts

| Command | Description |
|---------|-------------|
| `azerioid vhost list [--stack=caddy\|apache\|nginx] [--json]` | List managed vhosts; JSON includes `tls_status` (issuer, expiry, pending/failed) |
| `azerioid vhost add --domain=<d> --type=php\|static\|proxy [--php=<v>] [--root=<path>] [--upstream=<host:port>] [--tls=off\|auto\|internal\|dns01] [--dns-provider=…] [--wildcard] [--staging]` | Create a vhost; optional TLS issuance |
| `azerioid vhost edit --domain=<d> [--php=<v>] [--root=<path>] [--tls=…] [--dns-provider=…] [--wildcard] [--staging]` | Update PHP version, docroot, or TLS mode |
| `azerioid vhost del --domain=<d>` | Delete a vhost (site files left in place) |

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

See [TLS / HTTPS](#tls--https-user-vhosts) above.
### Databases

| Command | Description |
|---------|-------------|
| `azerioid db list [--engine=mariadb\|postgresql\|mongodb] [--json]` | List databases (no passwords) |
| `azerioid db add --engine=<e> --name=<db> [--user=<u>]` | Create DB + user; password printed once |
| `azerioid db del --engine=<e> --name=<db>` | Drop a non-protected database |
| `azerioid db edit --engine=<e> --name=<db> --reset-password` | Generate a new password (revealed once) |

```bash
azerioid db add --engine=postgresql --name=appdb --user=appdb
# Created database appdb (engine=postgresql, user=appdb).
# One-time password (will not be shown again):
# <generated-secret-shown-once>

azerioid db list --engine=postgresql --json
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

### Services

Single named systemd unit only — there is no blanket “restart the whole stack”:

```bash
azerioid service redis-server status
azerioid service redis-server restart
azerioid service redis-server stop
azerioid service redis-server start
```

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

### Audit

```bash
azerioid audit tail --lines=50
azerioid audit tail --follow
azerioid audit tail --lines=20 --json
```

Same panel audit stream the dashboard’s Audit page uses (broker `logs.tail` / `panel-audit`), including CLI-originated actions.

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
