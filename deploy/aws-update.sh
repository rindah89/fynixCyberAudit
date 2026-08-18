#!/usr/bin/env bash
set -euo pipefail
bundle="${1:?bundle directory required}"; target="${2:?deployment directory required}"; revision="${3:?revision required}"
[[ "$revision" =~ ^[0-9a-f]{40}$ && -s "$bundle/manifest.json" && -s "$bundle/image.tar" && -s "$bundle/source.tar" && -s "$target/.env" ]]
jq -e --arg r "$revision" '.version=="fynix-cyberaudit-release/v1" and .revision==$r' "$bundle/manifest.json" >/dev/null
test "$(sha256sum "$bundle/image.tar"|cut -d' ' -f1)" = "$(jq -r .image_sha256 "$bundle/manifest.json")"; test "$(sha256sum "$bundle/source.tar"|cut -d' ' -f1)" = "$(jq -r .source_sha256 "$bundle/manifest.json")"
image_tag="$(jq -r .image_tag "$bundle/manifest.json")"; image_id="$(jq -r .image_id "$bundle/manifest.json")"; docker load -i "$bundle/image.tar" >/dev/null; test "$(docker image inspect "$image_tag" --format '{{.Id}}')" = "$image_id"
release_root=/opt/fynix-suite/cyberaudit-releases; incoming="$release_root/$revision"; mkdir -p "$release_root"; rm -rf -- "$incoming"; mkdir "$incoming"; tar -xf "$bundle/source.tar" -C "$incoming" --strip-components=1 --no-same-owner
"$incoming/deploy/install-change-evidence-materializer.sh"; test -s /run/fynix-cyberaudit/change.env; set -a; source "$target/.env"; source /run/fynix-cyberaudit/change.env; set +a
previous_revision="$(cat "$target/.git-revision" 2>/dev/null || true)"; previous_image="$(cat "$target/.image-id" 2>/dev/null || true)"; mutated=0
rollback(){ rc=$?; if [[ $mutated == 1 && "$previous_revision" =~ ^[0-9a-f]{40}$ && -d "$release_root/$previous_revision" && "$previous_image" =~ ^sha256: ]]; then rsync -a --delete --exclude=.env --exclude=.git-revision --exclude=.image-id "$release_root/$previous_revision/" "$target/"; CYBERAUDIT_IMAGE="fynix-cyberaudit-release:$previous_revision" FYNIX_RELEASE_SHA="$previous_revision" docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" up -d --no-build; fi; exit $rc; }; trap rollback ERR
rsync -a --delete --exclude=.env --exclude=.git-revision --exclude=.image-id "$incoming/" "$target/"; mutated=1; printf '%s\n' "$revision" >"$target/.git-revision"; printf '%s\n' "$image_id" >"$target/.image-id"; export CYBERAUDIT_IMAGE="$image_tag" FYNIX_RELEASE_SHA="$revision"
docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" config -q
docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" run --rm --no-deps app php artisan migrate --force
docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" run --rm --no-deps app php artisan fynix:suite-preflight
docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" up -d --no-build
container="$(docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" ps -q app)"; for _ in $(seq 1 60); do [[ "$(docker inspect -f '{{.State.Health.Status}}' "$container" 2>/dev/null || true)" == healthy ]]&&break; sleep 2; done
test "$(docker inspect -f '{{.State.Health.Status}}' "$container")" = healthy; test "$(docker inspect -f '{{.Image}}' "$container")" = "$image_id"; docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" exec -T app php artisan migrate:status >/dev/null
trap - ERR; printf 'release=%s image=%s previous_release=%s previous_image=%s\n' "$revision" "$image_id" "$previous_revision" "$previous_image"
