# Port Ownership Matrix (A9)

AZERIOID Stack Manager enforces **one web server instance per type** on a host. The panel always uses a dedicated Caddy vhost snippet — never a second Caddy process.

## Scenarios

| Scenario | Site web server | Panel Caddy | Ports |
|----------|----------------|-------------|-------|
| Fresh bootstrap (P1) | none | panel only | Caddy → `127.0.0.1:3169` |
| User picks Caddy for sites (P5) | same Caddy instance | shared | Caddy → `80`/`443` + panel vhost on `3169` or subdomain |
| User picks Nginx/Apache (P5) | Nginx/Apache | panel Caddy | Nginx/Apache → `80`/`443`; panel Caddy → `3169` only |
| Two web servers detected | warn + alternate ports | — | UI warns; does not hard-block |

## Special rules

1. **Caddy the package is one instance.** Panel vhost is always a snippet in `/etc/caddy/conf.d/azerioid-panel.conf`, never a second `caddy` process or alternate binary.
2. **Panel default bind:** `127.0.0.1:3169` (SSH tunnel: `ssh -L 3169:127.0.0.1:3169 host`).
3. **Public access (optional):** `--access=public` adds HTTPS site block; localhost block remains for tunnel fallback.
4. **Database ports:** managed DBs (MariaDB, PostgreSQL, MongoDB, Redis) bind `127.0.0.1` by default when installed (P3+). Panel SQLite has no network port.
5. **Conflict detection (P2+):** registry `conflicts` array and broker preflight will warn when two components claim the same port.

## Component port registry (planned)

| Component | Port | Bind | Protocol | Owner |
|-----------|------|------|----------|-------|
| Panel Caddy | 3169 | 127.0.0.1 | tcp | system (`caddy`) |
| Caddy (sites) | 80, 443 | 0.0.0.0 / :: | tcp | managed (`caddy`) |
| Nginx | 80, 443 | 0.0.0.0 / :: | tcp | managed (`nginx`) |
| Apache | 80, 443 | 0.0.0.0 / :: | tcp | managed (`apache`/`httpd`) |
| MariaDB | 3306 | 127.0.0.1 | tcp | managed (`mariadb`) |
| PostgreSQL | 5432 | 127.0.0.1 | tcp | managed (`postgresql`) |
| MongoDB | 27017 | 127.0.0.1 | tcp | managed (`mongod`) |
| Redis | 6379 | 127.0.0.1 | tcp | managed (`redis`) |
| Memcached | 11211 | 127.0.0.1 | tcp | managed (`memcached`) |

## EL9 / SELinux

Port `3169` is labeled `http_port_t` on enforcing hosts via `deploy/lib/selinux.sh`.
