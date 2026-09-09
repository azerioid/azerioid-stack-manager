#!/usr/bin/env bash
# fail2ban, ufw/firewalld — carried forward from v1 installer.
set -euo pipefail

flush_panel_fail2ban_bans() {
    command -v fail2ban-client >/dev/null 2>&1 || return 0
    fail2ban-client status azerioid-panel >/dev/null 2>&1 || return 0
    local banned ip
    banned="$(fail2ban-client status azerioid-panel 2>/dev/null | sed -n 's/.*Banned IP list:[[:space:]]*//p')"
    for ip in ${banned}; do
        [[ -n "${ip}" ]] || continue
        fail2ban-client set azerioid-panel unbanip "${ip}" >/dev/null 2>&1 || true
    done
}

reset_panel_fail2ban_log() {
    install -d -m 0750 /var/log/azerioid-panel
    install -m 0640 /dev/null /var/log/azerioid-panel/auth-fail.log
    chown "${WEB_USER}:${WEB_USER}" /var/log/azerioid-panel/auth-fail.log 2>/dev/null || true
}

install_security() {
    if [[ "${DO_FAIL2BAN:-false}" == "true" ]]; then
        echo "==> fail2ban jail for panel failed logins"
        flush_panel_fail2ban_bans
        reset_panel_fail2ban_log
        case "${PKG_MGR}" in
            apt-get)
                export DEBIAN_FRONTEND=noninteractive
                apt-get -o DPkg::Lock::Timeout=120 install -y fail2ban >/dev/null
                ;;
            dnf) dnf -y install fail2ban >/dev/null 2>&1 || true ;;
        esac
        install -d -m 0755 /etc/fail2ban/filter.d /etc/fail2ban/jail.d
        install -m 0644 "${ROOT}/deploy/fail2ban/filter.d/azerioid-panel.conf" \
            /etc/fail2ban/filter.d/azerioid-panel.conf
        cat > /etc/fail2ban/jail.d/azerioid-panel.conf <<EOF
[azerioid-panel]
enabled  = true
filter   = azerioid-panel
logpath  = /var/log/azerioid-panel/auth-fail.log
backend  = auto
maxretry = 5
findtime = 600
bantime  = 3600
port     = ${PANEL_PORT}
EOF
        systemctl enable --now fail2ban 2>/dev/null || true
        systemctl reload fail2ban 2>/dev/null || systemctl restart fail2ban 2>/dev/null || true
    fi

    if [[ "${DO_FIREWALL:-false}" == "true" ]]; then
        echo "==> Firewall rule for panel port ${PANEL_PORT}"
        case "${PKG_MGR}" in
            apt-get)
                if ! command -v ufw >/dev/null 2>&1; then
                    echo "==> Installing ufw (not present on this Debian/Ubuntu image)"
                    export DEBIAN_FRONTEND=noninteractive
                    apt-get -o DPkg::Lock::Timeout=120 install -y ufw >/dev/null
                fi
                ;;
            dnf)
                if ! command -v firewall-cmd >/dev/null 2>&1; then
                    echo "==> Installing firewalld (not present on this EL image)"
                    dnf -y install firewalld >/dev/null
                fi
                if ! systemctl is-active firewalld >/dev/null 2>&1; then
                    echo "==> Enabling firewalld"
                    systemctl enable --now firewalld >/dev/null
                fi
                ;;
        esac
        if command -v ufw >/dev/null 2>&1; then
            # Allow SSH before enabling default-deny, or a remote install locks itself out.
            ufw allow OpenSSH >/dev/null 2>&1 || ufw allow 22/tcp comment 'azerioid-ssh' >/dev/null 2>&1 || true
            ufw allow 80/tcp comment 'azerioid-http' >/dev/null 2>&1 || true
            ufw allow 443/tcp comment 'azerioid-https' >/dev/null 2>&1 || true
            ufw allow "${PANEL_PORT}/tcp" comment 'stack-manager' >/dev/null 2>&1 || true
            ufw --force enable >/dev/null 2>&1 || true
        elif command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active firewalld >/dev/null 2>&1; then
            firewall-cmd --permanent --add-service=ssh >/dev/null 2>&1 || true
            firewall-cmd --permanent --add-service=http >/dev/null 2>&1 || true
            firewall-cmd --permanent --add-service=https >/dev/null 2>&1 || true
            firewall-cmd --permanent --add-port="${PANEL_PORT}/tcp" >/dev/null
            firewall-cmd --reload >/dev/null
        else
            echo "Warning: --firewall=true but neither ufw nor firewalld is available." >&2
        fi
    fi

    install -d -m 0750 /etc/azerioid-panel
    cat > /etc/azerioid-panel/access.env <<EOF
ACCESS_MODE=${ACCESS:-tunnel}
PANEL_PORT=${PANEL_PORT}
STACK=bootstrap
WEB_SERVICE=caddy
EOF
    chmod 0640 /etc/azerioid-panel/access.env
}
