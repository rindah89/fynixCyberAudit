#!/usr/bin/env bash
set -euo pipefail

deploy_dir="${1:-/opt/fynix-suite/cyberaudit}"
[[ "$(id -u)" -eq 0 ]] || { echo 'Run as root.' >&2; exit 2; }
test -x "$deploy_dir/deploy/backup.sh"
test -x "$deploy_dir/deploy/quarterly-recovery.sh"
test -x "$deploy_dir/deploy/rehearse-restore.sh"
test -s "$deploy_dir/.env"

for unit in \
  fynix-cyberaudit-backup.service \
  fynix-cyberaudit-backup.timer \
  fynix-cyberaudit-recovery-drill.service \
  fynix-cyberaudit-recovery-drill.timer; do
  install -m 0644 "$deploy_dir/deploy/$unit" "/etc/systemd/system/$unit"
done
systemctl daemon-reload
systemctl enable --now fynix-cyberaudit-backup.timer fynix-cyberaudit-recovery-drill.timer
systemctl list-timers --all fynix-cyberaudit-backup.timer fynix-cyberaudit-recovery-drill.timer
