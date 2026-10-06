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

# Import whatever key material <src> carries into a throwaway keyring, then
# re-export ONLY the key whose fingerprint equals the pin, into <dest> (binary
# keyring form). The output is built by gpg itself to contain exactly the pinned
# key — not the fetched bytes filtered by a check — so no second parser can
# disagree with what apt/rpm later consume: a fetch that appends, pads, or
# shadows keys cannot smuggle an extra trusted key through. Returns nonzero if
# the pinned key is absent from the import or the export is empty. An attacker
# cannot forge different key material under the pinned fingerprint (that is a
# hash preimage), and subkeys without a valid binding signature are dropped on
# import.
extract_pinned_key() {
    local src="$1" fingerprint="$2" dest="$3" gnupg rc=0 primaries
    gnupg="$(mktemp -d)"
    chmod 0700 "${gnupg}"
    if ! gpg --homedir "${gnupg}" --batch --quiet --import "${src}" 2>/dev/null; then
        rc=1
    elif ! gpg --homedir "${gnupg}" --batch --list-keys "${fingerprint}" >/dev/null 2>&1; then
        rc=1
    elif ! gpg --homedir "${gnupg}" --batch --yes --export-options export-minimal \
            --export "${fingerprint}" > "${dest}" 2>/dev/null || [[ ! -s "${dest}" ]]; then
        rc=1
    else
        # A gpg key selector matches subkey fingerprints too, so export-by-pin
        # does not by itself guarantee the exported *primary* is the pin (it
        # would otherwise rest on SHA-1 preimage resistance). Assert it directly
        # on the reconstructed keyring: exactly one primary (pub) key, equal to
        # the pin. toupper both sides so case cannot hide a mismatch.
        primaries="$(gpg --batch --with-colons --show-keys "${dest}" 2>/dev/null \
            | awk -F: '$1=="pub"{p=1;next} $1=="fpr"&&p{print toupper($10);p=0} $1=="sub"{p=0}')"
        if [[ "$(printf '%s\n' "${primaries}" | grep -c .)" != "1" \
                || "${primaries}" != "${fingerprint^^}" ]]; then
            rc=1
        fi
    fi
    rm -rf "${gnupg}"
    [[ ${rc} -eq 0 ]] || rm -f "${dest}"
    return ${rc}
}

# Fetch a repo key and install a keyring reconstructed to hold only the pinned
# key. Aborts the install on any fetch or pin failure so a forged or key-padded
# fetch over a hostile path cannot be trusted.
fetch_verify_dearmor() {
    local url="$1" fingerprint="$2" dest="$3" raw
    raw="$(mktemp)"
    if ! curl -fsSL "${url}" -o "${raw}"; then
        rm -f "${raw}"
        echo "Failed to fetch repo key ${url}" >&2
        exit 1
    fi
    if ! extract_pinned_key "${raw}" "${fingerprint}" "${dest}"; then
        rm -f "${raw}" "${dest}"
        echo "Repo key ${url} does not yield pinned key ${fingerprint}; refusing." >&2
        exit 1
    fi
    chmod 0644 "${dest}"
    rm -f "${raw}"
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
            local remi_raw remi_key
            remi_raw="$(mktemp)"; remi_key="$(mktemp)"
            if ! curl -fsSL "${REMI_GPG_URL}" -o "${remi_raw}"; then
                rm -f "${remi_raw}" "${remi_key}"
                echo "Failed to fetch Remi GPG key ${REMI_GPG_URL}" >&2
                exit 1
            fi
            if ! extract_pinned_key "${remi_raw}" "${REMI_GPG_FPR}" "${remi_key}"; then
                rm -f "${remi_raw}" "${remi_key}"
                echo "Remi GPG key does not yield pinned key ${REMI_GPG_FPR}; refusing." >&2
                exit 1
            fi
            rpm --import "${remi_key}"
            rm -f "${remi_raw}" "${remi_key}"
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
    # A66: key verification needs curl + gpg, and setup_repos runs before
    # bootstrap_packages. Ensure both are present (idempotent) so a minimal
    # image cannot skip verification for lack of the gpg binary.
    case "${PKG_MGR}" in
        apt-get)
            export DEBIAN_FRONTEND=noninteractive
            apt-get -o DPkg::Lock::Timeout=120 install -y curl ca-certificates gnupg >/dev/null
            ;;
        dnf)
            dnf -y install curl ca-certificates gnupg2 >/dev/null
            ;;
    esac
    command -v gpg >/dev/null 2>&1 || { echo "gpg is required for repo key verification but is unavailable." >&2; exit 1; }
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
