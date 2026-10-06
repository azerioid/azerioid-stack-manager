#!/usr/bin/env bash
# Post-release verification: panel self-update (optional) + regression spot-checks.
#
# Run from an operator workstation with SSH access to the fleet host.
#
# Usage:
#   ./deploy/verify-release.sh [--from-version=X.Y.Z] [--apply] [--ssh-identity=PATH] HOST
#
# Examples:
#   ./deploy/verify-release.sh --from-version=1.5.0 --apply root@64.226.78.176
#   ./deploy/verify-release.sh root@64.226.78.176
#
# Each check prints [PASS] or [FAIL]. Exit 1 if any check failed.
set -euo pipefail

FROM_VERSION=""
APPLY=0
SSH_IDENTITY="${SSH_IDENTITY:-$HOME/.ssh/lcmp-agent}"
PANEL_PORT="${PANEL_PORT:-3169}"
OCTANE_DOMAIN="${OCTANE_DOMAIN:-octane-demo.64.226.78.176.nip.io}"
PM2_DOMAIN="${PM2_DOMAIN:-pm2-su.64.226.78.176.nip.io}"
HOST=""

usage() {
    sed -n '2,12p' "$0"
    exit 2
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --from-version=*) FROM_VERSION="${1#*=}"; shift ;;
        --from-version)
            FROM_VERSION="${2:?}"; shift 2 ;;
        --apply) APPLY=1; shift ;;
        --ssh-identity=*) SSH_IDENTITY="${1#*=}"; shift ;;
        --panel-port=*) PANEL_PORT="${1#*=}"; shift ;;
        --octane-domain=*) OCTANE_DOMAIN="${1#*=}"; shift ;;
        --pm2-domain=*) PM2_DOMAIN="${1#*=}"; shift ;;
        -h|--help) usage ;;
        --) shift; break ;;
        -*)
            echo "Unknown option: $1" >&2
            usage
            ;;
        *)
            if [[ -z "${HOST}" ]]; then
                HOST="$1"
            else
                echo "Unexpected argument: $1" >&2
                usage
            fi
            shift
            ;;
    esac
done

[[ -n "${HOST}" ]] || usage

SSH=(ssh -i "${SSH_IDENTITY}" -o IdentitiesOnly=yes -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new)

FAILURES=0
pass() { echo "[PASS] $*"; }
fail() { echo "[FAIL] $*" >&2; FAILURES=$((FAILURES + 1)); }

echo "==> AZERIOID release verification on ${HOST}"
echo "    from-version=${FROM_VERSION:-<any>} apply=${APPLY} panel-port=${PANEL_PORT}"

