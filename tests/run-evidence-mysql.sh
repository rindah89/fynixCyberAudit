#!/usr/bin/env bash
set -euo pipefail
name="fynix-evidence-mysql-$RANDOM-$$"; port=33307
trap 'docker rm -f "$name" >/dev/null 2>&1 || true' EXIT
database="fynix_evidence_test_${RANDOM}_$$"
docker run -d --rm --name "$name" -e MYSQL_ROOT_PASSWORD=test-root-only -e MYSQL_DATABASE="$database" -p "127.0.0.1:$port:3306" mysql:8.4 >/dev/null
for _ in $(seq 1 60); do docker exec "$name" mysql -uroot -ptest-root-only -e 'SELECT 1' "$database" >/dev/null 2>&1 && break; sleep 1; done
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$port" DB_DATABASE="$database" DB_USERNAME=root DB_PASSWORD=test-root-only
php artisan migrate:fresh --force --path=database/migrations/2014_10_12_000000_create_users_table.php
php artisan migrate --force --path=database/migrations/2024_10_27_170857_create_settings_table.php
php artisan migrate --force --path=database/migrations/2026_08_18_180000_create_support_change_evidence_acceptances.php
php artisan migrate --force --path=database/migrations/2026_08_18_230000_create_evidence_policy_registry_tables.php
vendor/bin/phpunit -c tests/phpunit.mysql.xml tests/Feature/EvidenceAuthorizationMysqlHttpTest.php
php tests/mysql_evidence_concurrency_test.php
