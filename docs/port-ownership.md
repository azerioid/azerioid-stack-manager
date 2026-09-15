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
| **Laravel Octane worker** | `127.0.0.1:34000–34999` | Opt-in per vhost (ADR A35). FrankenPHP under Supervisor; Caddy `reverse_proxy` with the same headers as a `type=proxy` site. One port per Octane vhost. |
| **PM2 (Node) worker** | `127.0.0.1:36000–36999` | Opt-in per vhost (ADR A16/A37). `pm2-runtime` under Supervisor; Caddy `reverse_proxy` like Octane/proxy. One port per PM2 vhost (cluster workers share that listen port via Node cluster). Distinct from ttyd terminals (35000–35999). |
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
8. **Octane worker ports** (34000–34999) are loopback-only and allocated per vhost as the first unused, non-listening port in the range. They are never opened on the host firewall, and the panel's own runtime never uses them — Octane is opt-in for site vhosts only (ADR A35).
8b. **PM2 worker ports** (36000–36999) follow the same loopback-only allocation rules as Octane, in a range **distinct** from Octane (34000–34999) and ttyd terminals (35000–35999) so the runtimes never collide (ADR A37).
9. **Mail ports** (25/465/587/993) are the only managed ports besides 80/443 that are opened to the internet, and only when the opt-in `mail` component is installed. The broker opens them explicitly through `MailFirewall` (ufw or firewalld) at install and closes them at uninstall — never implicitly, and never by leaving the firewall untouched. Plaintext IMAP (143) and POP3 (110/995) are not offered. Port 25 accepts inbound mail but never advertises AUTH; authenticated submission lives on 587/465 only (ADR A36).
10. **Mail conflicts are replaceable, not fatal.** The `mail` registry entry conflicts with `exim4`/`sendmail`, but the operator resolves that by typing `REPLACE-MTA`, after which the broker removes the foreign MTA and installs Postfix. Every other component still hard-fails on conflict.

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
| Postfix SMTP (inbound) | 25 | 0.0.0.0 / :: | tcp | managed (`mail`) |
| Postfix submissions (implicit TLS) | 465 | 0.0.0.0 / :: | tcp | managed (`mail`) |
| Postfix submission (STARTTLS) | 587 | 0.0.0.0 / :: | tcp | managed (`mail`) |
| Dovecot IMAPS | 993 | 0.0.0.0 / :: | tcp | managed (`mail`) |
| Laravel Octane workers | 34000–34999 | 127.0.0.1 | tcp | managed (`azerioid-supervised`, program `octane-<domain>`) |
| Vhost terminals (ttyd) | 35000–35999 | 127.0.0.1 | tcp | managed (per-vhost user) |
| PM2 (Node) workers | 36000–36999 | 127.0.0.1 | tcp | managed (`azerioid-supervised`, program `pm2-<domain>`) |

## EL9 / SELinux

Applies to the whole EL-family gate (`DISTRO_FAMILY=el`): **AlmaLinux**, **Rocky Linux**, **CentOS Stream**, **RHEL**, and **Oracle Linux** 9+ (same `detect-os.sh` / broker `distro_key=el` path; not hardcoded to AlmaLinux).

Port `3169` is labeled `http_port_t` on enforcing hosts via `deploy/lib/selinux.sh`. Backend ports `8081`/`8082` are also labeled `http_port_t` (stock EL policy maps them to `transproxy_port_t` / `us_cli_port_t`, which `httpd_t` cannot bind). The broker reapplies those labels when Apache/Nginx are installed.

## Real-IP note (proxy / Node)

`X-Forwarded-For` is the connecting peer Caddy saw. A curl from the box itself to a `type=proxy` (or any) vhost correctly shows `127.0.0.1` — that is the true client address for a loopback request, not a passthrough bug. External clients still show their real IP (see ADR A24).
