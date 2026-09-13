#!/usr/bin/env bash
# Reverse AZERIOID Stack Manager bootstrap. Never touches /data/www or user site data by default.
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "uninstall.sh must run as root" >&2
    exit 1
fi

PREFIX="${PREFIX:-/usr/local/lib/azerioid-panel}"
PANEL_PHP_VERSION="${PANEL_PHP_VERSION:-8.4}"
UNINSTALL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "${UNINSTALL_DIR}/lib/os-paths.sh" ]]; then
    # shellcheck source=lib/os-paths.sh
    source "${UNINSTALL_DIR}/lib/os-paths.sh"
fi
MANAGED_MANIFEST="/var/lib/azerioid-panel/managed-components.json"
DROP_DB=0
REMOVE_BOOTSTRAP=0
PURGE_MANAGED=0
PURGE_REPOS=0
PURGE_PACKAGE_DATA=0

usage() {
    cat <<'EOF'
Usage: uninstall.sh [options]

  --drop-db              Remove panel SQLite and /etc/azerioid-panel secrets
  --remove-bootstrap     Remove Caddy/PHP only if bootstrap.json says installer added them
  --purge-managed        Uninstall broker-managed components (Redis, MariaDB, Nginx, …)
  --purge-repos          Remove panel-added apt/yum repo entries and keyrings
  --purge-package-data   Remove engine data dirs (/var/lib/mysql, mongodb, postgresql)
  --full                 --drop-db --remove-bootstrap --purge-managed --purge-repos --purge-package-data

  /data/www and customer site databases are never modified.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --drop-db) DROP_DB=1; shift ;;
        --remove-bootstrap) REMOVE_BOOTSTRAP=1; shift ;;
        --purge-managed) PURGE_MANAGED=1; shift ;;
        --purge-repos) PURGE_REPOS=1; shift ;;
        --purge-package-data) PURGE_PACKAGE_DATA=1; shift ;;
        --full)
            DROP_DB=1
            REMOVE_BOOTSTRAP=1
            PURGE_MANAGED=1
            PURGE_REPOS=1
            PURGE_PACKAGE_DATA=1
            shift
            ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
    esac
done

purge_managed_components() {
    local broker="${PREFIX}/broker"
    [[ -x "${broker}" ]] || { echo "Warning: broker not found; skipping --purge-managed" >&2; return 0; }
    [[ -f "${MANAGED_MANIFEST}" ]] || return 0

    local ids
    ids="$(python3 - "${MANAGED_MANIFEST}" <<'PY'
import json, pathlib, sys
data = json.loads(pathlib.Path(sys.argv[1]).read_text())
comps = data.get("components", {})
ids = []
if isinstance(comps, dict):
    ids = list(comps.keys())
elif isinstance(comps, list):
    for item in comps:
        if isinstance(item, str) and item.strip():
            ids.append(item.strip())
        elif isinstance(item, dict):
            cid = item.get("id") or item.get("component_id")
            if isinstance(cid, str) and cid.strip():
                ids.append(cid.strip())
for cid in sorted(ids, reverse=True):
    print(cid)
PY
)" || return 0

    local id op n=0
    while IFS= read -r id; do
        [[ -n "${id}" ]] || continue
        op="uninstall-$(echo "${id}" | tr -c 'a-zA-Z0-9_' '_')"
        echo "==> Uninstalling managed component: ${id}"
        if echo "{\"operation_id\":\"${op}\"}" | "${broker}" component.uninstall "${id}" >/dev/null 2>&1; then
            echo "    removed ${id}"
        else
            echo "    warning: broker could not uninstall ${id} (may already be gone)" >&2
        fi
        n=$((n + 1))
    done <<< "${ids}"
    [[ "${n}" -gt 0 ]] || echo "==> No managed components recorded"
    rm -f "${MANAGED_MANIFEST}"
}

