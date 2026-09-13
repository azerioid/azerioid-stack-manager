#!/usr/bin/env bash
# SELinux contexts for EL when enforcing (A3).
set -euo pipefail

apply_selinux() {
    [[ "${DISTRO_FAMILY}" == "el" ]] || return 0
    command -v getenforce >/dev/null 2>&1 || return 0
    [[ "$(getenforce 2>/dev/null || echo Disabled)" == "Enforcing" ]] || return 0

    echo "==> Applying SELinux contexts (enforcing)"
    dnf -y install policycoreutils-python-utils >/dev/null 2>&1 || true

    semanage port -a -t http_port_t -p tcp "${PANEL_PORT}" 2>/dev/null \
        || semanage port -m -t http_port_t -p tcp "${PANEL_PORT}" 2>/dev/null || true
    # Default EL policy labels 8081 as transproxy_port_t and 8082 as us_cli_port_t;
    # httpd_t/nginx cannot bind those. Relabel as http_port_t (A3 / A9 backends).
    for backend_port in 8081 8082; do
        semanage port -a -t http_port_t -p tcp "${backend_port}" 2>/dev/null \
            || semanage port -m -t http_port_t -p tcp "${backend_port}" 2>/dev/null || true
    done
    semanage fcontext -a -t httpd_sys_content_t '/data/www(/.*)?' 2>/dev/null || true
    semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/azerioid-panel(/.*)?' 2>/dev/null || true
    # Site PHP-FPM stays httpd_t (php-fpm binary is httpd_exec_t). Panel UI FPM
    # is a dedicated unconfined_service_t unit (see configure_panel_fpm).
    # Without these fcontexts, Laravel 500s writing sessions/logs under lib_t
    # with no AVC (dontaudit) and empty bodies.
    semanage fcontext -a -t httpd_sys_content_t '/usr/local/lib/azerioid-panel/web(/.*)?' 2>/dev/null || true
    semanage fcontext -a -t httpd_sys_rw_content_t '/usr/local/lib/azerioid-panel/web/storage(/.*)?' 2>/dev/null || true
    semanage fcontext -a -t httpd_sys_rw_content_t '/usr/local/lib/azerioid-panel/web/bootstrap/cache(/.*)?' 2>/dev/null || true
    semanage fcontext -a -t bin_t '/usr/local/lib/azerioid-panel/sbin(/.*)?' 2>/dev/null || true
    # install -d under /var/log inherits var_log_t; Caddy (httpd_t) needs httpd_log_t
    # or open(access_azerioid-panel.log) fails with EACCES (often dontaudit / no AVC).
    semanage fcontext -a -t httpd_log_t '/var/log/caddy(/.*)?' 2>/dev/null || true
    install -d -m 0755 /data/www
    restorecon -Rv /data/www /var/lib/azerioid-panel /usr/local/lib/azerioid-panel /var/log/caddy 2>/dev/null || true
    setsebool -P httpd_can_network_connect 1 2>/dev/null || true
    setsebool -P httpd_unified 1 2>/dev/null || true
    install_panel_fpm_selinux_module
}

install_panel_fpm_selinux_module() {
    local te="${ROOT}/deploy/selinux/azerioid_panel_fpm.te"
    [[ -f "${te}" ]] || {
        echo "missing ${te}" >&2
        return 1
    }
    # Re-installing the same module reloads policy; on 512MB EL that often OOMs
    # (`load_policy: Cannot allocate memory`) and aborts an otherwise healthy bootstrap.
    if command -v semodule >/dev/null 2>&1 && semodule -l 2>/dev/null | grep -q '^azerioid_panel_fpm'; then
        echo "==> SELinux module azerioid_panel_fpm already loaded"
        return 0
    fi
    command -v checkmodule >/dev/null 2>&1 || dnf -y install checkpolicy >/dev/null 2>&1 || true
    command -v checkmodule >/dev/null 2>&1 || {
        echo "checkmodule not found; cannot load azerioid_panel_fpm SELinux module" >&2
        return 1
    }
    local work
    work="$(mktemp -d)"
    checkmodule -M -m -o "${work}/azerioid_panel_fpm.mod" "${te}"
    semodule_package -o "${work}/azerioid_panel_fpm.pp" -m "${work}/azerioid_panel_fpm.mod"
    # First install still reloads policy; on 512MB EL that often OOMs mid-bootstrap.
    # Free page cache and retry once before failing the whole install.
    sync 2>/dev/null || true
    echo 3 >/proc/sys/vm/drop_caches 2>/dev/null || true
    if ! semodule -i "${work}/azerioid_panel_fpm.pp"; then
        echo "==> semodule install failed (often OOM on 512MB); retrying once after reclaim" >&2
        sleep 2
        sync 2>/dev/null || true
        echo 3 >/proc/sys/vm/drop_caches 2>/dev/null || true
        if ! semodule -i "${work}/azerioid_panel_fpm.pp"; then
            rm -rf "${work}"
            echo "Failed to load azerioid_panel_fpm SELinux module after retry (check free RAM/swap)." >&2
            return 1
        fi
    fi
    rm -rf "${work}"
}
