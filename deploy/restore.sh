#!/usr/bin/env bash
set -euo pipefail
umask 077

deploy_dir="${1:?deployment directory required}"
archive="${2:?backup archive required}"
confirmation="${3:-}"
[[ "$confirmation" == RESTORE-CYBERAUDIT ]] || { echo 'Pass RESTORE-CYBERAUDIT as the third argument.' >&2; exit 2; }
test -s "$deploy_dir/.env"
test -s "$archive"
test -s "$archive.sha256"
(cd "$(dirname "$archive")" && sha256sum -c "$(basename "$archive").sha256")
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
command -v age >/dev/null 2>&1 || { echo 'age is required to restore encrypted backups.' >&2; exit 2; }
env_value() { sed -n "s/^$1=//p" "$deploy_dir/.env" | tail -1; }
age_identity_file="${FYNIX_BACKUP_AGE_IDENTITY_FILE:-$(env_value FYNIX_BACKUP_AGE_IDENTITY_FILE)}"
test -s "$age_identity_file" || { echo 'FYNIX_BACKUP_AGE_IDENTITY_FILE must reference a readable age identity.' >&2; exit 2; }
age --decrypt -i "$age_identity_file" "$archive" | tar -xzf - -C "$work"

cd "$deploy_dir"
docker compose -p fynixcyberaudit --env-file .env stop app
docker compose -p fynixcyberaudit --env-file .env exec -T db sh -c \
  'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < "$work/database.sql"
docker compose -p fynixcyberaudit --env-file .env cp "$work/storage-app/." app:/var/www/html/storage/app/
docker compose -p fynixcyberaudit --env-file .env start app
docker compose -p fynixcyberaudit --env-file .env exec -T app php artisan migrate:status
docker compose -p fynixcyberaudit --env-file .env exec -T app php artisan fynix:suite-preflight