purge_panel_repos() {
    echo "==> Removing panel-added package repositories"
    rm -f /etc/apt/sources.list.d/caddy-stable.list \
        /etc/apt/sources.list.d/php-sury.list \
        /etc/apt/sources.list.d/php.list \
        /etc/apt/sources.list.d/mongodb-org-*.list \
        /etc/apt/sources.list.d/nodesource*.list \
        /etc/yum.repos.d/caddy.repo \
        /etc/yum.repos.d/_copr:*caddy*.repo \
        /etc/yum.repos.d/mongodb-org-*.repo 2>/dev/null || true
    dnf -y copr remove @caddy/caddy >/dev/null 2>&1 || true
    rm -f /usr/share/keyrings/caddy-stable-archive-keyring.gpg \
        /usr/share/keyrings/php-sury-archive-keyring.gpg \
        /usr/share/keyrings/mongodb-server-*.gpg 2>/dev/null || true
    rm -f /etc/pki/rpm-gpg/RPM-GPG-KEY-caddy 2>/dev/null || true
    rm -f /etc/azerioid-panel/nodesource-*.installed 2>/dev/null || true
}

purge_engine_data_dirs() {
    echo "==> Removing panel-provisioned engine data directories"
    systemctl stop mariadb postgresql mongod redis-server memcached nginx httpd 2>/dev/null || true
    rm -rf /var/lib/mysql /var/lib/mongodb /var/lib/pgsql /var/lib/postgresql \
        /var/log/mysql /var/log/mongodb /var/log/postgresql 2>/dev/null || true
}

purge_firewall_rules() {
    echo "==> Removing panel firewalld/ufw rules"
    if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active firewalld >/dev/null 2>&1; then
        local port rule
        for port in 3169 3306 5432 27017; do
            firewall-cmd --permanent --remove-port="${port}/tcp" >/dev/null 2>&1 || true
        done
        while IFS= read -r rule; do
            [[ -n "${rule}" ]] || continue
            if echo "${rule}" | grep -Eq 'port="(3169|3306|5432|27017|8081|8082)"'; then
                firewall-cmd --permanent --remove-rich-rule="${rule}" >/dev/null 2>&1 || true
            fi
        done < <(firewall-cmd --permanent --list-rich-rules 2>/dev/null || true)
        firewall-cmd --permanent --remove-rich-rule='rule family=ipv4 port port=8081 protocol=tcp drop' >/dev/null 2>&1 || true
        firewall-cmd --permanent --remove-rich-rule='rule family=ipv4 port port=8082 protocol=tcp drop' >/dev/null 2>&1 || true
        firewall-cmd --reload >/dev/null 2>&1 || true
    fi
    if command -v ufw >/dev/null 2>&1; then
        ufw --force delete allow 3169/tcp >/dev/null 2>&1 || true
        ufw --force delete deny 8081/tcp >/dev/null 2>&1 || true
        ufw --force delete deny 8082/tcp >/dev/null 2>&1 || true
        ufw --force delete allow 3306/tcp >/dev/null 2>&1 || true
        ufw --force delete allow 5432/tcp >/dev/null 2>&1 || true
        ufw --force delete allow 27017/tcp >/dev/null 2>&1 || true
    fi
}

purge_released_caddy_state() {
    rm -rf /var/lib/azerioid-panel/staging/released-caddy-vhosts-* 2>/dev/null || true
    rm -f /var/lib/azerioid-panel/staging/caddyfile.pre-release-*.bak 2>/dev/null || true
}

purge_vhost_identities() {
    echo "==> Removing per-vhost users (az-vh-*) and supervised process user"
    local meta=/var/lib/azerioid-panel/vhost-users.json
    if [[ -f "${meta}" ]]; then
        while IFS= read -r user; do
            [[ -n "${user}" ]] || continue
            userdel --force "${user}" 2>/dev/null || true
        done < <(python3 - "${meta}" <<'PY' 2>/dev/null || true
import json, pathlib, sys
p = pathlib.Path(sys.argv[1])
data = json.loads(p.read_text())
for row in (data.get("users") or {}).values():
    name = (row or {}).get("username") or ""
    if name.startswith("az-vh-"):
        print(name)
PY
)
        rm -f "${meta}"
    fi
    getent passwd | awk -F: '$1 ~ /^az-vh-/ {print $1}' | while read -r user; do
        userdel --force "${user}" 2>/dev/null || true
    done
    if getent passwd azerioid-supervised >/dev/null 2>&1; then
        userdel --force azerioid-supervised 2>/dev/null || true
    fi
    rm -rf /var/lib/azerioid-supervised /var/log/azerioid-supervised
    if getent group azerioid-vhosts >/dev/null 2>&1; then
        groupdel azerioid-vhosts 2>/dev/null || true
    fi
}

