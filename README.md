# AZERIOID Stack Manager

**One host. One panel. A full Linux web stack you control.**

AZERIOID Stack Manager is a self-contained stack manager and web control panel for operators who run sites on a single Linux server. One installer bootstraps **Caddy**, a dedicated **PHP 8.4 FPM** pool, and a **SQLite**-backed admin UI. Everything else — Nginx, Apache, databases, cache, PHP site runtimes, Node.js, Supervisor — installs on demand from the dashboard or the matching CLI, using official upstream packages.

Built for VPS and bare-metal operators who want a clean bootstrap, a real UI for day-two work, and a privileged broker that keeps package installs and host changes out of the web app process.

## Screenshots

Captured from a live Ubuntu 24.04 panel (UI anonymized where needed).

| Overview | Virtual hosts |
|:---:|:---:|
| ![Overview](docs/screenshots/overview.png) | ![Virtual hosts](docs/screenshots/virtual-hosts.png) |

| Databases | Terminal |
|:---:|:---:|
| ![Databases](docs/screenshots/databases.png) | ![Terminal](docs/screenshots/terminal.png) |

| File Manager + editor | Components |
|:---:|:---:|
| ![File Manager](docs/screenshots/file-manager-editor.png) | ![Components](docs/screenshots/components.png) |

![Security](docs/screenshots/security.png)

## What ships out of the box vs on demand

### Bootstrap (automatic)

| Piece | Role |
|-------|------|
| **Caddy** | Panel vhost on port **3169**; front door on `:80`/`:443` for sites |
| **PHP 8.4 FPM** | Dedicated panel pool — isolated from site PHP versions |
| **SQLite** | Panel state under `/var/lib/azerioid-panel/` |
| **Broker** | Privileged host driver (sudoers); UI never runs `apt`/`dnf` itself |
| **Queue worker** | Background component install/remove jobs |

### On demand (Components page or `azerioid component`)

| Category | Components |
|----------|------------|
| **Web engines** | Nginx, Apache (loopback backends; Caddy terminates TLS) |
| **Databases** | MariaDB, PostgreSQL, MongoDB |
| **Cache / KV** | Redis, Memcached |
| **Runtimes** | PHP 8.1–8.3 (site pools); Node.js 20 / 22 / 24 (runtime only — no PM2 in v1) |
| **Process manager** | Supervisor (programs run as `azerioid-supervised`, never root) |

## Feature highlights

Proven on supported distros in operator testing — not marketing vapor:

- **Multi-engine virtual hosts** — each site chooses Caddy, Apache, or Nginx independently; Caddy always owns `:80`/`:443`
- **HTTPS** — Let's Encrypt HTTP-01 (automatic), self-signed/`tls internal` (extensively used), DNS-01 via Cloudflare/DigitalOcean (supported; lightly tested vs internal TLS)
- **Databases with remote access modes** — localhost / specific IPs / global (with explicit confirmation); MariaDB and PostgreSQL enforce per-database host rules; MongoDB remote access is instance-wide firewall
- **Per-vhost Terminal and File Manager** — broker drops to the vhost user (`az-vh-…`); path traversal and symlink escape rejected
- **Supervisor processes** — create/start/stop/logs from UI or CLI
- **CLI parity** — `azerioid` wraps the same broker path as the dashboard (`origin=cli` in audit)
- **Tag self-update** — `azerioid panel update` installs semver git tags with rollback on failure
- **White-label panel domain** — bind the panel to a hostname on `:443`; IP:3169 and SSH tunnel stay as fallback

**Honest limits (v1):** single admin (no multi-user RBAC yet). Node.js is runtime install only. Archive extract is not offered in the File Manager (zip-slip scoped out).

## Supported operating systems

| OS | Support | Notes |
|----|---------|-------|
| **Ubuntu 24.04** | Supported | Verified |
| **Debian 12 / 13** | Supported | Verified |
| **AlmaLinux 9+** | Supported | Verified with **SELinux Enforcing** |
| **Rocky Linux 9+** | Supported | Verified with **SELinux Enforcing** |
| **RHEL / Oracle Linux 9+** | Supported (same EL path) | Same installer/SELinux helpers |
| **Fedora** | **Not supported** | Installer refuses correctly |

Requirements: root, outbound HTTPS, `git`.

## Quick start

```bash
git clone https://github.com/azerioid/azerioid-stack-manager.git stack-manager && cd stack-manager
chmod +x stack-manager.sh
sudo ./stack-manager.sh
```

**Interactive install** (TTY) prompts for:

- Access mode — `tunnel` (default, `127.0.0.1:3169`) or `public` (HTTPS on the host IP)
- Panel port, optional TOTP requirement, firewall, fail2ban, IP allowlist
- Optional admin creation (email + password)

Non-interactive example:

```bash
sudo ./stack-manager.sh --non-interactive --access=public --create-admin=true --admin-email=you@example.com
# Set ADMIN_PASSWORD in the environment — never pass passwords on argv
```

Tunnel access after a default install:

```bash
ssh -L 3169:127.0.0.1:3169 user@host
# open http://127.0.0.1:3169/setup  (or /login if an admin was created)
```

Optional hardening flags: `--firewall=true`, `--fail2ban=true`, `--require-totp=true`. Full list: `sudo ./stack-manager.sh --help`.

On any **public** install, change the admin password immediately after first login if you used a weak or default value.

## Security model (brief)

- Least-privilege **broker** — privileged work is sudo + broker, not the Laravel process
- **Registry-gated** installs — only components defined under `registry/components/`
- Panel PHP isolated (dedicated FPM pool, locked-down `disable_functions`)
- Localhost-first defaults for panel bind and managed DB/cache
- Optional TOTP, fail2ban jail, ufw/firewalld rules
- DNS-01 API tokens stored root-only (`0600`); never accepted on argv

Details: [`docs/SPEC.md`](docs/SPEC.md), [`docs/DECISIONS.md`](docs/DECISIONS.md), [`docs/port-ownership.md`](docs/port-ownership.md).

## CLI examples

```bash
azerioid status
azerioid vhost list --json
azerioid vhost add --domain=app.example.com --type=php --php=8.4 --root=/data/www/app.example.com --tls=auto
azerioid db add --engine=mariadb --name=appdb --user=appdb
azerioid component install redis
azerioid process create --vhost=app.example.com --command='node server.js' --name=app-node
azerioid panel update check
azerioid panel update apply --confirm
```

Secrets (DB passwords, DNS tokens, backup passphrase, TOTP re-auth) go through the environment or one-time stdout — not argv flags. See `azerioid help`.

## Uninstall

Panel only:

```bash
sudo ./deploy/uninstall.sh --drop-db --remove-bootstrap
```

Full teardown (panel + broker-managed components + added repos; does not delete `/data/www`):

```bash
sudo ./deploy/uninstall.sh --full
```

## License

MIT — see `web/composer.json`.

## Contributing

Issues and pull requests: [azerioid/azerioid-stack-manager](https://github.com/azerioid/azerioid-stack-manager). Architecture decisions live in [`docs/DECISIONS.md`](docs/DECISIONS.md); keep ADR history intact when proposing changes.
