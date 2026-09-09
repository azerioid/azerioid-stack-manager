#!/usr/bin/env bash
# Wait until the panel HTTP endpoint and FPM socket are actually ready.
set -euo pipefail

wait_for_panel_ready() {
    local port="${PANEL_PORT}"
    local fpm_sock="/run/php/azerioid-panel.sock"
    local queue_unit="azerioid-panel-queue.service"
    local i

    echo "==> Waiting for panel services to become ready"
    for i in $(seq 1 60); do
        if [[ -S "${fpm_sock}" ]] \
            && systemctl is-active --quiet "${queue_unit}" 2>/dev/null \
            && curl -fsSI "http://127.0.0.1:${port}/login" 2>/dev/null | head -n1 | grep -qE 'HTTP/[0-9.]+ (200|302)'; then
            return 0
        fi
        sleep 0.5
    done

    die "Panel is not reachable on 127.0.0.1:${port} (FPM socket, queue worker, or HTTP check failed)."
}

verify_public_panel_ready() {
    [[ "${ACCESS:-tunnel}" == "public" ]] || return 0

    local port="${PANEL_PORT}"
    local ip="${PANEL_PUBLIC_IP:-}"
    [[ -z "${ip}" ]] && ip="$(detect_public_ip 2>/dev/null || true)"
    [[ -n "${ip}" ]] || die "Public access: could not determine public IP for readiness check."

    if ! ss -tln 2>/dev/null | awk '{print $4}' | grep -qE "^(\[::\]:|\*:|0\.0\.0\.0:)${port}$"; then
        die "Public access: port ${port} is not listening on all interfaces (public Caddy bind missing)."
    fi

    local i code
    echo "==> Verifying public HTTPS on ${ip}:${port}"
    for i in $(seq 1 30); do
        code="$(curl -kfsSI --connect-timeout 3 --max-time 5 "https://${ip}:${port}/login" 2>/dev/null | head -n1 || true)"
        if echo "${code}" | grep -qE 'HTTP/[0-9.]+ (200|302)'; then
            export PUBLIC_READINESS_VERIFIED=1
            return 0
        fi
        sleep 0.5
    done

    warn "Public HTTPS self-check failed: https://${ip}:${port}/login not reachable from this host."
    warn "Tunnel access may still work; if external browsers cannot connect, check your cloud provider firewall."
    export PUBLIC_READINESS_VERIFIED=0
}

panel_public_url() {
    if [[ "${ACCESS:-tunnel}" != "public" ]]; then
        return 1
    fi
    if [[ -n "${PANEL_PUBLIC_DOMAIN:-}" ]]; then
        echo "https://${PANEL_PUBLIC_DOMAIN}"
        return 0
    fi
    local ip="${PANEL_PUBLIC_IP:-}"
    [[ -z "${ip}" ]] && ip="$(detect_public_ip 2>/dev/null || true)"
    [[ -n "${ip}" ]] || return 1
    echo "https://${ip}:${PANEL_PORT}"
}

print_install_success() {
    local panel_url="http://127.0.0.1:${PANEL_PORT}"
    local setup_url="${panel_url}/setup"
    local login_url="${panel_url}/login"
    local totp_note="disabled (password-only login)"
    if [[ "${REQUIRE_TOTP:-false}" == "true" ]]; then
        totp_note="required (enroll on first login if not done during setup)"
    fi
    local admin_created=0
    if command -v sqlite3 >/dev/null 2>&1 \
        && [[ -f /var/lib/azerioid-panel/panel.sqlite ]] \
        && sqlite3 /var/lib/azerioid-panel/panel.sqlite "SELECT COUNT(*) FROM users;" 2>/dev/null | grep -q '^[1-9]'; then
        admin_created=1
    fi

    echo
    echo "AZERIOID Stack Manager installed."
    echo "  Panel:     ${panel_url}"
    if [[ "${admin_created}" -eq 0 ]]; then
        echo "  Setup:     ${setup_url}  (create the admin account — required on first visit)"
    fi
    echo "  Sign in:   ${login_url}"
    echo "  TOTP:      ${totp_note}"
    echo "  Access:    ${ACCESS:-tunnel}"
    echo "  Firewall:  ${DO_FIREWALL:-false}  fail2ban: ${DO_FAIL2BAN:-false}"
    echo "  Broker:    ${PREFIX}/broker"
    echo "  Database:  /var/lib/azerioid-panel/panel.sqlite"
    echo "  Queue:     systemctl status azerioid-panel-queue"
    echo
    echo "  SSH tunnel: ssh -L ${PANEL_PORT}:127.0.0.1:${PANEL_PORT} user@host"
    if [[ "${admin_created}" -eq 0 ]]; then
        echo "              Then open ${setup_url} in your browser."
    else
        echo "              Then open ${login_url} in your browser."
    fi
    local public_url=""
    public_url="$(panel_public_url 2>/dev/null || true)"
    if [[ -n "${public_url}" ]]; then
        if [[ -n "${PANEL_PUBLIC_DOMAIN:-}" ]]; then
            echo "  Public:    ${public_url}/login  (TLS on ${PANEL_PUBLIC_DOMAIN}; accept cert warning if self-signed)"
        else
            echo "  Public:    ${public_url}/login  (self-signed TLS; accept the certificate warning)"
        fi
        if [[ "${PUBLIC_READINESS_VERIFIED:-0}" -eq 1 ]]; then
            echo "  Verified:  public HTTPS reachable from this host."
        elif [[ "${PUBLIC_READINESS_VERIFIED:-}" == "0" ]]; then
            echo "  Warning:   public HTTPS self-check did not pass — see installer warnings above."
        fi
        echo "             If unreachable from outside, check your cloud provider firewall allows inbound TCP ${PANEL_PORT}."
    fi
    if [[ "${DO_FAIL2BAN:-false}" == "true" ]]; then
        echo "             fail2ban: repeated failed logins block your IP from port ${PANEL_PORT} for 1h (connection refused)."
    fi
    echo
    if [[ "${admin_created}" -eq 1 ]]; then
        local admin_email=""
        admin_email="$(sqlite3 /var/lib/azerioid-panel/panel.sqlite "SELECT email FROM users ORDER BY id LIMIT 1;" 2>/dev/null || true)"
        echo "  Admin:     ${admin_email:-unknown} (sign in at ${login_url})"
        if [[ "${REQUIRE_TOTP:-false}" == "true" ]]; then
            echo "             Complete 2FA enrollment on first login."
        fi
    else
        if [[ "${ADMIN_CREATE_FAILED:-0}" -eq 1 ]]; then
            echo "  ⚠ Admin account was NOT created during install."
            echo "    Complete ${setup_url} in your browser, or run panel:create-admin manually"
            echo "    (see installer output above for the exact env-var command)."
        else
            echo "  No admin account yet — complete /setup or reinstall interactively and choose admin creation."
        fi
    fi
}