purge_backend_dropins() {
    echo "==> Removing Caddy-front-router backend drop-ins"
    local f
    if declare -F apache_backend_dropins >/dev/null 2>&1; then
        while IFS= read -r f; do
            rm -f "${f}" 2>/dev/null || true
        done < <(apache_backend_dropins; nginx_backend_dropins)
    else
        rm -f /etc/apache2/conf-available/azerioid-backend.conf \
            /etc/apache2/conf-enabled/azerioid-backend.conf \
            /etc/httpd/conf.d/azerioid-backend.conf \
            /etc/httpd/conf.d/00-azerioid-remoteip.conf \
            /etc/nginx/conf.d/00-azerioid-backend.conf 2>/dev/null || true
    fi
}


remove_bootstrap_packages() {
    local bootstrap="/etc/azerioid-panel/bootstrap.json"
    [[ -f "${bootstrap}" ]] || return 0
    if ! python3 - "${bootstrap}" <<'PY'
import json, pathlib, sys
data = json.loads(pathlib.Path(sys.argv[1]).read_text())
sys.exit(0 if data.get("caddy") or data.get("php") else 1)
PY
    then
        return 0
    fi
    echo "==> Removing bootstrap-installed Caddy/PHP (per bootstrap.json)"
    if command -v apt-get >/dev/null 2>&1; then
        apt-get -y remove --purge caddy "php${PANEL_PHP_VERSION}-fpm" "php${PANEL_PHP_VERSION}-cli" \
            "php${PANEL_PHP_VERSION}-common" "php${PANEL_PHP_VERSION}-"* php-common 2>/dev/null || true
        apt-get -y autoremove --purge 2>/dev/null || true
    elif command -v dnf >/dev/null 2>&1; then
        dnf -y remove caddy php-fpm php-cli php-common php-process php-mysqlnd php-pgsql \
            php-mbstring php-xml php-curl php-zip php-bcmath php-sqlite3 \
            php-pdo php-json php-opcache 2>/dev/null || true
        dnf -y remove 'php-*' postgresql postgresql-server postgresql-contrib 2>/dev/null || true
    fi
    rm -f "${bootstrap}"
    rm -f /etc/caddy/conf.d/*.conf 2>/dev/null || true
    if [[ -f /etc/caddy/Caddyfile ]] && command -v caddy >/dev/null 2>&1; then
        cat > /etc/caddy/Caddyfile <<'EOF'
:80 {
    root * /usr/share/caddy
    file_server
}
EOF
        systemctl restart caddy 2>/dev/null || true
    fi
}

WEB_USER=caddy
id -u caddy >/dev/null 2>&1 || WEB_USER=www-data
id -u "${WEB_USER}" >/dev/null 2>&1 || WEB_USER=apache

if [[ "${PURGE_MANAGED}" -eq 1 ]]; then
    purge_managed_components
    purge_backend_dropins
    if command -v apt-get >/dev/null 2>&1; then
        # Broker removes the postgresql metapackage; versioned server/client packages can remain.
        mapfile -t pg_pkgs < <(dpkg-query -W -f='${Package} ${Status}\n' 2>/dev/null | awk '/install ok installed$/ && $1 ~ /^postgresql/ {print $1}')
        if [[ ${#pg_pkgs[@]} -gt 0 ]]; then
            echo "==> Purging leftover PostgreSQL packages: ${pg_pkgs[*]}"
            apt-get -y remove --purge "${pg_pkgs[@]}" 2>/dev/null || true
        fi
        apt-get -y autoremove --purge 2>/dev/null || true
    fi
fi

systemctl stop azerioid-panel-queue.service 2>/dev/null || true
systemctl disable azerioid-panel-queue.service 2>/dev/null || true
rm -f /etc/systemd/system/azerioid-panel-queue.service
systemctl daemon-reload

rm -f /etc/sudoers.d/azerioid-panel
visudo -c >/dev/null 2>&1 || echo "Warning: visudo -c failed after removing panel sudoers." >&2
rm -f /etc/cron.d/azerioid-panel
rm -f /etc/logrotate.d/azerioid-panel
rm -f /usr/local/bin/azerioid

if command -v fail2ban-client >/dev/null 2>&1 \
    && fail2ban-client status azerioid-panel >/dev/null 2>&1; then
    for ip in $(fail2ban-client status azerioid-panel 2>/dev/null | sed -n 's/.*Banned IP list:[[:space:]]*//p'); do
        [[ -n "${ip}" ]] || continue
        fail2ban-client set azerioid-panel unbanip "${ip}" >/dev/null 2>&1 || true
    done
fi
: > /var/log/azerioid-panel/auth-fail.log 2>/dev/null || true

rm -f /etc/fail2ban/filter.d/azerioid-panel.conf /etc/fail2ban/jail.d/azerioid-panel.conf
systemctl reload fail2ban 2>/dev/null || true

rm -f /etc/tmpfiles.d/azerioid-panel.conf
rm -f /etc/azerioid-panel/access.env /etc/azerioid-panel/runtime.json

SNIPPET=/etc/caddy/conf.d/azerioid-panel.conf
if [[ -f "${SNIPPET}" ]]; then
    rm -f "${SNIPPET}"
    systemctl reload caddy 2>/dev/null || true
fi
purge_released_caddy_state

systemctl stop azerioid-panel-php-fpm.service 2>/dev/null || true
systemctl disable azerioid-panel-php-fpm.service 2>/dev/null || true
rm -f /etc/systemd/system/azerioid-panel-php-fpm.service
rm -f /etc/azerioid-panel/php-fpm.conf
rm -rf /etc/azerioid-panel/php-fpm.d
rm -f /run/azerioid-panel-php-fpm.pid
semodule -r azerioid_panel_fpm 2>/dev/null || true
semanage fcontext -d -t bin_t '/usr/local/lib/azerioid-panel/sbin(/.*)?' 2>/dev/null || true

POOL="$(pool_dir || true)"
rm -f "${POOL:+${POOL}/azerioid-panel.conf}" 2>/dev/null || true

UNIT="$(fpm_unit)"
rm -f "/etc/systemd/system/${UNIT}.service.d/azerioid-panel.conf" 2>/dev/null || true
systemctl daemon-reload
systemctl restart "${UNIT}" 2>/dev/null || true

if [[ -f "${PREFIX}/web/.env" ]]; then
    install -d -m 0750 /etc/azerioid-panel
    cp -a "${PREFIX}/web/.env" /etc/azerioid-panel/web.env
    chmod 0640 /etc/azerioid-panel/web.env
fi

rm -rf "${PREFIX}"

if [[ "${REMOVE_BOOTSTRAP}" -eq 1 ]]; then
    remove_bootstrap_packages
fi

if [[ "${DROP_DB}" -eq 1 ]]; then
    purge_vhost_identities
    rm -f /var/lib/azerioid-panel/panel.sqlite /var/lib/azerioid-panel/panel.sqlite-wal /var/lib/azerioid-panel/panel.sqlite-shm
    rm -rf /var/lib/azerioid-panel
    rm -rf /etc/azerioid-panel
fi

if [[ "${PURGE_REPOS}" -eq 1 ]]; then
    purge_panel_repos
fi

if [[ "${PURGE_PACKAGE_DATA}" -eq 1 ]]; then
    purge_engine_data_dirs
fi

if [[ "${DROP_DB}" -eq 1 ]]; then
    purge_firewall_rules
fi

echo "AZERIOID Stack Manager panel artifacts removed."
if [[ "${PURGE_MANAGED}" -eq 0 ]]; then
    echo "  Managed components (MariaDB, Redis, Nginx, …) were left installed."
    echo "  Re-run with --purge-managed or --full to remove them via the broker."
fi
echo "  /data/www and user databases were not modified."
