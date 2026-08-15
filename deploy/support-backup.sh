#!/usr/bin/env bash
set -euo pipefail

deploy_dir="${1:-/opt/fynix-suite/cyberaudit}"
backup_root="${2:-/var/backups/fynix/fynixcyberaudit}"
recipient_file="${FYNIX_BACKUP_AGE_RECIPIENT_FILE:-/etc/fynix/backup-age-recipient}"
[[ -s "$recipient_file" ]] || { echo 'Backup age recipient is missing.' >&2; exit 1; }
command -v age >/dev/null || { echo 'age is required for encrypted backups.' >&2; exit 1; }
command -v flock >/dev/null || { echo 'flock is required for backup serialization.' >&2; exit 1; }
install -d -m 0750 "$backup_root"
exec 9>"$backup_root/.backup.lock"
flock -n 9 || { echo 'Another CyberAudit backup is already running.' >&2; exit 1; }
stage="$(mktemp -d "$backup_root/.staging.XXXXXXXX")"
trap 'rm -rf -- "$stage"' EXIT
plain="$($deploy_dir/deploy/backup.sh "$deploy_dir" "$stage")"
[[ "$plain" == "$stage/"*.tar.gz && -s "$plain" && -s "$plain.sha256" ]] || { echo 'CyberAudit backup contract produced an incomplete snapshot.' >&2; exit 1; }
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
artifact="$backup_root/fynix-cyberaudit-$timestamp.tar.age"
tar -C "$stage" -cf - "$(basename "$plain")" "$(basename "$plain").sha256" | age -R "$recipient_file" -o "$artifact"
chmod 0640 "$artifact"
sha256sum "$artifact" > "$artifact.sha256"
chmod 0640 "$artifact.sha256"
printf '%s\n' "$artifact"
