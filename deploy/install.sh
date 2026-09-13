#!/usr/bin/env bash
# AZERIOID Stack Manager installer — self-contained bootstrap (Caddy + PHP 8.4 + SQLite).
# Invoked by ./stack-manager.sh or directly by advanced users.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LIB="${ROOT}/deploy/lib"

# Defaults
PREFIX="${PREFIX:-/usr/local/lib/azerioid-panel}"
WWW_ROOT="${WWW_ROOT:-/data/www}"
PANEL_PHP_VERSION="${PANEL_PHP_VERSION:-8.4}"
PANEL_PORT="${PANEL_PORT:-3169}"
WEB_USER="${WEB_USER:-}"
ACCESS="${ACCESS:-tunnel}"
PANEL_PUBLIC_DOMAIN="${PANEL_PUBLIC_DOMAIN:-}"
PANEL_PUBLIC_IP="${PANEL_PUBLIC_IP:-}"
IP_ALLOWLIST="${IP_ALLOWLIST:-}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-password}"
ADMIN_NAME="${ADMIN_NAME:-Admin}"
CREATE_ADMIN="${CREATE_ADMIN:-0}"
NON_INTERACTIVE=0
DRY_RUN=0
SKIP_CADDY=0
DO_FIREWALL=false
DO_FAIL2BAN=false
REQUIRE_TOTP=false
INSTALL_USED_DEFAULT_ADMIN_PASSWORD=0
INSTALL_USED_DEFAULT_ADMIN_EMAIL=0

# Set to 1 when equivalent CLI flag was passed (skips that interactive prompt).
EXPLICIT_ACCESS=0
EXPLICIT_PORT=0
EXPLICIT_TOTP=0
EXPLICIT_FIREWALL=0
EXPLICIT_FAIL2BAN=0
EXPLICIT_PUBLIC_DOMAIN=0
EXPLICIT_PUBLIC_IP=0
EXPLICIT_ALLOWLIST=0
EXPLICIT_CREATE_ADMIN=0

