#!/usr/bin/env bash
# Phase 1 smoke test — run on a host after stack-manager bootstrap.
# Usage: sudo ./deploy/test/smoke-p1.sh [--panel-port=3169]
set -euo pipefail

PANEL_PORT=3169
PANEL_DB="/var/lib/azerioid-panel/panel.sqlite"
FPM_SOCK="/run/php/azerioid-panel.sock"
QUEUE_UNIT="azerioid-panel-queue.service"
FAILURES=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --panel-port=*) PANEL_PORT="${1#*=}"; shift ;;
        -h|--help)
            echo "Usage: smoke-p1.sh [--panel-port=3169]"
            exit 0
            ;;
        *) echo "Unknown: $1" >&2; exit 2 ;;
    esac
done

pass() { echo "[PASS] $*"; }
fail() { echo "[FAIL] $*" >&2; FAILURES=$((FAILURES + 1)); }

echo "==> AZERIOID Stack Manager P1 smoke test (port ${PANEL_PORT})"

# 1. Panel HTTP responds
if curl -fsSI "http://127.0.0.1:${PANEL_PORT}" 2>/dev/null | head -n1 | grep -qE 'HTTP/[0-9.]+ (200|302|301)'; then
    pass "Panel HTTP responds on 127.0.0.1:${PANEL_PORT}"
else
    fail "Panel not reachable at http://127.0.0.1:${PANEL_PORT}"
fi

# 2. FPM socket
if [[ -S "${FPM_SOCK}" ]]; then
    pass "FPM socket exists: ${FPM_SOCK}"
    owner="$(stat -c '%U' "${FPM_SOCK}" 2>/dev/null || stat -f '%Su' "${FPM_SOCK}")"
    if [[ "${owner}" == "caddy" || "${owner}" == "www-data" || "${owner}" == "apache" ]]; then
        pass "FPM socket owner: ${owner}"
    else
        fail "FPM socket owner unexpected: ${owner}"
    fi
else
    fail "FPM socket missing: ${FPM_SOCK}"
fi

# 3. SQLite panel DB
if [[ -f "${PANEL_DB}" ]]; then
    pass "Panel SQLite exists: ${PANEL_DB}"
else
    fail "Panel SQLite missing: ${PANEL_DB}"
fi

# 4. No MariaDB panel DB required
if command -v mariadb >/dev/null 2>&1; then
    if mariadb -e "SHOW DATABASES LIKE 'lacmp_panel';" 2>/dev/null | grep -q lacmp_panel; then
        echo "[WARN] lacmp_panel MariaDB schema exists (not required for bootstrap)"
    else
        pass "No MariaDB lacmp_panel schema (expected for bootstrap)"
    fi
else
    pass "MariaDB not installed (expected for bootstrap)"
fi

# 5. Queue worker active
if systemctl is-active --quiet "${QUEUE_UNIT}"; then
    pass "Queue worker active: ${QUEUE_UNIT}"
else
    fail "Queue worker not active: ${QUEUE_UNIT}"
fi

# 6. panel.runtime broker action
BROKER="/usr/local/lib/azerioid-panel/broker"
if [[ -x "${BROKER}" ]]; then
    if "${BROKER}" panel.runtime 2>/dev/null | grep -q '"php_version"'; then
        pass "broker panel.runtime returns php_version"
    else
        fail "broker panel.runtime failed or missing php_version"
    fi
else
    fail "Broker not found: ${BROKER}"
fi

# 7. Runtime JSON
if [[ -f /etc/azerioid-panel/runtime.json ]] && grep -q '"panel_php_version"' /etc/azerioid-panel/runtime.json; then
    pass "runtime.json present"
else
    fail "runtime.json missing or incomplete"
fi

# 8. Bootstrap tracking
if [[ -f /etc/azerioid-panel/bootstrap.json ]]; then
    pass "bootstrap.json present"
else
    fail "bootstrap.json missing"
fi

# 9. SELinux on EL
if command -v getenforce >/dev/null 2>&1; then
    mode="$(getenforce 2>/dev/null || echo Disabled)"
    if [[ "${mode}" == "Enforcing" ]]; then
        pass "SELinux enforcing and panel reachable"
    else
        echo "[INFO] SELinux mode: ${mode}"
    fi
fi

# 10. Jobs table (queue processed ping)
if [[ -f "${PANEL_DB}" ]] && command -v sqlite3 >/dev/null 2>&1; then
    pending="$(sqlite3 "${PANEL_DB}" "SELECT COUNT(*) FROM jobs;" 2>/dev/null || echo -1)"
    if [[ "${pending}" == "0" ]]; then
        pass "jobs table empty (queue processing)"
    elif [[ "${pending}" -ge 0 ]]; then
        echo "[WARN] jobs table has ${pending} pending row(s) — worker may still be catching up"
    fi
fi

echo
if [[ "${FAILURES}" -eq 0 ]]; then
    echo "Smoke test PASSED"
    exit 0
else
    echo "Smoke test FAILED (${FAILURES} failure(s))"
    exit 1
fi
