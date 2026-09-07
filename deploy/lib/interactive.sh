#!/usr/bin/env bash
# Interactive installer prompts (TTY + no --non-interactive).
set -euo pipefail

# Defaults for admin bootstrap (used by prompts and post-install warnings).
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-password}"
ADMIN_NAME="${ADMIN_NAME:-Admin}"
CREATE_ADMIN="${CREATE_ADMIN:-1}"
PANEL_PUBLIC_DOMAIN="${PANEL_PUBLIC_DOMAIN:-}"
PANEL_PUBLIC_IP="${PANEL_PUBLIC_IP:-}"
IP_ALLOWLIST="${IP_ALLOWLIST:-}"
INSTALL_USED_DEFAULT_ADMIN_PASSWORD=0
INSTALL_USED_DEFAULT_ADMIN_EMAIL=0

prompt_line() {
    local prompt="$1" default="${2:-}" reply=""
    if [[ -n "${default}" ]]; then
        read -r -p "${prompt} [${default}]: " reply </dev/tty
        echo "${reply:-${default}}"
    else
        read -r -p "${prompt}: " reply </dev/tty
        echo "${reply}"
    fi
}

prompt_yes_no() {
    local prompt="$1" default="${2:-y}" reply="" hint="Y/n"
    [[ "${default}" == "n" || "${default}" == "N" ]] && hint="y/N"
    read -r -p "${prompt} [${hint}]: " reply </dev/tty
    reply="${reply:-${default}}"
    case "$(echo "${reply}" | tr '[:upper:]' '[:lower:]')" in
        y|yes) echo true ;;
        *) echo false ;;
    esac
}

prompt_password() {
    local prompt="$1" default="${2:-}" reply=""
    if [[ -n "${default}" ]]; then
        read -r -s -p "${prompt} [${default}]: " reply </dev/tty
        echo "" >&2
        echo "${reply:-${default}}"
    else
        read -r -s -p "${prompt}: " reply </dev/tty
        echo "" >&2
        echo "${reply}"
    fi
}

run_interactive_install_prompts() {
    echo
    echo "AZERIOID Stack Manager interactive setup (Enter accepts [bracketed] defaults)."
    echo

    if [[ "${EXPLICIT_ACCESS:-0}" -eq 0 ]]; then
        ACCESS="$(prompt_line "Access mode (tunnel/public)" "tunnel")"
        ACCESS="$(echo "${ACCESS}" | tr '[:upper:]' '[:lower:]')"
        [[ "${ACCESS}" == "tunnel" || "${ACCESS}" == "public" ]] || die "Access mode must be tunnel or public."
    fi

    if [[ "${ACCESS}" == "public" && "${EXPLICIT_PUBLIC_DOMAIN:-0}" -eq 0 ]]; then
        PANEL_PUBLIC_DOMAIN="$(prompt_line "Domain for automatic HTTPS (blank = IP / self-signed)" "")"
    fi

    if [[ "${ACCESS}" == "public" && -z "${PANEL_PUBLIC_DOMAIN}" && "${EXPLICIT_PUBLIC_IP:-0}" -eq 0 ]]; then
        local detected=""
        detected="$(detect_public_ip 2>/dev/null || true)"
        PANEL_PUBLIC_IP="$(prompt_line "Public IP (blank = auto-detect)" "${detected}")"
    fi

    if [[ "${EXPLICIT_PORT:-0}" -eq 0 ]]; then
        PANEL_PORT="$(prompt_line "Panel port" "${PANEL_PORT}")"
        [[ "${PANEL_PORT}" =~ ^[0-9]+$ ]] && (( PANEL_PORT >= 1024 && PANEL_PORT <= 65535 )) \
            || die "Panel port must be 1024–65535."
    fi

    if [[ "${EXPLICIT_TOTP:-0}" -eq 0 ]]; then
        local totp_default="n"
        [[ "${ACCESS}" == "public" ]] && totp_default="y"
        REQUIRE_TOTP="$(prompt_yes_no "Require TOTP for admins?" "${totp_default}")"
    fi

    if [[ "${ACCESS}" == "public" && "${REQUIRE_TOTP}" != "true" ]]; then
        warn "Public access with TOTP disabled — the panel will be password-only on the network."
    fi

    if [[ "${EXPLICIT_FIREWALL:-0}" -eq 0 ]]; then
        local fw_default="n"
        [[ "${ACCESS}" == "public" ]] && fw_default="y"
        DO_FIREWALL="$(prompt_yes_no "Open firewall for the panel port?" "${fw_default}")"
    fi

    if [[ "${EXPLICIT_FAIL2BAN:-0}" -eq 0 ]]; then
        local f2b_default="n"
        [[ "${ACCESS}" == "public" ]] && f2b_default="y"
        DO_FAIL2BAN="$(prompt_yes_no "Install fail2ban jail for failed logins?" "${f2b_default}")"
    fi

    if [[ "${EXPLICIT_ALLOWLIST:-0}" -eq 0 ]]; then
        IP_ALLOWLIST="$(prompt_line "Allowlist IPs comma-separated (blank = any + fail2ban)" "")"
    fi

    if [[ "${EXPLICIT_CREATE_ADMIN:-0}" -eq 0 ]]; then
        CREATE_ADMIN="$(prompt_yes_no "Create default admin now?" "y")"
        if [[ "${CREATE_ADMIN}" == "true" ]]; then
            local default_email="admin@example.com"
            local default_password="password"
            local chosen_email chosen_password
            chosen_email="$(prompt_line "Admin email" "${default_email}")"
            chosen_password="$(prompt_password "Admin password" "${default_password}")"
            ADMIN_EMAIL="${chosen_email}"
            ADMIN_PASSWORD="${chosen_password}"
            [[ "${ADMIN_EMAIL}" == "${default_email}" ]] && INSTALL_USED_DEFAULT_ADMIN_EMAIL=1
            [[ "${ADMIN_PASSWORD}" == "${default_password}" ]] && INSTALL_USED_DEFAULT_ADMIN_PASSWORD=1
        else
            CREATE_ADMIN=0
        fi
    fi

    case "${CREATE_ADMIN}" in
        true|1) CREATE_ADMIN=1 ;;
        *) CREATE_ADMIN=0 ;;
    esac

    export ACCESS PANEL_PORT REQUIRE_TOTP DO_FIREWALL DO_FAIL2BAN
    export PANEL_PUBLIC_DOMAIN PANEL_PUBLIC_IP IP_ALLOWLIST
    export ADMIN_EMAIL ADMIN_PASSWORD ADMIN_NAME CREATE_ADMIN
    export INSTALL_USED_DEFAULT_ADMIN_PASSWORD INSTALL_USED_DEFAULT_ADMIN_EMAIL
}
