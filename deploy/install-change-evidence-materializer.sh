#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || exit 1
source_dir="$(cd "$(dirname "$0")" && pwd)"
install -m 0755 "$source_dir/materialize-change-evidence-secrets.sh" /usr/local/sbin/fynix-cyberaudit-materialize-secrets
cat >/etc/systemd/system/fynix-cyberaudit-secrets.service <<'UNIT'
[Unit]
Description=Materialize Fynix CyberAudit runtime secrets
After=network-online.target
Before=docker.service
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/fynix-cyberaudit-materialize-secrets
RemainAfterExit=yes
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable fynix-cyberaudit-secrets.service
systemctl restart fynix-cyberaudit-secrets.service
