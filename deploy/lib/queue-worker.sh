#!/usr/bin/env bash
# systemd queue worker for Laravel jobs.
set -euo pipefail

install_queue_worker() {
    echo "==> Installing azerioid-panel-queue.service"
    install -m 0644 "${ROOT}/deploy/systemd/azerioid-panel-queue.service" \
        /etc/systemd/system/azerioid-panel-queue.service

    sed -i \
        -e "s|@PREFIX@|${PREFIX}|g" \
        -e "s|@PANEL_USER@|${PANEL_USER}|g" \
        -e "s|@PHP_BIN@|${PHP_BIN}|g" \
        /etc/systemd/system/azerioid-panel-queue.service

    systemctl daemon-reload
    systemctl enable --now azerioid-panel-queue.service
    systemctl restart azerioid-panel-queue.service
}

dispatch_ping_job() {
    echo "==> Dispatching PingJob to verify queue worker"
    run_as_web "${PHP_BIN} artisan queue:dispatch-ping" >/dev/null 2>&1 || true
}
