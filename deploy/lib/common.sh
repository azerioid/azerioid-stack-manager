#!/usr/bin/env bash
# Shared helpers for AZERIOID Stack Manager installer modules.
set -euo pipefail

if [[ -t 1 ]]; then
    C_RED=$'\e[31m'; C_GRN=$'\e[32m'; C_YLW=$'\e[33m'; C_CYN=$'\e[36m'; C_RST=$'\e[0m'
else
    C_RED=""; C_GRN=""; C_YLW=""; C_CYN=""; C_RST=""
fi

info()  { echo "${C_CYN}[*]${C_RST} $*"; }
ok()    { echo "${C_GRN}[OK]${C_RST} $*"; }
warn()  { echo "${C_YLW}[!]${C_RST} $*"; }
die()   { echo "${C_RED}[ERROR]${C_RST} $*" >&2; exit 1; }

env_set() {
    local file="$1" key="$2" value="$3"
    python3 - "$file" "$key" "$value" <<'PY'
import pathlib, sys
path, key, value = pathlib.Path(sys.argv[1]), sys.argv[2], sys.argv[3]
text = path.read_text() if path.exists() else ""
lines, found = [], False
prefix = key + "="

def fmt_value(v: str) -> str:
    if any(c in v for c in ' \t#"\\'):
        return '"' + v.replace("\\", "\\\\").replace('"', '\\"') + '"'
    return v

for line in text.splitlines():
    if line.startswith(prefix) or line.startswith("#" + prefix):
        if not found:
            lines.append(prefix + fmt_value(value))
            found = True
    else:
        lines.append(line)
if not found:
    lines.append(prefix + fmt_value(value))
path.write_text("\n".join(lines) + "\n")
PY
}

pool_dir() {
    if [[ -d "/etc/php/${PANEL_PHP_VERSION}/fpm/pool.d" ]]; then
        echo "/etc/php/${PANEL_PHP_VERSION}/fpm/pool.d"
    elif [[ -d /etc/php-fpm.d ]]; then
        echo /etc/php-fpm.d
    fi
}

fpm_ini() {
    if [[ -f "/etc/php/${PANEL_PHP_VERSION}/fpm/php.ini" ]]; then
        echo "/etc/php/${PANEL_PHP_VERSION}/fpm/php.ini"
    elif [[ -f /etc/php.ini ]]; then
        echo /etc/php.ini
    fi
}

fpm_bin() {
    local c
    for c in \
        "php-fpm${PANEL_PHP_VERSION}" \
        "/usr/sbin/php-fpm${PANEL_PHP_VERSION}" \
        "/usr/sbin/php-fpm" \
        "php${PANEL_PHP_VERSION}-fpm"
    do
        if command -v "${c}" >/dev/null 2>&1; then
            command -v "${c}"
            return 0
        fi
        if [[ -x "${c}" ]]; then
            echo "${c}"
            return 0
        fi
    done
    return 1
}

fpm_unit() {
    if systemctl cat "php${PANEL_PHP_VERSION}-fpm.service" >/dev/null 2>&1; then
        echo "php${PANEL_PHP_VERSION}-fpm"
        return
    fi
    if systemctl cat "php-fpm.service" >/dev/null 2>&1; then
        echo "php-fpm"
        return
    fi
    echo "php${PANEL_PHP_VERSION}-fpm"
}

php_bin() {
    if command -v "php${PANEL_PHP_VERSION}" >/dev/null 2>&1; then
        command -v "php${PANEL_PHP_VERSION}"
    elif command -v php >/dev/null 2>&1; then
        command -v php
    else
        return 1
    fi
}

detect_web_user() {
    if [[ -n "${WEB_USER:-}" ]]; then
        echo "${WEB_USER}"
        return
    fi
    if id -u caddy >/dev/null 2>&1; then
        echo caddy
    elif id -u www-data >/dev/null 2>&1; then
        echo www-data
    elif id -u apache >/dev/null 2>&1; then
        echo apache
    else
        echo www-data
    fi
}

run_as_web() {
    sudo -u "${WEB_USER}" -H env COMPOSER_HOME="${COMPOSER_HOME:-/tmp}" \
        PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin" \
        bash -c "cd '${PREFIX}/web' && $*"
}

write_bootstrap_json() {
    local ts
    ts="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    install -d -m 0750 -o root -g root /etc/azerioid-panel
    python3 - /etc/azerioid-panel/bootstrap.json "${ts}" <<'PY'
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
ts = sys.argv[2]
existing = {}
if path.is_file():
    try:
        existing = json.loads(path.read_text())
    except json.JSONDecodeError:
        pass
data = {
    "version": 1,
    "installed_at": existing.get("installed_at", ts),
    "updated_at": ts,
    "bootstrap_stack": "minimal",
    "caddy": True,
    "php": "8.4",
    "sqlite": True,
}
path.write_text(json.dumps(data, indent=2) + "\n")
path.chmod(0o640)
PY
}

write_runtime_json() {
    install -d -m 0750 -o root -g root /etc/azerioid-panel
    cat > /etc/azerioid-panel/runtime.json <<EOF
{
    "panel_php_version": "${PANEL_PHP_VERSION}",
    "fpm_socket": "/run/php/azerioid-panel.sock",
    "fpm_pool": "azerioid-panel",
    "queue_unit": "azerioid-panel-queue.service"
}
EOF
    chmod 0640 /etc/azerioid-panel/runtime.json
    chown root:root /etc/azerioid-panel/runtime.json
}
