#!/usr/bin/env bash
set -euo pipefail
source_dir="$(cd "$(dirname "$0")/.." && pwd)"
target="${1:?deployment directory required}"
revision="${2:?revision required}"
test -s "$target/.env"
mkdir -p "$target"
rsync -a --delete --exclude='.env' --exclude='.git-revision' --exclude='node_modules' "$source_dir/" "$target/"
printf '%s\n' "$revision" > "$target/.git-revision"
cd "$target"
docker compose -p fynixcyberaudit --env-file .env up -d --build
container="$(docker compose -p fynixcyberaudit ps -q app)"
for _ in $(seq 1 60); do
  [[ "$(docker inspect -f '{{.State.Health.Status}}' "$container" 2>/dev/null || true)" == healthy ]] && exit 0
  sleep 2
done
docker compose -p fynixcyberaudit logs app
exit 1
