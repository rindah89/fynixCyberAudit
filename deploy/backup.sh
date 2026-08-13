#!/usr/bin/env bash
set -euo pipefail

deploy_dir="${1:-/opt/fynix-suite/cyberaudit}"
output_dir="${2:-/var/backups/fynix/cyberaudit}"
test -s "$deploy_dir/.env"
mkdir -p "$output_dir"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
revision="$(tr -d '\n' < "$deploy_dir/.git-revision" 2>/dev/null || printf unknown)"
archive="$output_dir/cyberaudit-$stamp-$revision.tar.gz"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

cd "$deploy_dir"
docker compose -p fynixcyberaudit --env-file .env exec -T db sh -c \
  'exec mysqldump --single-transaction --routines --triggers -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > "$work/database.sql"
docker compose -p fynixcyberaudit --env-file .env cp app:/var/www/html/storage/app "$work/storage-app"
cp .git-revision "$work/git-revision" 2>/dev/null || printf '%s\n' "$revision" > "$work/git-revision"
secret_ref="${FYNIX_SECRET_BACKUP_REF:-}"
test -n "$secret_ref" || { echo 'FYNIX_SECRET_BACKUP_REF is required (versioned external .env/APP_KEY backup reference).' >&2; exit 2; }
printf '%s\n' "$secret_ref" > "$work/secret-backup-ref"
tar -C "$work" -czf "$archive" database.sql storage-app git-revision secret-backup-ref
(cd "$output_dir" && sha256sum "$(basename "$archive")" > "$(basename "$archive").sha256")
if [[ -n "${FYNIX_BACKUP_S3_URI:-}" ]]; then
  aws s3 cp "$archive" "${FYNIX_BACKUP_S3_URI%/}/$(basename "$archive")" --sse AES256 --only-show-errors
  aws s3 cp "$archive.sha256" "${FYNIX_BACKUP_S3_URI%/}/$(basename "$archive").sha256" --sse AES256 --only-show-errors
fi
printf '%s\n' "$archive"
