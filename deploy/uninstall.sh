#!/usr/bin/env bash
# Reverse AZERIOID Stack Manager bootstrap. Never touches /data/www or user site data by default.
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "uninstall.sh must run as root" >&2
    exit 1
fi

PREFIX="${PREFIX:-/usr/local/lib/azerioid-panel}"
PANEL_PHP_VERSION="${PANEL_PHP_VERSION:-8.4}"
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
for cid in sorted(data.get("components", {}).keys(), reverse=True):
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
        /etc/yum.repos.d/caddy.repo 2>/dev/null || true
    rm -f /usr/share/keyrings/caddy-stable-archive-keyring.gpg \
        /usr/share/keyrings/php-sury-archive-keyring.gpg \
        /usr/share/keyrings/mongodb-server-*.gpg 2>/dev/null || true
    rm -f /etc/pki/rpm-gpg/RPM-GPG-KEY-caddy 2>/dev/null || true
    rm -f /etc/azerioid-panel/nodesource-*.installed 2>/dev/null || true
}

purge_engine_data_dirs() {
    echo "==> Removing panel-provisioned engine data directories"
    systemctl stop mariadb postgresql mongod redis-server memcached nginx 2>/dev/null || true
    rm -rf /var/lib/mysql /var/lib/mongodb /var/lib/postgresql \
        /var/log/mysql /var/log/mongodb /var/log/postgresql 2>/dev/null || true
}

purge_released_caddy_state() {
    rm -rf /var/lib/azerioid-panel/staging/released-caddy-vhosts-* 2>/dev/null || true
    rm -f /var/lib/azerioid-panel/staging/caddyfile.pre-release-*.bak 2>/dev/null || true
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
        apt-get -y remove --purge caddy "php${PANEL_PHP_VERSION}-fpm" "php${PANEL_PHP_VERSION}-cli" 2>/dev/null || true
        apt-get -y autoremove --purge 2>/dev/null || true
    elif command -v dnf >/dev/null 2>&1; then
        dnf -y remove caddy php-fpm php-cli 2>/dev/null || true
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
    if command -v apt-get >/dev/null 2>&1; then
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

POOL=""
[[ -d "/etc/php/${PANEL_PHP_VERSION}/fpm/pool.d" ]] && POOL="/etc/php/${PANEL_PHP_VERSION}/fpm/pool.d"
[[ -z "${POOL}" && -d /etc/php-fpm.d ]] && POOL=/etc/php-fpm.d
rm -f "${POOL}/azerioid-panel.conf" 2>/dev/null || true

UNIT="php${PANEL_PHP_VERSION}-fpm"
systemctl cat php-fpm.service >/dev/null 2>&1 && UNIT=php-fpm
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
    rm -f /var/lib/azerioid-panel/panel.sqlite /var/lib/azerioid-panel/panel.sqlite-wal /var/lib/azerioid-panel/panel.sqlite-shm
    rm -rf /var/lib/azerioid-panel/staging
    rm -f /etc/azerioid-panel/broker.json /etc/azerioid-panel/web.env /etc/azerioid-panel/bootstrap.json
    rmdir /etc/azerioid-panel 2>/dev/null || true
    rmdir /var/lib/azerioid-panel 2>/dev/null || true
fi

if [[ "${PURGE_REPOS}" -eq 1 ]]; then
    purge_panel_repos
fi

if [[ "${PURGE_PACKAGE_DATA}" -eq 1 ]]; then
    purge_engine_data_dirs
fi

echo "AZERIOID Stack Manager panel artifacts removed."
if [[ "${PURGE_MANAGED}" -eq 0 ]]; then
    echo "  Managed components (MariaDB, Redis, Nginx, …) were left installed."
    echo "  Re-run with --purge-managed or --full to remove them via the broker."
fi
echo "  /data/www and user databases were not modified."
