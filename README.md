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

### What happens next

1. Open the **setup URL** printed at the end of the install (default panel port **3169**).
2. Complete **`/setup`** in the browser to create the single admin account. **Login does not work until setup finishes** — there are zero users after a fresh install.
3. Sign in at **`/login`** with the credentials you chose.

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

Details: `docs/SPEC.md`, `docs/port-ownership.md`.

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

After install, `/usr/local/bin/azerioid` wraps the same broker actions the dashboard uses:

```bash
azerioid status
azerioid vhost list --json
azerioid db add --engine=postgresql --name=app
azerioid component install redis
azerioid process list
azerioid help
```

Mutating commands are audited like the UI (`origin=cli` in the audit args). Generated DB passwords are printed once and never accepted via argv.

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
