#!/usr/bin/env bash
# GPG-verified third-party repos: Caddy, Sury (deb), Remi (EL).
set -euo pipefail

CADDY_GPG_URL="https://dl.cloudsmith.io/public/caddy/stable/gpg.key"
SURY_GPG_URL="https://packages.sury.org/php/apt.gpg"
REMI_GPG_URL="https://rpms.remirepo.net/RPM-GPG-KEY-remirepo"

verify_gpg_key() {
    local keyfile="$1" fingerprint="$2"
    [[ -f "${keyfile}" ]] || return 1
    gpg --show-keys --with-colons "${keyfile}" 2>/dev/null \
        | awk -F: '$1=="fpr" {print $10}' \
        | grep -qi "${fingerprint}" 2>/dev/null
}

install_caddy_repo() {
    echo "==> Adding Caddy official repository"
    case "${DISTRO_FAMILY}" in
        ubuntu|debian)
            install -d -m 0755 /usr/share/keyrings
            curl -fsSL "${CADDY_GPG_URL}" \
                | gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
            echo "deb [signed-by=/usr/share/keyrings/caddy-stable-archive-keyring.gpg] https://dl.cloudsmith.io/public/caddy/stable/deb/${DISTRO_FAMILY} any-version main" \
                > /etc/apt/sources.list.d/caddy-stable.list
            ;;
        el)
            # Official Caddy docs for RHEL/CentOS use COPR. Cloudsmith rpm/el/$releasever
            # 404s on AlmaLinux 9.8 (releasever=9.8) and rpm/el/9 is an empty 2020 stub,
            # so dnf silently installs EPEL Caddy 2.6.4.
            echo "==> Enabling @caddy/caddy COPR (EL official install path)"
            dnf -y install dnf-plugins-core >/dev/null
            dnf -y copr enable @caddy/caddy >/dev/null
            ;;
    esac
}

install_php_repo() {
    echo "==> Adding PHP repository (Sury/Remi)"
    case "${DISTRO_FAMILY}" in
        ubuntu|debian)
            install -d -m 0755 /usr/share/keyrings
            curl -fsSL "${SURY_GPG_URL}" \
                | gpg --batch --yes --dearmor -o /usr/share/keyrings/php-sury-archive-keyring.gpg
            echo "deb [signed-by=/usr/share/keyrings/php-sury-archive-keyring.gpg] https://packages.sury.org/php/ ${OS_CODENAME} main" \
                > /etc/apt/sources.list.d/php-sury.list
            ;;
        el)
            dnf -y install https://rpms.remirepo.net/enterprise/remi-release-"${OS_MAJOR}".rpm >/dev/null 2>&1 || true
            dnf -y module reset php >/dev/null 2>&1 || true
            dnf -y module enable php:remi-8.4 >/dev/null 2>&1 || \
                dnf -y module enable php:remi-8.3 >/dev/null 2>&1 || true
            ;;
    esac
}

setup_repos() {
    install -d -m 0755 /etc/apt/keyrings 2>/dev/null || true
    install_caddy_repo
    install_php_repo
    case "${PKG_MGR}" in
        apt-get)
            export DEBIAN_FRONTEND=noninteractive
            apt-get -o DPkg::Lock::Timeout=120 update -qq
            ;;
        dnf)
            dnf -y makecache >/dev/null
            ;;
    esac
}