REMOTE_SCRIPT=$(cat <<'EOS'
set -uo pipefail
PREFIX=/usr/local/lib/azerioid-panel
PANEL_PORT=__PANEL_PORT__
FROM_VERSION='__FROM_VERSION__'
APPLY=__APPLY__
OCTANE_DOMAIN='__OCTANE_DOMAIN__'
PM2_DOMAIN='__PM2_DOMAIN__'

read_deployed() {
    VERSION="$(cat "${PREFIX}/VERSION" 2>/dev/null || echo unknown)"
    TAG="$(cat "${PREFIX}/TAG" 2>/dev/null || echo none)"
    COMMIT="$(cat "${PREFIX}/COMMIT" 2>/dev/null || echo none)"
}

read_deployed
echo "DEPLOYED_VERSION=${VERSION}"
echo "DEPLOYED_TAG=${TAG}"
echo "DEPLOYED_COMMIT=${COMMIT}"

if [[ -n "${FROM_VERSION}" && "${VERSION}" != "${FROM_VERSION}" ]]; then
    echo "FROM_VERSION_MISMATCH=1 expected=${FROM_VERSION} got=${VERSION}"
else
    echo "FROM_VERSION_MISMATCH=0"
fi

echo "=== panel update check ==="
CHECK_OUT="$(azerioid panel update check 2>&1)" || true
printf '%s\n' "${CHECK_OUT}" | tail -20
UPDATE_AVAILABLE=0
if printf '%s' "${CHECK_OUT}" | grep -q 'Update available'; then
    UPDATE_AVAILABLE=1
fi
echo "UPDATE_AVAILABLE=${UPDATE_AVAILABLE}"

if [[ "${APPLY}" -eq 1 ]]; then
    if [[ "${UPDATE_AVAILABLE}" -eq 1 ]]; then
        echo "=== panel update apply ==="
        azerioid panel update apply --confirm 2>&1 | tail -30 || echo "UPDATE_APPLY_FAILED=1"
    else
        echo "UPDATE_APPLY_SKIPPED=already_up_to_date"
    fi
    read_deployed
    echo "POST_UPDATE_VERSION=${VERSION}"
    echo "POST_UPDATE_TAG=${TAG}"
    echo "POST_UPDATE_COMMIT=${COMMIT}"
fi

echo "=== spot-check: panel HTTP ==="
if curl -fsSI "http://127.0.0.1:${PANEL_PORT}/" 2>/dev/null | head -n1 | grep -qE 'HTTP/[0-9.]+ (200|302|301)'; then
    echo "CHECK_PANEL_HTTP=pass"
else
    echo "CHECK_PANEL_HTTP=fail"
fi

echo "=== spot-check: mail ==="
MAIL_OUT="$(azerioid mail status 2>&1)" || true
printf '%s\n' "${MAIL_OUT}" | head -12
MAIL_OK=1
for svc in postfix dovecot opendkim; do
    if ! printf '%s' "${MAIL_OUT}" | grep -q "${svc}.*active"; then
        MAIL_OK=0
    fi
done
if [[ "${MAIL_OK}" -eq 1 ]]; then
    echo "CHECK_MAIL=pass"
else
    echo "CHECK_MAIL=fail"
fi

echo "=== spot-check: PM2 vhost ==="
PM2_STATUS="$(azerioid vhost list 2>&1 | grep -F "${PM2_DOMAIN}" || true)"
printf '%s\n' "${PM2_STATUS}"
if printf '%s' "${PM2_STATUS}" | grep -q 'pm2:'; then
    echo "CHECK_PM2_RUNTIME=pass"
else
    echo "CHECK_PM2_RUNTIME=fail"
fi
if curl -fsSI -k "https://${PM2_DOMAIN}/" 2>/dev/null | head -n1 | grep -qE 'HTTP/[0-9.]+ (200|301|302)'; then
    echo "CHECK_PM2_HTTP=pass"
elif curl -fsSI "http://${PM2_DOMAIN}/" 2>/dev/null | head -n1 | grep -qE 'HTTP/[0-9.]+ (200|301|302)'; then
    echo "CHECK_PM2_HTTP=pass"
else
    echo "CHECK_PM2_HTTP=fail"
fi

echo "=== spot-check: Octane demo ==="
OCTANE_ROW="$(azerioid vhost list 2>&1 | grep -F "${OCTANE_DOMAIN}" || true)"
printf '%s\n' "${OCTANE_ROW}"
if printf '%s' "${OCTANE_ROW}" | grep -q 'octane:'; then
    echo "CHECK_OCTANE_RUNTIME=pass"
else
    echo "CHECK_OCTANE_RUNTIME=fail"
fi
if curl -fsSI -k "https://${OCTANE_DOMAIN}/" 2>/dev/null | head -n1 | grep -qE 'HTTP/[0-9.]+ (200|301|302)'; then
    echo "CHECK_OCTANE_HTTP=pass"
elif curl -fsSI "http://${OCTANE_DOMAIN}/" 2>/dev/null | head -n1 | grep -qE 'HTTP/[0-9.]+ (200|301|302)'; then
    echo "CHECK_OCTANE_HTTP=pass"
else
    echo "CHECK_OCTANE_HTTP=fail"
fi

echo "=== spot-check: Adminer component + auth gate ==="
ADMINER_ROW="$(azerioid component list 2>&1 | grep -E '[|] adminer[[:space:]]' || true)"
printf '%s\n' "${ADMINER_ROW}"
if printf '%s' "${ADMINER_ROW}" | grep -qi 'installed'; then
    echo "CHECK_ADMINER_COMPONENT=pass"
else
    echo "CHECK_ADMINER_COMPONENT=fail"
fi
ADMINER_CODE="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PANEL_PORT}/tools/adminer/" 2>/dev/null || echo 000)"
echo "ADMINER_HTTP_CODE=${ADMINER_CODE}"
if [[ "${ADMINER_CODE}" == "401" ]]; then
    echo "CHECK_ADMINER_AUTH=pass"
else
    echo "CHECK_ADMINER_AUTH=fail"
fi

echo "=== spot-check: hardening ==="
F2B="$(systemctl is-active fail2ban 2>/dev/null || echo inactive)"
echo "FAIL2BAN=${F2B}"
if [[ "${F2B}" == "active" ]]; then
    echo "CHECK_FAIL2BAN=pass"
else
    echo "CHECK_FAIL2BAN=fail"
fi
SSHD_PA="$(sshd -T 2>/dev/null | awk '/^passwordauthentication /{print $2; exit}')"
echo "SSHD_PASSWORDAUTH=${SSHD_PA:-unknown}"
if [[ "${SSHD_PA}" == "no" ]]; then
    echo "CHECK_SSHD=pass"
else
    echo "CHECK_SSHD=fail"
fi

