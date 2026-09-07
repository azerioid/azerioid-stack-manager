#!/usr/bin/env bash
# Panel Caddy vhost snippet (localhost tunnel default; optional public HTTPS).
set -euo pipefail

CADDY_CONFD="${CADDY_CONFD:-/etc/caddy/conf.d}"
CADDYFILE='/etc/caddy/Caddyfile'

caddy_port_listening() {
    local p="$1"
    ss -tln 2>/dev/null | awk '{print $4}' | grep -Eq ":${p}$"
}

detect_public_ip() {
    local ip=""
    ip="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "src") print $(i + 1)}' | head -n1)"
    if [[ -n "${ip}" && "${ip}" != 127.* ]]; then
        echo "${ip}"
        return 0
    fi
    ip="$(hostname -I 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i !~ /^127\./) { print $i; exit }}')"
    [[ -n "${ip}" ]] || return 1
    echo "${ip}"
}

ensure_caddy_imports_conf() {
    install -d -m 0755 /etc/caddy "${CADDY_CONFD}"
    # Panel uses tls internal (self-signed) on public IP mode. Chrome often accepts the
    # warning for the document request over h2, then retries /livewire/update over HTTP/3
    # (alt-svc) and fails with net::ERR_CERT_AUTHORITY_INVALID — login never completes.
    # Force h1/h2 only so Livewire stays same-connection as the page the operator trusted.
    local desired=$'{
    admin off
    servers {
        protocols h1 h2
    }
}
import '"${CADDY_CONFD}"'/*.conf
'
    if [[ ! -f "${CADDYFILE}" ]]; then
        printf '%s' "${desired}" > "${CADDYFILE}"
        chown root:root "${CADDYFILE}"
        return 0
    fi
    if ! grep -qE 'protocols[[:space:]]+h1[[:space:]]+h2' "${CADDYFILE}"; then
        # Rewrite / merge global options without dropping an existing import line.
        if grep -qE 'import[[:space:]]+.*conf\.d' "${CADDYFILE}"; then
            # Replace leading global block if present; otherwise prepend.
            if grep -qE '^[[:space:]]*\{' "${CADDYFILE}"; then
                python3 - "${CADDYFILE}" "${CADDY_CONFD}" <<'PY'
import pathlib, re, sys
path, confd = pathlib.Path(sys.argv[1]), sys.argv[2]
text = path.read_text()
global_block = """{
    admin off
    servers {
        protocols h1 h2
    }
}
"""
# Drop existing top-level {...} global options only (first brace group).
m = re.match(r'(?s)^\s*\{.*?\n\}\s*', text)
rest = text[m.end():] if m else text
if not re.search(r'import\s+.*conf\.d', rest):
    rest = f"import {confd}/*.conf\n" + rest
path.write_text(global_block + rest.lstrip("\n"))
PY
            else
                printf '%s\n' "${desired%$'\n'}" > "${CADDYFILE}.new"
                # Keep previous body after ensuring import once
                if ! grep -qE 'import[[:space:]]+.*conf\.d' "${CADDYFILE}"; then
                    echo "import ${CADDY_CONFD}/*.conf" >> "${CADDYFILE}.new"
                fi
                cat "${CADDYFILE}" >> "${CADDYFILE}.new"
                mv "${CADDYFILE}.new" "${CADDYFILE}"
            fi
        else
            printf '%s' "${desired}" > "${CADDYFILE}"
        fi
        chown root:root "${CADDYFILE}"
    elif ! grep -qE 'import[[:space:]]+.*conf\.d' "${CADDYFILE}"; then
        cat >> "${CADDYFILE}" <<EOF

# AZERIOID Stack Manager — load managed vhost snippets
import ${CADDY_CONFD}/*.conf
EOF
    fi
}

configure_panel_caddy() {
    [[ "${SKIP_CADDY:-0}" -eq 0 ]] || { echo "Caddy snippet skipped"; return 0; }
    echo "==> Configuring panel Caddy vhost on 127.0.0.1:${PANEL_PORT}"

    local caddy_user="${WEB_USER}"
    if command -v systemctl >/dev/null 2>&1; then
        caddy_user="$(systemctl show caddy -p User --value 2>/dev/null || true)"
        [[ -n "${caddy_user}" && "${caddy_user}" != "root" ]] || caddy_user="${WEB_USER}"
    fi

    install -d -m 0755 -o "${caddy_user}" -g "${caddy_user}" /var/log/caddy
    touch /var/log/caddy/access_azerioid-panel.log
    chown "${caddy_user}:${caddy_user}" /var/log/caddy/access_azerioid-panel.log
    chmod 0640 /var/log/caddy/access_azerioid-panel.log
    chmod 0755 /var/log/caddy

    install -d -m 0755 "${CADDY_CONFD}"
    local snippet="${CADDY_CONFD}/azerioid-panel.conf"
    local public_ip="${PANEL_PUBLIC_IP:-}"
    local public_domain="${PANEL_PUBLIC_DOMAIN:-}"
    if [[ "${ACCESS:-tunnel}" == "public" ]]; then
        if [[ -z "${public_domain}" && -z "${public_ip}" ]]; then
            public_ip="$(detect_public_ip || true)"
            # Keep installer / APP_URL in sync when IP was only detected here.
            if [[ -n "${public_ip}" ]]; then
                PANEL_PUBLIC_IP="${public_ip}"
                export PANEL_PUBLIC_IP
            fi
        fi
        [[ -n "${public_domain}" || -n "${public_ip}" ]] \
            || die "Could not detect public IP for --access=public (set --domain= or confirm IP in interactive setup)"
        if [[ -n "${public_domain}" ]]; then
            echo "==> Public panel HTTPS on ${public_domain}:${PANEL_PORT}"
        else
            echo "==> Public panel HTTPS on ${public_ip}:${PANEL_PORT}"
        fi
        # Re-assert APP_URL after IP detection (configure_panel_db may have run with empty IP).
        if [[ -f "${PREFIX}/web/.env" ]]; then
            local app_url
            if [[ -n "${public_domain}" ]]; then
                app_url="https://${public_domain}:${PANEL_PORT}"
            else
                app_url="https://${public_ip}:${PANEL_PORT}"
            fi
            env_set "${PREFIX}/web/.env" APP_URL "${app_url}"
        fi
    fi

    python3 - "${snippet}" "${PREFIX}" "${PANEL_PORT}" "${ACCESS:-tunnel}" "${public_ip}" "${public_domain}" <<'PY'
import pathlib, sys
snippet, prefix, port, access, public_ip, public_domain = sys.argv[1:7]
web = prefix + "/web/public"
sock = "unix//run/php/azerioid-panel.sock"
common = f"""    encode gzip zstd
    import /var/lib/azerioid-panel/caddy-terminal-routes.conf
    root * {web}
    php_fastcgi {sock}
    file_server
    header {{
        X-Content-Type-Options nosniff
        X-Frame-Options DENY
        Referrer-Policy no-referrer
        -Server
        -Alt-Svc
    }}
    log {{
        output file /var/log/caddy/access_azerioid-panel.log {{
            roll_size 16mb
            roll_keep 3
            roll_keep_for 7d
        }}
    }}"""
blocks = [f"""# AZERIOID Stack Manager panel vhost (generated by install.sh)
http://127.0.0.1:{port} {{
    bind 127.0.0.1
{common}
}}
"""]
if access == "public":
    host = public_domain.strip() if public_domain.strip() else public_ip.strip()
    if host:
        blocks.append(f"""https://{host}:{port} {{
    tls internal
{common}
}}
""")
pathlib.Path(snippet).write_text("".join(blocks))
PY
    chmod 0644 "${snippet}"

    ensure_caddy_imports_conf

    if command -v caddy >/dev/null 2>&1; then
        caddy validate --config "${CADDYFILE}" || { echo "Caddy validate failed" >&2; return 1; }
        systemctl enable --now caddy 2>/dev/null || true
        systemctl reload caddy 2>/dev/null || systemctl restart caddy 2>/dev/null || true
    fi

    local i
    for i in $(seq 1 30); do
        caddy_port_listening "${PANEL_PORT}" && return 0
        sleep 0.3
    done
    echo "Warning: port ${PANEL_PORT} not listening after Caddy reload" >&2
}
