#!/usr/bin/env bash
set -euo pipefail
umask 077

deploy_dir="${1:-/opt/fynix-suite/cyberaudit}"
output_dir="${2:-/var/backups/fynix/cyberaudit}"
test -s "$deploy_dir/.env"
mkdir -p "$output_dir"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
revision="$(tr -d '\n' < "$deploy_dir/.git-revision" 2>/dev/null || printf unknown)"
archive="$output_dir/cyberaudit-$stamp-$revision.tar.gz.age"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
command -v age >/dev/null 2>&1 || { echo 'age is required for encrypted backups.' >&2; exit 2; }
env_value() { sed -n "s/^$1=//p" "$deploy_dir/.env" | tail -1; }
age_recipient="${FYNIX_BACKUP_AGE_RECIPIENT:-$(env_value FYNIX_BACKUP_AGE_RECIPIENT)}"
test -n "$age_recipient" || { echo 'FYNIX_BACKUP_AGE_RECIPIENT is required.' >&2; exit 2; }

cd "$deploy_dir"
docker compose -p fynixcyberaudit --env-file .env exec -T db sh -c \
  'exec mysqldump --single-transaction --routines --triggers -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > "$work/database.sql"
docker compose -p fynixcyberaudit --env-file .env cp app:/var/www/html/storage/app "$work/storage-app"
cp .git-revision "$work/git-revision" 2>/dev/null || printf '%s\n' "$revision" > "$work/git-revision"
secret_ref="${FYNIX_SECRET_BACKUP_REF:-$(env_value FYNIX_SECRET_BACKUP_REF)}"
test -n "$secret_ref" || { echo 'FYNIX_SECRET_BACKUP_REF is required (versioned external .env/APP_KEY backup reference).' >&2; exit 2; }
printf '%s\n' "$secret_ref" > "$work/secret-backup-ref"
tar -C "$work" -czf - database.sql storage-app git-revision secret-backup-ref \
  | age -r "$age_recipient" -o "$archive"
(cd "$output_dir" && sha256sum "$(basename "$archive")" > "$(basename "$archive").sha256")
backup_s3_uri="${FYNIX_BACKUP_S3_URI:-$(env_value FYNIX_BACKUP_S3_URI)}"
test -n "$backup_s3_uri" || { echo 'FYNIX_BACKUP_S3_URI is required for an off-host recovery copy.' >&2; exit 2; }
command -v aws >/dev/null 2>&1 || { echo 'aws is required for the off-host recovery copy.' >&2; exit 2; }
aws s3 cp "$archive" "${backup_s3_uri%/}/$(basename "$archive")" --sse AES256 --only-show-errors
aws s3 cp "$archive.sha256" "${backup_s3_uri%/}/$(basename "$archive").sha256" --sse AES256 --only-show-errors
printf '%s\n' "$archive"
