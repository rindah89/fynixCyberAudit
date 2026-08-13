#!/usr/bin/env bash
set -euo pipefail

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
tar -xzf "$archive" -C "$work"

cd "$deploy_dir"
docker compose -p fynixcyberaudit --env-file .env stop app
docker compose -p fynixcyberaudit --env-file .env exec -T db sh -c \
  'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < "$work/database.sql"
docker compose -p fynixcyberaudit --env-file .env start app
docker compose -p fynixcyberaudit --env-file .env cp "$work/storage-app/." app:/var/www/html/storage/app/
docker compose -p fynixcyberaudit --env-file .env exec -T app php artisan migrate:status
docker compose -p fynixcyberaudit --env-file .env exec -T app php artisan fynix:suite-preflight
