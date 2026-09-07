#!/usr/bin/env bash
# AZERIOID Stack Manager — certbot renew deploy hook (installed by broker tls.renew.hook-install).
# Reloads Nginx/Apache/Caddy after certbot renews a certificate.
set -euo pipefail
reload_unit() {
  local unit="$1"
  if systemctl is-active --quiet "$unit" 2>/dev/null; then
    systemctl reload "$unit" 2>/dev/null || systemctl kill -s HUP "$unit" 2>/dev/null || systemctl restart "$unit"
  fi
}
reload_unit nginx
reload_unit apache2
reload_unit httpd
reload_unit caddy
