#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
for script in "$root"/deploy/*.sh; do bash -n "$script"; done
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
bin="$tmp/bin"; mkdir -p "$bin"
log="$tmp/calls"; : > "$log"
# shellcheck disable=SC2016
printf '#!/bin/bash\nprintf "docker %%s\\n" "$*" >> "%s"\nif [[ " $* " == *" exec -T db "* ]]; then printf "CREATE TABLE smoke(id INT);\\n"; fi\nif [[ " $* " == *" cp app:/var/www/html/storage/app "* ]]; then target="${@: -1}"; mkdir -p "$target"; printf evidence > "$target/file"; fi\n' "$log" > "$bin/docker"
chmod +x "$bin/docker"
deploy="$tmp/deploy"; mkdir -p "$deploy"
printf 'APP_KEY=test\n' > "$deploy/.env"
printf 'abc123\n' > "$deploy/.git-revision"
PATH="$bin:$PATH" FYNIX_SECRET_BACKUP_REF=secret:v1 "$root/deploy/backup.sh" "$deploy" "$tmp/backups" > "$tmp/archive-path"
archive="$(cat "$tmp/archive-path")"
tar -tzf "$archive" | grep -qx database.sql
tar -tzf "$archive" | grep -qx secret-backup-ref
(cd "$(dirname "$archive")" && sha256sum -c "$(basename "$archive").sha256")
PATH="$bin:$PATH" "$root/deploy/restore.sh" "$deploy" "$archive" RESTORE-CYBERAUDIT
stop_line="$(grep -n ' stop app' "$log" | cut -d: -f1)"
copy_line="$(grep -n ' cp .*storage-app' "$log" | tail -1 | cut -d: -f1)"
start_line="$(grep -n ' start app' "$log" | cut -d: -f1)"
[[ "$stop_line" -lt "$copy_line" && "$copy_line" -lt "$start_line" ]]
release="$tmp/release"; mkdir -p "$release/source/deploy"
printf '#!/bin/sh\nprintf "rollback %%s\\n" "$*" >> "%s"\n' "$log" > "$release/source/deploy/aws-update.sh"
chmod +x "$release/source/deploy/aws-update.sh"
"$root/deploy/rollback.sh" "$release" "$tmp/target" deadbeef
grep -q 'rollback .*deadbeef' "$log"
if "$root/deploy/restore.sh" "$deploy" "$archive" WRONG 2>/dev/null; then exit 1; fi
grep -q 'origin/main' "$root/scripts/deploy-aws-local.sh"
grep -q 'head-object' "$root/scripts/deploy-aws-local.sh"
grep -q 'materialize-change-evidence-secrets' "$root/deploy/install-change-evidence-materializer.sh"
grep -q 'findmnt.*tmpfs' "$root/deploy/materialize-change-evidence-secrets.sh"
grep -q 'signing_private_key' "$root/deploy/materialize-change-evidence-secrets.sh"
grep -q '/run/fynix-cyberaudit' "$root/docker-compose.yml"
grep -q 'Wants=network-online.target' "$root/deploy/fynix-cyberaudit-secrets.service"
grep -q 'install -m 0400' "$root/deploy/materialize-change-evidence-secrets.sh"
grep -q 'docker load' "$root/deploy/aws-update.sh"
grep -q -- '--no-build' "$root/deploy/aws-update.sh"
grep -q 'artisan migrate --force' "$root/deploy/aws-update.sh"
grep -q 'artisan fynix:suite-preflight' "$root/deploy/aws-update.sh"
if grep -q 'compose .*--build' "$root/deploy/aws-update.sh"; then exit 1; fi
grep -q 'image_sha256' "$root/scripts/build-release-bundle.sh"
grep -q 'cyberaudit_change_signing_public_keys' "$root/deploy/handoff-support-change-verifier.sh"
if grep -q 'signing_private_key:.' "$root/deploy/handoff-support-change-verifier.sh"; then exit 1; fi
