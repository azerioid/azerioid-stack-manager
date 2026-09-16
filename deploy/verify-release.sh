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

echo "=== spot-check: database broker ==="
DB_OUT="$("${PREFIX}/broker" db.engine </dev/null 2>&1)" || true
if printf '%s' "${DB_OUT}" | grep -q '"ok":true'; then
    echo "CHECK_DB_ENGINE=pass"
else
    echo "CHECK_DB_ENGINE=fail"
fi
printf '%s\n' "${DB_OUT}" | head -c 400
echo

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
