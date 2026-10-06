#!/usr/bin/env bash
# Bootstrap packages: Caddy, PHP 8.4 FPM, SQLite, composer deps.
set -euo pipefail

# A66: fetch the Composer installer to a temp file and verify its SHA-384
# against the signature Composer publishes (on composer.github.io, a different
# origin than getcomposer.org) before running it as root. Piping the installer
# straight into PHP ran whatever bytes the fetch returned; a compromised origin,
# active MITM, or redirecting resolver meant root code execution at install.
install_composer_verified() {
    local php_bin="$1"
    local tmp expected actual
    tmp="$(mktemp)"
    # Signature first, from the separate composer.github.io origin.
    expected="$(curl -fsSL https://composer.github.io/installer.sig | tr -d '[:space:]')"
    if [[ ! "${expected}" =~ ^[a-f0-9]{96}$ ]]; then
        rm -f "${tmp}"
        echo "Composer installer signature could not be fetched or is malformed." >&2
        exit 1
    fi
    curl -fsSL https://getcomposer.org/installer -o "${tmp}"
    actual="$("${php_bin}" -r "echo hash_file('sha384', '${tmp}');")"
    if [[ "${actual}" != "${expected}" ]]; then
        rm -f "${tmp}"
        echo "Composer installer checksum mismatch (expected ${expected}, got ${actual}); refusing to run it." >&2
        exit 1
    fi
    "${php_bin}" "${tmp}" -- --install-dir=/usr/local/bin --filename=composer
    rm -f "${tmp}"
}

bootstrap_packages() {
    echo "==> Installing bootstrap packages"
    case "${PKG_MGR}" in
        apt-get)
            export DEBIAN_FRONTEND=noninteractive
            apt-get -o DPkg::Lock::Timeout=120 install -y \
                caddy sqlite3 curl ca-certificates gnupg rsync logrotate \
                "php${PANEL_PHP_VERSION}-fpm" "php${PANEL_PHP_VERSION}-cli" \
                "php${PANEL_PHP_VERSION}-sqlite3" "php${PANEL_PHP_VERSION}-mysql" \
                "php${PANEL_PHP_VERSION}-pgsql" "php${PANEL_PHP_VERSION}-mbstring" \
                "php${PANEL_PHP_VERSION}-xml" "php${PANEL_PHP_VERSION}-curl" \
                "php${PANEL_PHP_VERSION}-zip" "php${PANEL_PHP_VERSION}-bcmath" \
                unzip git
            ;;
        dnf)
            dnf -y install caddy sqlite curl ca-certificates gnupg2 unzip git rsync logrotate \
                php-fpm php-cli php-process php-sqlite3 php-mysqlnd php-pgsql php-mbstring php-xml php-curl \
                php-zip php-bcmath php-sodium policycoreutils-python-utils checkpolicy >/dev/null
            ;;
    esac

    # Non-interactive SSH / minimal images may omit /usr/local/bin from PATH.
    export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:${PATH:-}"

    if ! command -v composer >/dev/null 2>&1 && [[ ! -x /usr/local/bin/composer ]]; then
        echo "==> Installing Composer"
        install_composer_verified "$(php_bin)"
        chmod 0755 /usr/local/bin/composer
        hash -r 2>/dev/null || true
    fi
    # Prefer absolute paths (same as PanelUpdater::composerBin) so an empty
    # `command -v` result cannot become `php install` → "Could not open input file".
    if [[ -x /usr/local/bin/composer ]]; then
        export COMPOSER_BIN=/usr/local/bin/composer
    elif [[ -x /usr/bin/composer ]]; then
        export COMPOSER_BIN=/usr/bin/composer
    else
        export COMPOSER_BIN="$(command -v composer || true)"
    fi
    if [[ -z "${COMPOSER_BIN}" || ! -x "${COMPOSER_BIN}" ]]; then
        echo "Composer binary not found after bootstrap (expected /usr/local/bin/composer)." >&2
        exit 1
    fi
    export PHP_BIN="$(php_bin)"
    if [[ -z "${PHP_BIN}" || ! -x "${PHP_BIN}" ]]; then
        echo "PHP CLI binary not found after bootstrap." >&2
        exit 1
    fi
}
