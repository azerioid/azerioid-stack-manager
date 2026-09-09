#!/usr/bin/env bash
# Single shared resolver for OS-varying paths and unit names.
# Call sites must not interpolate Debian/EL names; probe what is on disk.
set -euo pipefail

os_first_file() {
    local p
    for p in "$@"; do
        [[ -n "${p}" && -f "${p}" ]] || continue
        echo "${p}"
        return 0
    done
    return 1
}

os_first_dir() {
    local p
    for p in "$@"; do
        [[ -n "${p}" && -d "${p}" ]] || continue
        echo "${p}"
        return 0
    done
    return 1
}

os_php_nodot() {
    echo "${PANEL_PHP_VERSION:-8.4}" | tr -d '.'
}

mariadb_server_cnf() {
    os_first_file \
        "${MARIADB_SERVER_CNF:-}" \
        /etc/mysql/mariadb.conf.d/50-server.cnf \
        /etc/my.cnf.d/mariadb-server.cnf \
        /etc/my.cnf.d/server.cnf
}

mariadb_socket() {
    os_first_file \
        /run/mysqld/mysqld.sock \
        /var/lib/mysql/mysql.sock \
        /tmp/mysql.sock
}

pool_dir() {
    local nodot
    nodot="$(os_php_nodot)"
    os_first_dir \
        "/etc/php/${PANEL_PHP_VERSION}/fpm/pool.d" \
        "/etc/opt/remi/php${nodot}/php-fpm.d" \
        /etc/php-fpm.d
}

fpm_ini() {
    local nodot
    nodot="$(os_php_nodot)"
    os_first_file \
        "/etc/php/${PANEL_PHP_VERSION}/fpm/php.ini" \
        /etc/php.ini \
        "/etc/opt/remi/php${nodot}/php.ini"
}

fpm_unit() {
    local nodot u
    nodot="$(os_php_nodot)"
    for u in "php${PANEL_PHP_VERSION}-fpm" "php${nodot}-php-fpm" php-fpm; do
        if systemctl cat "${u}.service" >/dev/null 2>&1; then
            echo "${u}"
            return 0
        fi
    done
    echo "php${PANEL_PHP_VERSION}-fpm"
}

# Panel UI FPM. Prefer the dedicated unit when present (EL httpd_t workaround).
panel_fpm_unit() {
    if systemctl cat azerioid-panel-php-fpm.service >/dev/null 2>&1; then
        echo azerioid-panel-php-fpm
        return 0
    fi
    fpm_unit
}

apache_unit() {
    local u
    for u in httpd apache2; do
        if systemctl cat "${u}.service" >/dev/null 2>&1; then
            echo "${u}"
            return 0
        fi
    done
    if [[ -x /usr/sbin/httpd && ! -d /etc/apache2 ]]; then
        echo httpd
        return 0
    fi
    echo apache2
}

apache_backend_dropins() {
    printf '%s\n' \
        /etc/apache2/conf-available/azerioid-backend.conf \
        /etc/apache2/conf-enabled/azerioid-backend.conf \
        /etc/httpd/conf.d/azerioid-backend.conf \
        /etc/httpd/conf.d/00-azerioid-remoteip.conf
}

nginx_backend_dropins() {
    printf '%s\n' /etc/nginx/conf.d/00-azerioid-backend.conf
}

# Packages from registry/components/<id>.json distros.<family>.packages
registry_distro_packages() {
    local id="$1"
    local key="${DISTRO_FAMILY:-}"
    local json="${ROOT:-}/registry/components/${id}.json"
    [[ -n "${key}" && -f "${json}" ]] || return 1
    python3 - "${json}" "${key}" <<'PY'
import json, pathlib, sys
data = json.loads(pathlib.Path(sys.argv[1]).read_text())
key = sys.argv[2]
pkgs = data.get("distros", {}).get(key, {}).get("packages") or []
print(" ".join(str(p) for p in pkgs if p))
PY
}
