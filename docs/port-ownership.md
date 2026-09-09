# Port Ownership Matrix (A9)

AZERIOID Stack Manager uses **Caddy as the permanent front door** on `:80` / `:443`. Apache and Nginx, when installed, are **internal backend engines** on loopback only. There is no port conflict between web-server components, and Caddy never releases site ports.

The panel always uses a dedicated Caddy vhost snippet — never a second Caddy process.

## Front-router model

| Role | Bind | Notes |
|------|------|--------|
| **Caddy (front door)** | `:80` / `:443` (all interfaces) plus panel on `127.0.0.1:3169` | Terminates TLS for **every** site vhost. Per-vhost engine chooses how content is served. |
| **Caddy engine** | (same process) | `php_fastcgi` / `file_server` directly — no extra hop. |
| **Apache engine** | `127.0.0.1:8081` only | Name-based vhosts on that one port. Caddy `reverse_proxy` with `Host` preserved. Never bound to `:80`/`:443`. |
| **Nginx engine** | `127.0.0.1:8082` only | Same pattern as Apache. |
| **Panel Caddy vhost** | `127.0.0.1:3169` (optional public HTTPS on the host IP) plus optional white-label hostname on `:443` | Unaffected by site-engine choice. A catch-all on `:3169` returns **421** so site vhosts never accidentally match the panel port. |

Installing Apache or Nginx as a component does **not** require releasing `:80`/`:443`. The deprecated `web.release-site-ports` action is a no-op.

**Closed gap:** there is no longer a “release Caddy to Nginx and lose the path back” problem — Caddy never leaves `:80`/`:443`.

## TLS

Caddy terminates TLS for every vhost (native HTTP-01, DNS-01 static `tls <cert> <key>`, `tls internal`, or HTTP-only). Apache and Nginx speak plain HTTP on loopback and do not hold site certificates.

Caddy `reverse_proxy` sets `X-Forwarded-For` / `X-Forwarded-Proto` / `X-Forwarded-Host` from the real client. Apache (`mod_remoteip`) and Nginx (`real_ip`) restore that IP for logs and `REMOTE_ADDR`, trusting **only** `127.0.0.1`/`::1` (Caddy on loopback). `X-Forwarded-Proto=https` is mapped to PHP `HTTPS` / `REQUEST_SCHEME`.

## Special rules

1. **Caddy the package is one instance.** Panel vhost is always a snippet in `/etc/caddy/conf.d/azerioid-panel.conf`, never a second `caddy` process or alternate binary.
2. **Panel default bind:** `127.0.0.1:3169` (SSH tunnel: `ssh -L 3169:127.0.0.1:3169 host`).
3. **Public access (optional):** `--access=public` adds `https://<IP>:3169` (`tls internal`) **and** a catch-all `https://:3169` that returns 421. A lone site on that port would otherwise match **any** Host header (e.g. `let.az:3169`).
4. **White-label panel domain (optional):** `--domain=panel.example.com` (or Settings / `azerioid panel domain set`) adds a **host-specific** `:443` site and issues TLS with the same A21 pipeline as site vhosts. IP:3169 and the tunnel **always** remain as lockout-proof fallback. Changing the domain re-issues for the new name; the old hostname stops matching the panel and its cert is left to expire.
5. **Database ports:** managed DBs (MariaDB, PostgreSQL, MongoDB, Redis) bind `127.0.0.1` by default when installed (P3+). Panel SQLite has no network port. Per-database **remote access** (localhost / specific IPs / global) may re-bind the engine publicly and add tagged firewall allow rules; MongoDB remote access is **instance-wide** (see ADR A23). The table below is the **localhost default**, not a guarantee after access-control changes.
6. **Backend engine ports** (8081/8082) are loopback-only and are **not** opened on the host firewall (explicit deny when ufw/firewalld is active). Defense in depth beyond bind address.
7. **Conflict detection:** registry `conflicts` is for real package clashes (e.g. two database engines), not Caddy vs Nginx on `:80`.

## Component port registry

| Component | Port | Bind | Protocol | Owner |
|-----------|------|------|----------|-------|
| Panel Caddy | 3169 | 127.0.0.1 | tcp | system (`caddy`) |
| Caddy (sites, front door) | 80, 443 | 0.0.0.0 / :: | tcp | managed (`caddy`) |
| Apache (backend) | 8081 | 127.0.0.1 | tcp | managed (`apache`/`httpd`) |
| Nginx (backend) | 8082 | 127.0.0.1 | tcp | managed (`nginx`) |
| MariaDB | 3306 | 127.0.0.1 | tcp | managed (`mariadb`) |
| PostgreSQL | 5432 | 127.0.0.1 | tcp | managed (`postgresql`) |
| MongoDB | 27017 | 127.0.0.1 (default); `0.0.0.0` when any DB is remote | tcp | managed (`mongod`) |
| Redis | 6379 | 127.0.0.1 | tcp | managed (`redis`) |
| Memcached | 11211 | 127.0.0.1 | tcp | managed (`memcached`) |

## EL9 / SELinux

Port `3169` is labeled `http_port_t` on enforcing hosts via `deploy/lib/selinux.sh`. Backend ports `8081`/`8082` are also labeled `http_port_t` (stock EL policy maps them to `transproxy_port_t` / `us_cli_port_t`, which `httpd_t` cannot bind). The broker reapplies those labels when Apache/Nginx are installed.
