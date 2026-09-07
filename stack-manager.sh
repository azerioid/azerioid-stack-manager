#!/usr/bin/env bash
#
# stack-manager.sh — AZERIOID Stack Manager bootstrap entrypoint.
# Self-contained install: Caddy + PHP 8.4 FPM + SQLite panel.
# No lcmp/lamp prerequisite.
#
set -euo pipefail

if [[ -t 1 ]]; then
    C_RED=$'\e[31m'; C_GRN=$'\e[32m'; C_CYN=$'\e[36m'; C_RST=$'\e[0m'
else
    C_RED=""; C_GRN=""; C_CYN=""; C_RST=""
fi
ok()  { echo "${C_GRN}[OK]${C_RST} $*"; }
die() { echo "${C_RED}[ERROR]${C_RST} $*" >&2; exit 1; }

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALLER="${REPO_ROOT}/deploy/install.sh"

for _arg in "$@"; do
    case "${_arg}" in
        -h|--help) exec bash "${INSTALLER}" --help ;;
    esac
done

[[ -f "${INSTALLER}" ]] || die "deploy/install.sh is missing. Re-clone the full repository."
[[ "$(id -u)" -eq 0 ]] || die "This installer must be run as root."

# shellcheck source=deploy/lib/detect-os.sh
source "${REPO_ROOT}/deploy/lib/detect-os.sh"
detect_os || die "Unsupported operating system."
ok "Detected supported OS: ${PRETTY_NAME:-$OS_ID $OS_VER}"

exec bash "${INSTALLER}" "$@"