echo "=== spot-check: panel identity (ADR A39 Part A) ==="
# After an --apply the scheduler starts the migration within ~5 minutes of the
# deploy; give it that long before calling a pending host a failure.
ID_OUT=""
for _ in $(seq 1 45); do
    ID_OUT="$("${PREFIX}/broker" panel.identity.status </dev/null 2>&1)" || true
    printf '%s' "${ID_OUT}" | grep -q '"migrated":true' && break
    [[ "${APPLY}" == "1" ]] || break
    printf '%s' "${ID_OUT}" | grep -q '"result":"failed"' && break
    sleep 10
done
printf '%s\n' "${ID_OUT}" | head -c 600
echo
if printf '%s' "${ID_OUT}" | grep -q '"migrated":true'; then
    echo "CHECK_PANEL_IDENTITY=pass"
else
    echo "CHECK_PANEL_IDENTITY=fail"
fi
echo "=== spot-check: vhost isolation (ADR A49) ==="
# Same timing as the identity migration: the scheduler starts it within ~5 minutes.
ISO_OUT=""
for _ in $(seq 1 45); do
    ISO_OUT="$("${PREFIX}/broker" vhost.isolation.status </dev/null 2>&1)" || true
    printf '%s' "${ISO_OUT}" | grep -q '"migrated":true' && break
    [[ "${APPLY}" == "1" ]] || break
    # A failure only ends the wait when it blocks automatic retries; a failure recorded by
    # an older release is retried by this one (A49-E1).
    printf '%s' "${ISO_OUT}" | grep -q '"auto_eligible":false' \
        && ! printf '%s' "${ISO_OUT}" | grep -q '"running":true' \
        && printf '%s' "${ISO_OUT}" | grep -q '"result":"failed"' && break
    sleep 10
done
printf '%s\n' "${ISO_OUT}" | head -c 400
echo
if printf '%s' "${ISO_OUT}" | grep -q '"migrated":true'; then
    echo "CHECK_VHOST_ISOLATION=pass"
else
    echo "CHECK_VHOST_ISOLATION=fail"
