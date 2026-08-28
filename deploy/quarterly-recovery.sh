#!/usr/bin/env bash
set -euo pipefail
umask 077

deploy_dir="${1:-/opt/fynix-suite/cyberaudit}"
backup_dir="${2:-/var/backups/fynix/cyberaudit}"
evidence_dir="${3:-/var/lib/fynix-governance/recovery/cyberaudit}"
lock_file="${4:-/run/lock/fynix-cyberaudit-recovery.lock}"

mkdir -p "$(dirname "$lock_file")"
exec 9>"$lock_file"
flock -n 9 || { echo 'A CyberAudit recovery operation is already running.' >&2; exit 75; }

archive="$("$deploy_dir/deploy/backup.sh" "$deploy_dir" "$backup_dir")"
test -s "$archive"
"$deploy_dir/deploy/rehearse-restore.sh" "$deploy_dir" "$archive" "$evidence_dir"
