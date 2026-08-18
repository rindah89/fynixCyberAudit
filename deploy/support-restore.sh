#!/usr/bin/env bash
set -euo pipefail

deploy_dir="${1:-/opt/fynix-suite/cyberaudit}"
artifact="${2:?backup artifact required}"
confirmation="${3:-}"
backup_root="${FYNIX_CYBERAUDIT_BACKUP_ROOT:-/var/backups/fynix/fynixcyberaudit}"
identity_file="${FYNIX_BACKUP_AGE_IDENTITY_FILE:-/etc/fynix/backup-age-identity}"
[[ "$confirmation" == RESTORE-CYBERAUDIT ]] || { echo 'Pass RESTORE-CYBERAUDIT as the third argument.' >&2; exit 2; }
canonical="$(readlink -f "$artifact")"
[[ "$canonical" == "$backup_root/"*.tar.age && -f "$canonical" && ! -L "$artifact" ]] || { echo 'Artifact is outside governed CyberAudit storage.' >&2; exit 2; }
[[ -s "$canonical.sha256" && -s "$identity_file" ]] || { echo 'Checksum or age identity is missing.' >&2; exit 2; }
(cd "$backup_root" && sha256sum -c "$(basename "$canonical").sha256")
"$deploy_dir/deploy/support-backup.sh" "$deploy_dir" "$backup_root/pre-restore" >/dev/null
stage="$(mktemp -d "$backup_root/.restore.XXXXXXXX")"
restart_app() { cd "$deploy_dir" && docker compose -p fynixcyberaudit --env-file .env up -d --no-deps app >/dev/null 2>&1 || true; }
cleanup() { rm -rf -- "$stage"; restart_app; }
trap cleanup EXIT
age -d -i "$identity_file" -o "$stage/recovery.tar" "$canonical"
if tar -tf "$stage/recovery.tar" | grep -Eq '(^|/)\.\.(/|$)|^/'; then
  echo 'CyberAudit recovery set contains an unsafe path.' >&2
  exit 1
fi
tar -C "$stage" -xf "$stage/recovery.tar"
archive_count="$(find "$stage" -mindepth 1 -maxdepth 1 -type f -name '*.tar.gz' | wc -l | tr -d ' ')"
archive="$(find "$stage" -mindepth 1 -maxdepth 1 -type f -name '*.tar.gz' -print -quit)"
[[ "$archive_count" -eq 1 && -s "$archive.sha256" ]] || { echo 'Decrypted CyberAudit recovery set is incomplete.' >&2; exit 1; }
"$deploy_dir/deploy/restore.sh" "$deploy_dir" "$archive" RESTORE-CYBERAUDIT
trap - EXIT
rm -rf -- "$stage"