fi
# Adversarial, not a status read: one site's identity must be refused another site's docroot.
mapfile -t VH_IDS < <(getent passwd | awk -F: '$1 ~ /^az-vh-/ && $6 ~ /^\/data\/www\// {print $1":"$6}')
CROSS_OPEN=""
CROSS_TRIED=0
for a in "${VH_IDS[@]}"; do
    for b in "${VH_IDS[@]}"; do
        [[ "${a}" == "${b}" ]] && continue
        [[ -d "${b#*:}" ]] || continue
        [[ "$(( $(stat -c '%a' "${b#*:}") % 10 & 4 ))" == "0" ]] || continue
        CROSS_TRIED=$((CROSS_TRIED + 1))
        runuser -u "${a%%:*}" -- test -r "${b#*:}" 2>/dev/null && CROSS_OPEN="${CROSS_OPEN} ${a%%:*}->${b#*:}"
        [[ "${CROSS_TRIED}" -ge 40 ]] && break 2
    done
done
echo "VHOST_CROSS_READS_TRIED=${CROSS_TRIED}"
if [[ -z "${CROSS_OPEN}" ]]; then
    echo "CHECK_VHOST_CROSS_READ_REFUSED=pass"
else
    echo "VHOST_CROSS_READ_OPEN=${CROSS_OPEN}"
    echo "CHECK_VHOST_CROSS_READ_REFUSED=fail"
fi
echo "=== spot-check: per-site PHP pools (ADR A55) ==="
# Started by the scheduler like A49; a site put back on the shared pool is reported, not waited on.
POOL_OUT=""
for _ in $(seq 1 45); do
    POOL_OUT="$("${PREFIX}/broker" vhost.phppool.status </dev/null 2>&1)" || true
    printf '%s' "${POOL_OUT}" | grep -q '"migrated":true' && break
    [[ "${APPLY}" == "1" ]] || break
    printf '%s' "${POOL_OUT}" | grep -q '"auto_eligible":false' \
        && ! printf '%s' "${POOL_OUT}" | grep -q '"running":true' && break
    sleep 10
done
printf '%s\n' "${POOL_OUT}" | head -c 400
echo
if printf '%s' "${POOL_OUT}" | grep -q '"migrated":true'; then
    echo "CHECK_SITE_PHP_POOLS=pass"
else
    echo "CHECK_SITE_PHP_POOLS=fail"
fi
# Every site pool must run as a site identity, never as a shared or privileged account.
POOL_BAD=""
for f in /etc/php/*/fpm/pool.d/azv-*.conf /etc/php-fpm.d/azv-*.conf /etc/opt/remi/php*/php-fpm.d/azv-*.conf; do
    [[ -f "${f}" ]] || continue
    grep -Eq '^user = az-vh-' "${f}" || POOL_BAD="${POOL_BAD} ${f}"
done
if [[ -z "${POOL_BAD}" ]]; then
    echo "CHECK_SITE_POOL_USERS=pass"
else
    echo "SITE_POOL_USER_WRONG=${POOL_BAD}"
    echo "CHECK_SITE_POOL_USERS=fail"
fi
echo "=== spot-check: site programs run as their site (ADR A56) ==="
PROG_OUT=""
for _ in $(seq 1 45); do
    PROG_OUT="$("${PREFIX}/broker" program.identity.status </dev/null 2>&1)" || true
    printf '%s' "${PROG_OUT}" | grep -q '"migrated":true' && break
    [[ "${APPLY}" == "1" ]] || break
    printf '%s' "${PROG_OUT}" | grep -q '"auto_eligible":false' \
        && ! printf '%s' "${PROG_OUT}" | grep -q '"running":true' && break
    sleep 10
done
printf '%s\n' "${PROG_OUT}" | head -c 400
echo
if printf '%s' "${PROG_OUT}" | grep -q '"migrated":true'; then
    echo "CHECK_PROGRAM_IDENTITY=pass"
else
    echo "CHECK_PROGRAM_IDENTITY=fail"
fi
# Octane and PM2 workers must never run as the shared account again.
PROG_SHARED=""
for f in /etc/supervisor/conf.d/azerioid-octane-*.conf /etc/supervisor/conf.d/azerioid-pm2-*.conf \
    /etc/supervisor/conf.d/azerioid-docker-*.conf /etc/supervisord.d/azerioid-octane-*.ini \
    /etc/supervisord.d/azerioid-pm2-*.ini /etc/supervisord.d/azerioid-docker-*.ini; do
    [[ -f "${f}" ]] || continue
    grep -Eq '^user=az-vh-' "${f}" || PROG_SHARED="${PROG_SHARED} ${f}"
done
if [[ -z "${PROG_SHARED}" ]]; then
    echo "CHECK_SITE_WORKERS_AS_SITE=pass"
else
    echo "SITE_WORKERS_SHARED=${PROG_SHARED}"
    echo "CHECK_SITE_WORKERS_AS_SITE=fail"
fi
# Part 3: the shared account is in no site's group once every site program has moved.
SUP_GROUPS="$(id -nG azerioid-supervised 2>/dev/null | tr ' ' '\n' | grep -c '^az-vh-' || true)"
echo "SUPERVISED_SITE_GROUPS=${SUP_GROUPS}"
# A process keeps the groups it started with: none of the shared account's may hold a site group.
SITE_GIDS=" $(getent group | awk -F: '$1 ~ /^az-vh-/ {printf "%s ", $3}')"
SUP_PROC_SITE=0
for pid in $(pgrep -u azerioid-supervised 2>/dev/null); do
    for g in $(awk '/^Groups:/ {for (i = 2; i <= NF; i++) print $i}' "/proc/${pid}/status" 2>/dev/null); do
        [[ "${SITE_GIDS}" == *" ${g} "* ]] && SUP_PROC_SITE=$((SUP_PROC_SITE + 1))
    done
done
echo "SUPERVISED_PROCESS_SITE_GROUPS=${SUP_PROC_SITE}"
if { [[ "${SUP_GROUPS}" == "0" ]] && [[ "${SUP_PROC_SITE}" == "0" ]]; } || ! printf '%s' "${PROG_OUT}" | grep -q '"migrated":true'; then
    echo "CHECK_SUPERVISED_OUT_OF_SITES=pass"
else
    echo "CHECK_SUPERVISED_OUT_OF_SITES=fail"
fi
if [[ "$(systemctl is-active azerioid-panel-php-fpm.service 2>/dev/null)" == "active" ]]; then
    echo "CHECK_PANEL_OWN_MASTER=pass"
else
    echo "CHECK_PANEL_OWN_MASTER=fail"
fi
# Nobody but the panel account may reach the broker: not Caddy, not site PHP.
GRANTED=""
for u in caddy www-data apache nginx; do
    id -u "${u}" >/dev/null 2>&1 || continue
    sudo -l -U "${u}" 2>/dev/null | grep -q "${PREFIX}/broker" && GRANTED="${GRANTED} ${u}"
done
echo "BROKER_GRANT_OUTSIDE_PANEL=${GRANTED:-none}"
if [[ -z "${GRANTED}" ]]; then
    echo "CHECK_BROKER_GRANT_ONLY_PANEL=pass"
else
    echo "CHECK_BROKER_GRANT_ONLY_PANEL=fail"
fi
# Caddy serves the panel but must not be able to read its secrets.
if id -u caddy >/dev/null 2>&1; then
    if runuser -u caddy -- test -r "${PREFIX}/web/.env" 2>/dev/null; then
        echo "CHECK_CADDY_CANNOT_READ_PANEL_ENV=fail"
    else
        echo "CHECK_CADDY_CANNOT_READ_PANEL_ENV=pass"
    fi
fi
# A60: the panel FPM socket must not be owned by the panel worker user with a
# web-tier group — that is the group-caddy model that let any gid-caddy pool
# (Adminer) speak FastCGI to it and run code as the root-equivalent panel user.
# After A60 the socket is owned by the web user and grouped to the panel user;
# pre-migration it is the web user on both (same principal). Fail only the
# vulnerable shape: owner == panel worker and group != panel worker.
panel_sock=/run/php/azerioid-panel.sock
if [[ -S "${panel_sock}" ]]; then
    sock_owner="$(stat -c %U "${panel_sock}")"
    sock_group="$(stat -c %G "${panel_sock}")"
    panel_worker=""
    for pf in /etc/azerioid-panel/php-fpm.d/azerioid-panel.conf /etc/php/*/fpm/pool.d/azerioid-panel.conf /etc/opt/remi/*/php-fpm.d/azerioid-panel.conf /etc/php-fpm.d/azerioid-panel.conf; do
        [[ -f "${pf}" ]] || continue
        panel_worker="$(sed -n 's/^user[[:space:]]*=[[:space:]]*//p' "${pf}" | head -1)"
        [[ -n "${panel_worker}" ]] && break
    done
    echo "PANEL_SOCKET=${sock_owner}:${sock_group} worker=${panel_worker}"
    if [[ -n "${panel_worker}" && "${sock_owner}" == "${panel_worker}" && "${sock_group}" != "${panel_worker}" ]]; then
        echo "CHECK_PANEL_SOCKET_NOT_WEB_GROUP=fail"
    else
        echo "CHECK_PANEL_SOCKET_NOT_WEB_GROUP=pass"
    fi
fi
# The distro php.ini carries the operator's own disable_functions again. Only
# the FPM-only ini of Debian/Ubuntu: on EL/Remi the file also serves the CLI
# (queue worker, scheduler), so the migrator deliberately leaves it alone.
for ini in /etc/php/*/fpm/php.ini; do
    [[ -f "${ini}.azerioid-panel.bak" ]] || continue
    if [[ "$(grep -m1 '^disable_functions' "${ini}")" == "$(grep -m1 '^disable_functions' "${ini}.azerioid-panel.bak")" ]]; then
        echo "CHECK_DISTRO_PHPINI_RESTORED=pass"
    else
        echo "CHECK_DISTRO_PHPINI_RESTORED=fail"
    fi
done

echo "=== spot-check: database broker ==="
DB_OUT="$("${PREFIX}/broker" db.engine </dev/null 2>&1)" || true
if printf '%s' "${DB_OUT}" | grep -q '"ok":true'; then
    echo "CHECK_DB_ENGINE=pass"
else
    echo "CHECK_DB_ENGINE=fail"
fi
printf '%s\n' "${DB_OUT}" | head -c 400
echo

echo "=== spot-check: default site (real TLS handshake, not config shape) ==="
# This check exists because the :443 catch-all shipped broken twice and the test suite could
# not see it either time: both broken forms were valid configurations Caddy accepted, and
# `caddy adapt` accepted all of them. Only a real request distinguishes them.
DS_OUT="$("${PREFIX}/broker" panel.default-site.show </dev/null 2>&1)" || true
if printf '%s' "${DS_OUT}" | grep -q '"enabled":true'; then
    # With a real SNI, as a browser sends it (A46-E2): requesting https://127.0.0.1/ sends no SNI
    # at all, and that is the one case default_sni alone covered, so this check used to pass
    # while every unknown hostname failed its handshake.
    DS_CODE="$(curl -sk -m 8 -o /dev/null -w '%{http_code}' --resolve nonexistent.invalid:443:127.0.0.1 https://nonexistent.invalid/ 2>/dev/null || echo 000)"
    DS_BODY="$(curl -sk -m 8 --resolve nonexistent.invalid:443:127.0.0.1 https://nonexistent.invalid/ 2>/dev/null | grep -c 'not configured on this server' || true)"
    echo "DEFAULT_SITE_443_CODE=${DS_CODE}"
    # 000 means the handshake itself failed — the original defect. A 200 with no body means
    # the handshake succeeded but nothing routed, which was the second defect.
    if [[ "${DS_CODE}" == "200" && "${DS_BODY}" != "0" ]]; then
        echo "CHECK_DEFAULT_SITE_TLS=pass"
    else
        echo "CHECK_DEFAULT_SITE_TLS=fail"
    fi
    DS_HTTP="$(curl -s -m 8 -o /dev/null -w '%{http_code}' -H 'Host: nonexistent.invalid' http://127.0.0.1/ 2>/dev/null || echo 000)"
    if [[ "${DS_HTTP}" == "200" || "${DS_HTTP}" == "308" ]]; then
        echo "CHECK_DEFAULT_SITE_HTTP=pass"
    else
        echo "CHECK_DEFAULT_SITE_HTTP=fail"
    fi
    # Specificity: a real HTTPS vhost must keep serving itself, not the catch-all. Picks the
    # first vhost that actually has a certificate; if none does, the check is not applicable.
    TLS_VHOST="$("${PREFIX}/broker" vhost.list </dev/null 2>&1 \
        | python3 -c 'import sys,json
try:
    d=json.load(sys.stdin)["data"]["vhosts"]
except Exception:
    sys.exit(0)
for v in d:
    st=v.get("tls_status") or {}
    # Must actually serve HTTPS. A vhost configured without TLS has no HTTPS block, so it
    # *should* receive the default-site notice — selecting one would fail this check for
    # behaviour that is correct.
    if v.get("tls") and st.get("ok") and not v.get("readonly") \
            and not v.get("domain","").endswith(".invalid"):
        print(v["domain"]); break' 2>/dev/null || true)"
    if [[ -n "${TLS_VHOST}" ]]; then
        VH_DEFAULT="$(curl -sk -m 8 -H "Host: ${TLS_VHOST}" https://127.0.0.1/ 2>/dev/null | grep -c 'not configured on this server' || true)"
        if [[ "${VH_DEFAULT}" == "0" ]]; then
            echo "CHECK_DEFAULT_SITE_SPECIFICITY=pass"
        else
            echo "CHECK_DEFAULT_SITE_SPECIFICITY=fail"
        fi
        echo "SPECIFICITY_VHOST=${TLS_VHOST}"
    fi
else
    echo "DEFAULT_SITE=not-enabled (opt-in; azerioid panel default-site set --mode=page)"
fi

echo "=== spot-check: firewall (B2) ==="
FW_OUT="$("${PREFIX}/broker" firewall.rules </dev/null 2>&1)" || true
if printf '%s' "${FW_OUT}" | grep -q '"ok":true'; then
    echo "CHECK_FIREWALL_READS=pass"
else
    # An inactive firewall is a legitimate host state, so this reports rather than fails.
    echo "FIREWALL=no active backend or unreadable"
    echo "CHECK_FIREWALL_READS=fail"
fi
if printf '%s' "${FW_OUT}" | grep -q '"backend":"\(ufw\|firewalld\)"'; then
    echo "CHECK_FIREWALL_BACKEND=pass"
else
    echo "CHECK_FIREWALL_BACKEND=fail"
fi
# The panel's own rules must still be recognised as panel-owned after an upgrade. If this
# regresses, the UI offers an operator the chance to delete a rule a feature depends on.
if printf '%s' "${FW_OUT}" | grep -q '"comment":"azerioid'; then
    echo "CHECK_FIREWALL_OWNERSHIP=pass"
else
    echo "CHECK_FIREWALL_OWNERSHIP=fail"
fi
# Protected ports are the lockout guard; an empty list means the guard has nothing to defend.
if printf '%s' "${FW_OUT}" | grep -q '"protected_ports"'; then
    echo "CHECK_FIREWALL_PROTECTED=pass"
else
    echo "CHECK_FIREWALL_PROTECTED=fail"
fi

echo "=== spot-check: cron (B2) ==="
CRON_OUT="$("${PREFIX}/broker" cron.jobs </dev/null 2>&1)" || true
if printf '%s' "${CRON_OUT}" | grep -q '"ok":true'; then
    echo "CHECK_CRON_READS=pass"
else
    echo "CHECK_CRON_READS=fail"
fi
# Root crontab lines the panel did not write must survive every upgrade untouched.
if printf '%s' "${CRON_OUT}" | grep -q '"unmanaged_root_lines"'; then
    echo "CHECK_CRON_UNMANAGED_REPORTED=pass"
else
    echo "CHECK_CRON_UNMANAGED_REPORTED=fail"
fi
# The permission chain, which is what actually broke in v1.8.0: a site identity must be able
# to traverse the log base to reach its own directory. Unit tests cannot see this — the
# filesystem double treats mkdir and chown as no-ops.
CRON_LOG_DIR="/var/log/azerioid-cron"
if [[ -d "${CRON_LOG_DIR}" ]]; then
    CRON_MODE="$(stat -c '%a' "${CRON_LOG_DIR}" 2>/dev/null || stat -f '%Lp' "${CRON_LOG_DIR}" 2>/dev/null)"
    echo "CRON_LOG_DIR_MODE=${CRON_MODE}"
    if [[ "${CRON_MODE}" == "751" || "${CRON_MODE}" == "755" ]]; then
        echo "CHECK_CRON_LOG_TRAVERSABLE=pass"
    else
        echo "CHECK_CRON_LOG_TRAVERSABLE=fail"
    fi
    # Each identity's own directory must belong to it, or the wrapper cannot append.
    BAD_OWNER=0
    for d in "${CRON_LOG_DIR}"/az-vh-*; do
        [[ -d "${d}" ]] || continue
        # Logs of a removed identity are quarantined root:root 0700 (A49-E1), not owned by it.
        if ! id -u "$(basename "${d}")" >/dev/null 2>&1; then
            [[ "$(stat -c '%u %a' "${d}")" == "0 700" ]] || { BAD_OWNER=1; echo "CRON_LOG_DIR_NOT_QUARANTINED=${d}"; }
            continue
        fi
        if [[ "$(stat -c '%U' "${d}" 2>/dev/null)" != "$(basename "${d}")" ]]; then
            BAD_OWNER=1
            echo "CRON_LOG_DIR_WRONG_OWNER=${d}"
        fi
    done
    if [[ "${BAD_OWNER}" == "0" ]]; then
        echo "CHECK_CRON_LOG_OWNERSHIP=pass"
    else
        echo "CHECK_CRON_LOG_OWNERSHIP=fail"
    fi
else
    echo "CRON_LOG_DIR=absent (no scheduled jobs have run yet)"
fi

echo "=== spot-check: SFTP (A48) ==="
SFTP_OUT="$("${PREFIX}/broker" sftp.status </dev/null 2>&1)" || true
if printf '%s' "${SFTP_OUT}" | grep -q '"ok":true'; then
    echo "CHECK_SFTP_READS=pass"
else
    echo "CHECK_SFTP_READS=fail"
fi

# The panel must never edit sshd_config itself — the distro owns it, and an upgrade replacing it
# must not carry panel changes away. Asserted whether or not SFTP is configured, because this is
# the property that keeps the host upgradeable.
if grep -qiE 'azerioid|ForceCommand internal-sftp' /etc/ssh/sshd_config 2>/dev/null; then
    echo "CHECK_SFTP_NO_SSHD_CONFIG_EDIT=fail"
    echo "SFTP_SSHD_CONFIG_TOUCHED=yes"
else
    echo "CHECK_SFTP_NO_SSHD_CONFIG_EDIT=pass"
fi

# sshd's own configuration must remain valid at all times; a host that cannot reload sshd is one
# reboot away from being unreachable.
if /usr/sbin/sshd -t 2>/dev/null; then
    echo "CHECK_SFTP_SSHD_CONFIG_VALID=pass"
else
    echo "CHECK_SFTP_SSHD_CONFIG_VALID=fail"
fi

if printf '%s' "${SFTP_OUT}" | grep -q '"configured":true'; then
    # Ask sshd what it actually resolved for an enabled identity, rather than reading the file the
    # panel wrote. The file saying the right thing and sshd applying it are different claims, and
    # the difference is what let a TLS defect ship earlier in this project.
    SFTP_USER="$(printf '%s' "${SFTP_OUT}" | python3 -c 'import sys,json
try:
    sites=json.load(sys.stdin)["data"]["sites"]
except Exception:
    sys.exit(0)
print(sites[0] if sites else "")' 2>/dev/null || true)"
    if [[ -n "${SFTP_USER}" ]]; then
        RESOLVED="$(/usr/sbin/sshd -T -C "user=${SFTP_USER}" 2>/dev/null || true)"
        MISSING=""
        for directive in "forcecommand internal-sftp" "permittty no" "passwordauthentication no" \
                         "allowtcpforwarding no" "authorizedkeysfile /etc/ssh/azerioid-authorized-keys/%u"; do
            printf '%s' "${RESOLVED}" | grep -qi "^${directive}$" || MISSING="${MISSING}${directive}; "
        done
        if [[ -z "${MISSING}" ]]; then
            echo "CHECK_SFTP_RESTRICTIONS_APPLY=pass"
        else
            echo "CHECK_SFTP_RESTRICTIONS_APPLY=fail"
            echo "SFTP_MISSING_DIRECTIVES=${MISSING}"
        fi
        echo "SFTP_ENABLED_IDENTITY=${SFTP_USER}"

        # The administrator must never be caught by the Match block. This is the check that would
        # have failed loudly if the block had matched the group every site identity already belongs
        # to, which was the first design and would have restricted the panel's own accounts.
        ROOT_RESOLVED="$(/usr/sbin/sshd -T -C user=root 2>/dev/null || true)"
        if printf '%s' "${ROOT_RESOLVED}" | grep -qi '^forcecommand none$' \
            && printf '%s' "${ROOT_RESOLVED}" | grep -qi '^permittty yes$'; then
            echo "CHECK_SFTP_ADMIN_UNRESTRICTED=pass"
        else
            echo "CHECK_SFTP_ADMIN_UNRESTRICTED=fail"
        fi
    else
        echo "SFTP=configured but no site enabled; restriction checks not applicable"
    fi

    # Keys must not live where the account can rewrite them: a site that owns its key file can
    # install its own key and keep access after the hole that let it in is closed.
    KEY_DIR=/etc/ssh/azerioid-authorized-keys
    if [[ -d "${KEY_DIR}" ]]; then
        BAD_KEYS=0
        for f in "${KEY_DIR}"/*; do
            [[ -e "${f}" ]] || continue
            OWNER="$(stat -c '%U' "${f}" 2>/dev/null)"
            MODE="$(stat -c '%a' "${f}" 2>/dev/null)"
            if [[ "${OWNER}" != "root" ]] || [[ "${MODE}" =~ [2367]$ ]] || [[ "${MODE}" =~ ^.[2367] ]]; then
                BAD_KEYS=1
                echo "SFTP_KEY_FILE_UNSAFE=${f} owner=${OWNER} mode=${MODE}"
            fi
        done
        if [[ "${BAD_KEYS}" == "0" ]]; then
            echo "CHECK_SFTP_KEYS_ROOT_OWNED=pass"
        else
            echo "CHECK_SFTP_KEYS_ROOT_OWNED=fail"
        fi
    fi

    if [[ -f /etc/fail2ban/jail.d/azerioid-sftp.conf ]]; then
        echo "CHECK_SFTP_JAIL_PRESENT=pass"
    else
        # fail2ban may simply not be installed; reported rather than failed.
        echo "SFTP_JAIL=absent (fail2ban not installed, or jail removed)"
    fi
else
    echo "SFTP=not configured (opt-in; azerioid sftp enable <domain>)"
fi

# A66: repo signing keys must carry the pinned fingerprints (apt path). A
# mismatch means a key other than the one the installer pins is trusted.
if command -v gpg >/dev/null 2>&1; then
    REPO_KEYS_OK=1
    check_keyring() {
        local file="$1" fpr="$2"
        [[ -f "${file}" ]] || return 0  # repo not on this host; not applicable
        gpg --show-keys --with-colons "${file}" 2>/dev/null \
            | awk -F: '$1=="fpr"{print $10}' | grep -qiF "${fpr}" || REPO_KEYS_OK=0
    }
    check_keyring /usr/share/keyrings/caddy-stable-archive-keyring.gpg 65760C51EDEA2017CEA2CA15155B6D79CA56EA34
    check_keyring /usr/share/keyrings/php-sury-archive-keyring.gpg 15058500A0235D97F5D10063B188E2B695BD4743
    if [[ "${REPO_KEYS_OK}" == "1" ]]; then
        echo "CHECK_REPO_KEYS_PINNED=pass"
    else
        echo "CHECK_REPO_KEYS_PINNED=fail"
    fi
fi

echo "VERIFY_REMOTE_DONE"
EOS
)

REMOTE_SCRIPT="${REMOTE_SCRIPT//__PANEL_PORT__/${PANEL_PORT}}"
REMOTE_SCRIPT="${REMOTE_SCRIPT//__FROM_VERSION__/${FROM_VERSION}}"
REMOTE_SCRIPT="${REMOTE_SCRIPT//__APPLY__/${APPLY}}"
REMOTE_SCRIPT="${REMOTE_SCRIPT//__OCTANE_DOMAIN__/${OCTANE_DOMAIN}}"
REMOTE_SCRIPT="${REMOTE_SCRIPT//__PM2_DOMAIN__/${PM2_DOMAIN}}"

OUTPUT="$("${SSH[@]}" "${HOST}" bash <<<"${REMOTE_SCRIPT}")" || {
    echo "${OUTPUT}"
    fail "SSH remote verification failed"
    exit 1
}

printf '%s\n' "${OUTPUT}"

while IFS= read -r line; do
    case "${line}" in
        FROM_VERSION_MISMATCH=1*)
            fail "Deployed version mismatch: ${line#FROM_VERSION_MISMATCH=}"
            ;;
        CHECK_*=pass)
            pass "${line/check_/Check: }"
            ;;
        CHECK_*=fail)
            fail "${line/check_/Check: }"
            ;;
        CHECK_*=0)
            fail "${line}"
            ;;
        DEPLOYED_VERSION=*)
            pass "Deployed ${line/DEPLOYED_/}"
            ;;
        DEPLOYED_TAG=*)
            pass "Deployed ${line/DEPLOYED_/}"
            ;;
        DEPLOYED_COMMIT=*)
            pass "Deployed ${line/DEPLOYED_/}"
            ;;
        POST_UPDATE_VERSION=*)
            pass "After update ${line/POST_UPDATE_/}"
            ;;
        POST_UPDATE_TAG=*)
            pass "After update ${line/POST_UPDATE_/}"
            ;;
        UPDATE_APPLY_SKIPPED=*)
            pass "Panel update apply skipped (already up to date)"
            ;;
    esac
done <<<"${OUTPUT}"

echo ""
if [[ "${FAILURES}" -eq 0 ]]; then
    echo "==> Summary: ALL CHECKS PASSED"
    exit 0
fi
echo "==> Summary: ${FAILURES} CHECK(S) FAILED"
exit 1
