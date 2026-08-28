#!/usr/bin/env bash
set -euo pipefail
umask 077

deploy_dir="${1:?deployment directory required}"
archive="${2:?backup archive required}"
evidence_dir="${3:-/var/lib/fynix-governance/recovery/cyberaudit}"
test -s "$deploy_dir/.env"
test -s "$archive"
test -s "$archive.sha256"
(cd "$(dirname "$archive")" && sha256sum -c "$(basename "$archive").sha256")

work="$(mktemp -d)"
scratch="fynix_recovery_$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"
report_name="cyberaudit-restore-$(date -u +%Y%m%dT%H%M%SZ).json"
started="$(date +%s)"

cleanup() {
  cd "$deploy_dir"
  docker compose -p fynixcyberaudit --env-file .env exec -T db sh -c \
    'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS `'$scratch'`"' \
    >/dev/null 2>&1 || true
  rm -rf "$work"
}
trap cleanup EXIT

command -v age >/dev/null 2>&1 || { echo 'age is required to rehearse encrypted backups.' >&2; exit 2; }
env_value() { sed -n "s/^$1=//p" "$deploy_dir/.env" | tail -1; }
age_identity_file="${FYNIX_BACKUP_AGE_IDENTITY_FILE:-$(env_value FYNIX_BACKUP_AGE_IDENTITY_FILE)}"
test -s "$age_identity_file" || { echo 'FYNIX_BACKUP_AGE_IDENTITY_FILE must reference a readable age identity.' >&2; exit 2; }
age --decrypt -i "$age_identity_file" "$archive" | tar -xzf - -C "$work"
test -s "$work/database.sql"
test -s "$work/git-revision"
test -s "$work/secret-backup-ref"
test -d "$work/storage-app"

cd "$deploy_dir"
docker compose -p fynixcyberaudit --env-file .env exec -T db sh -c \
  'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE `'$scratch'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"'
docker compose -p fynixcyberaudit --env-file .env exec -T db sh -c \
  'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "'$scratch'"' < "$work/database.sql"

table_count="$(docker compose -p fynixcyberaudit --env-file .env exec -T db sh -c \
  'exec mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='"'"'$scratch'"'"' AND table_type='"'"'BASE TABLE'"'"'"' | tr -d '\r')"
[[ "$table_count" =~ ^[1-9][0-9]*$ ]]

docker compose -p fynixcyberaudit --env-file .env run --rm --no-deps \
  -e DB_DATABASE="$scratch" app php artisan migrate:status >/dev/null
docker compose -p fynixcyberaudit --env-file .env run --rm --no-deps \
  -e DB_DATABASE="$scratch" -v "$work/storage-app:/var/www/html/storage/app:ro" \
  app php artisan fynix:suite-preflight >/dev/null

mkdir -p "$evidence_dir"
chmod 0700 "$evidence_dir"
backup_sha256="$(sha256sum "$archive" | awk '{print $1}')"
occurred_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
restore_seconds="$(( $(date +%s) - started ))"
report="$evidence_dir/$report_name"
printf '{"schema":"fynix.cyberaudit.restore-drill.v1","source":"cyberaudit","occurred_at":"%s","backup_sha256":"%s","database_restored":true,"migrations_verified":true,"storage_verified":true,"application_preflight_passed":true,"restore_seconds":%d,"restored_table_count":%d}\n' \
  "$occurred_at" "$backup_sha256" "$restore_seconds" "$table_count" > "$report"
chmod 0600 "$report"

container_report="/tmp/$report_name"
docker compose -p fynixcyberaudit --env-file .env cp "$report" "app:$container_report"
docker compose -p fynixcyberaudit --env-file .env exec -T app \
  php artisan fynix:record-recovery-drill "$container_report"
docker compose -p fynixcyberaudit --env-file .env exec -T app rm -f "$container_report"
printf '%s\n' "$report"