usage() {
    cat <<'EOF'
Usage: stack-manager.sh [options]
       deploy/install.sh [options]

AZERIOID Stack Manager bootstrap — installs Caddy, PHP 8.4 FPM, and the SQLite-backed admin panel.

  --non-interactive       no prompts (also auto-enabled when stdin is not a TTY)
  --dry-run               print plan only
  --prefix=<dir>          default /usr/local/lib/azerioid-panel
  --port=<n>              panel port (default 3169)
  --web-user=<user>       default: caddy or www-data
  --access=tunnel|public  default tunnel (127.0.0.1)
  --domain=<name>         white-label panel hostname on :443 (Let's Encrypt); IP:port stays as fallback
  --public-ip=<addr>      override detected public IP (public mode)
  --allowlist=<csv>       panel IP allowlist (exact IPs; blank = any)
  --create-admin=true|false  bootstrap admin via artisan (default false non-interactive)
  --admin-email=<addr>    with --create-admin=true
  --skip-caddy            skip panel vhost snippet
  --firewall=true|false   open panel port in ufw/firewalld
  --fail2ban=true|false   install fail2ban jail
  --require-totp=true|false
  -h, --help

Interactive mode (TTY, no --non-interactive): prompts for access, port, TOTP, firewall,
fail2ban, allowlist, and optional admin creation. CLI flags pre-fill or skip matching prompts.
EOF
}

parse_bool() {
    case "$(echo "${1:-}" | tr '[:upper:]' '[:lower:]')" in
        1|true|yes|on) echo true ;;
        0|false|no|off) echo false ;;
        *) return 1 ;;
    esac
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --non-interactive) NON_INTERACTIVE=1; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        --prefix=*) PREFIX="${1#*=}"; shift ;;
        --port=*) PANEL_PORT="${1#*=}"; EXPLICIT_PORT=1; shift ;;
        --web-user=*) WEB_USER="${1#*=}"; shift ;;
        --access=*) ACCESS="${1#*=}"; EXPLICIT_ACCESS=1; shift ;;
        --domain=*) PANEL_PUBLIC_DOMAIN="${1#*=}"; EXPLICIT_PUBLIC_DOMAIN=1; shift ;;
        --public-ip=*) PANEL_PUBLIC_IP="${1#*=}"; EXPLICIT_PUBLIC_IP=1; shift ;;
        --allowlist=*) IP_ALLOWLIST="${1#*=}"; EXPLICIT_ALLOWLIST=1; shift ;;
        --create-admin=*) CREATE_ADMIN="$(parse_bool "${1#*=}")"; EXPLICIT_CREATE_ADMIN=1; shift ;;
        --admin-email=*) ADMIN_EMAIL="${1#*=}"; shift ;;
        --skip-caddy) SKIP_CADDY=1; shift ;;
        --firewall=*) DO_FIREWALL="$(parse_bool "${1#*=}")"; EXPLICIT_FIREWALL=1; shift ;;
        --fail2ban=*) DO_FAIL2BAN="$(parse_bool "${1#*=}")"; EXPLICIT_FAIL2BAN=1; shift ;;
        --require-totp=*) REQUIRE_TOTP="$(parse_bool "${1#*=}")"; EXPLICIT_TOTP=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
    esac
done

# Piped SSH / CI: never block on prompts when stdin is not a terminal.
if [[ "${NON_INTERACTIVE}" -eq 0 && ! -t 0 ]]; then
    NON_INTERACTIVE=1
fi

export ROOT PREFIX WWW_ROOT PANEL_PHP_VERSION PANEL_PORT ACCESS NON_INTERACTIVE DRY_RUN SKIP_CADDY
export DO_FIREWALL DO_FAIL2BAN REQUIRE_TOTP
export PANEL_PUBLIC_DOMAIN PANEL_PUBLIC_IP IP_ALLOWLIST
export ADMIN_EMAIL ADMIN_PASSWORD ADMIN_NAME CREATE_ADMIN
export INSTALL_USED_DEFAULT_ADMIN_PASSWORD INSTALL_USED_DEFAULT_ADMIN_EMAIL
export EXPLICIT_ACCESS EXPLICIT_PORT EXPLICIT_TOTP EXPLICIT_FIREWALL EXPLICIT_FAIL2BAN
export EXPLICIT_PUBLIC_DOMAIN EXPLICIT_PUBLIC_IP EXPLICIT_ALLOWLIST EXPLICIT_CREATE_ADMIN

[[ ${EUID} -eq 0 ]] || { echo "install.sh must be run as root" >&2; exit 1; }

# shellcheck source=lib/common.sh
source "${LIB}/common.sh"
# shellcheck source=lib/detect-os.sh
source "${LIB}/detect-os.sh"
# shellcheck source=lib/interactive.sh
source "${LIB}/interactive.sh"
# shellcheck source=lib/repos.sh
source "${LIB}/repos.sh"
# shellcheck source=lib/packages.sh
source "${LIB}/packages.sh"
# shellcheck source=lib/selinux.sh
source "${LIB}/selinux.sh"
# shellcheck source=lib/fpm.sh
source "${LIB}/fpm.sh"
# shellcheck source=lib/caddy.sh
source "${LIB}/caddy.sh"
# shellcheck source=lib/panel-db.sh
source "${LIB}/panel-db.sh"
# shellcheck source=lib/queue-worker.sh
source "${LIB}/queue-worker.sh"
# shellcheck source=lib/broker-setup.sh
source "${LIB}/broker-setup.sh"
# shellcheck source=lib/security.sh
source "${LIB}/security.sh"
# shellcheck source=lib/readiness.sh
source "${LIB}/readiness.sh"
# shellcheck source=lib/post-install.sh
source "${LIB}/post-install.sh"

detect_os
WEB_USER="$(detect_web_user)"
export WEB_USER

if [[ "${NON_INTERACTIVE}" -eq 0 ]]; then
    run_interactive_install_prompts
fi

if [[ "${DRY_RUN}" -eq 1 ]]; then
    echo "DRY-RUN — AZERIOID Stack Manager bootstrap"
    echo "  prefix:    ${PREFIX}"
    echo "  php:       ${PANEL_PHP_VERSION}"
    echo "  web user:  ${WEB_USER}"
    echo "  port:      ${PANEL_PORT}"
    echo "  access:    ${ACCESS}"
    echo "  totp:      ${REQUIRE_TOTP}"
    echo "  firewall:  ${DO_FIREWALL}"
    echo "  fail2ban:  ${DO_FAIL2BAN}"
    echo "  admin:     ${CREATE_ADMIN} (${ADMIN_EMAIL})"
    exit 0
fi

[[ -d "${ROOT}/broker/src" && -f "${ROOT}/broker/broker" && -d "${ROOT}/web" ]] || {
    echo "Repo layout incomplete (need broker/ and web/)." >&2
    exit 1
}

echo "==> AZERIOID Stack Manager bootstrap into ${PREFIX}"

setup_repos
bootstrap_packages
# On a bare EL image, www-data does not exist and the caddy user is created
# by the Caddy RPM — re-detect after packages so FPM/broker are not owned
# by a phantom www-data group.
if ! id -u "${WEB_USER}" >/dev/null 2>&1; then
    WEB_USER=""
    WEB_USER="$(detect_web_user)"
    export WEB_USER
    echo "==> Web user (after packages): ${WEB_USER}"
fi
source "${LIB}/ttyd.sh"
install_ttyd
apply_selinux
install_broker
install_panel_app
configure_panel_db
configure_panel_fpm
configure_panel_caddy
install_queue_worker
dispatch_ping_job
install_security
write_bootstrap_json

# Rsync of the panel tree after the first restorecon leaves files as
# admin_home_t / unconfined. Re-apply so Caddy (httpd_t) can read the web
# tree; site FPM stays confined.
apply_selinux

wait_for_panel_ready
verify_public_panel_ready
create_install_admin
print_install_success
print_install_warnings

chmod 0751 "${PREFIX}"
chown root:root "${PREFIX}"
