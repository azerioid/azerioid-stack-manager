#!/usr/bin/env bash
# GPG-verified third-party repos: Caddy, Sury (deb), Remi (EL).
set -euo pipefail

CADDY_GPG_URL="https://dl.cloudsmith.io/public/caddy/stable/gpg.key"
SURY_GPG_URL="https://packages.sury.org/php/apt.gpg"
REMI_GPG_URL="https://rpms.remirepo.net/RPM-GPG-KEY-remi2018"

# A66: pinned primary fingerprints for each repo's signing key. Verified with
# gpg against both the live upstream key and the key already trusted on the
# production host (see verify-release.sh). A10 mandates this check; it was dead
# code until now, so repo keys were trusted on first use.
CADDY_GPG_FPR="65760C51EDEA2017CEA2CA15155B6D79CA56EA34"  # Caddy Web Server <contact@caddyserver.com>
SURY_GPG_FPR="15058500A0235D97F5D10063B188E2B695BD4743"   # DEB.SURY.ORG Automatic Signing Key <deb@sury.org>
REMI_GPG_FPR="6B38FEA7231F87F52B9CA9D8555097595F11735A"   # Remi's RPM repository <remi@remirepo.net>

verify_gpg_key() {
    local keyfile="$1" fingerprint="$2"
    [[ -f "${keyfile}" ]] || return 1
    gpg --show-keys --with-colons "${keyfile}" 2>/dev/null \
        | awk -F: '$1=="fpr" {print $10}' \
        | grep -qiF "${fingerprint}" 2>/dev/null
}

# Fetch an armored key to a temp file, verify it carries the pinned fingerprint,
# then dearmor it into the destination keyring. Aborts the install on any
# mismatch so a forged key fetched over a hostile path cannot be pinned.
fetch_verify_dearmor() {
    local url="$1" fingerprint="$2" dest="$3" tmp
    tmp="$(mktemp)"
    if ! curl -fsSL "${url}" -o "${tmp}"; then
        rm -f "${tmp}"
        echo "Failed to fetch repo key ${url}" >&2
        exit 1
    fi
    if ! verify_gpg_key "${tmp}" "${fingerprint}"; then
        rm -f "${tmp}"
        echo "Repo key ${url} does not match pinned fingerprint ${fingerprint}; refusing." >&2
        exit 1
    fi
    gpg --batch --yes --dearmor -o "${dest}" < "${tmp}"
    rm -f "${tmp}"
}

install_caddy_repo() {
    echo "==> Adding Caddy official repository"
    case "${DISTRO_FAMILY}" in
        ubuntu|debian)
            install -d -m 0755 /usr/share/keyrings
            fetch_verify_dearmor "${CADDY_GPG_URL}" "${CADDY_GPG_FPR}" \
                /usr/share/keyrings/caddy-stable-archive-keyring.gpg
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
            fetch_verify_dearmor "${SURY_GPG_URL}" "${SURY_GPG_FPR}" \
                /usr/share/keyrings/php-sury-archive-keyring.gpg
            echo "deb [signed-by=/usr/share/keyrings/php-sury-archive-keyring.gpg] https://packages.sury.org/php/ ${OS_CODENAME} main" \
                > /etc/apt/sources.list.d/php-sury.list
            ;;
        el)
            # A66: import Remi's signing key pinned by fingerprint, then install
            # the remi-release RPM with localpkg_gpgcheck so dnf verifies the
            # RPM against that key. The old path installed the RPM by URL with
            # `|| true`, so an unsigned or forged bootstrap RPM was trusted.
            local remi_key
            remi_key="$(mktemp)"
            if ! curl -fsSL "${REMI_GPG_URL}" -o "${remi_key}"; then
                rm -f "${remi_key}"
                echo "Failed to fetch Remi GPG key ${REMI_GPG_URL}" >&2
                exit 1
            fi
            if ! verify_gpg_key "${remi_key}" "${REMI_GPG_FPR}"; then
                rm -f "${remi_key}"
                echo "Remi GPG key does not match pinned fingerprint ${REMI_GPG_FPR}; refusing." >&2
                exit 1
            fi
            rpm --import "${remi_key}"
            rm -f "${remi_key}"
            dnf -y --setopt=localpkg_gpgcheck=1 install \
                "https://rpms.remirepo.net/enterprise/remi-release-${OS_MAJOR}.rpm"
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
