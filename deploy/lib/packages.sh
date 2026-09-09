#!/usr/bin/env bash
# Bootstrap packages: Caddy, PHP 8.4 FPM, SQLite, composer deps.
set -euo pipefail

bootstrap_packages() {
    echo "==> Installing bootstrap packages"
    case "${PKG_MGR}" in
        apt-get)
            export DEBIAN_FRONTEND=noninteractive
            apt-get -o DPkg::Lock::Timeout=120 install -y \
                caddy sqlite3 curl ca-certificates gnupg rsync \
                "php${PANEL_PHP_VERSION}-fpm" "php${PANEL_PHP_VERSION}-cli" \
                "php${PANEL_PHP_VERSION}-sqlite3" "php${PANEL_PHP_VERSION}-mysql" \
                "php${PANEL_PHP_VERSION}-pgsql" "php${PANEL_PHP_VERSION}-mbstring" \
                "php${PANEL_PHP_VERSION}-xml" "php${PANEL_PHP_VERSION}-curl" \
                "php${PANEL_PHP_VERSION}-zip" "php${PANEL_PHP_VERSION}-bcmath" \
                unzip git
            ;;
        dnf)
            dnf -y install caddy sqlite curl ca-certificates gnupg2 unzip git rsync \
                php-fpm php-cli php-process php-sqlite3 php-mysqlnd php-pgsql php-mbstring php-xml php-curl \
                php-zip php-bcmath policycoreutils-python-utils checkpolicy >/dev/null
            ;;
    esac

    if ! command -v composer >/dev/null 2>&1; then
        echo "==> Installing Composer"
        curl -fsSL https://getcomposer.org/installer \
            | "$(php_bin)" -- --install-dir=/usr/local/bin --filename=composer
        chmod 0755 /usr/local/bin/composer
    fi
    export COMPOSER_BIN="$(command -v composer)"
    export PHP_BIN="$(php_bin)"
}
