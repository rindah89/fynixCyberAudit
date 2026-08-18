#!/usr/bin/env bash
set -euo pipefail
bundle="${1:?bundle directory required}"; target="${2:?deployment directory required}"; revision="${3:?revision required}"; artifact_sha="${4:?artifact SHA-256 required}"
[[ "$artifact_sha" =~ ^[0-9a-f]{64}$ ]]
[[ "$revision" =~ ^[0-9a-f]{40}$ && -s "$bundle/manifest.json" && -s "$bundle/image.tar" && -s "$bundle/source.tar" && -s "$target/.env" ]]
jq -e --arg r "$revision" '.version=="fynix-cyberaudit-release/v1" and .revision==$r' "$bundle/manifest.json" >/dev/null
test "$(sha256sum "$bundle/image.tar"|cut -d' ' -f1)" = "$(jq -r .image_sha256 "$bundle/manifest.json")"; test "$(sha256sum "$bundle/source.tar"|cut -d' ' -f1)" = "$(jq -r .source_sha256 "$bundle/manifest.json")"
image_tag="$(jq -r .image_tag "$bundle/manifest.json")"; image_id="$(jq -r .image_id "$bundle/manifest.json")"; manifest_digest="$(sha256sum "$bundle/manifest.json"|cut -d' ' -f1)"; docker load -i "$bundle/image.tar" >/dev/null; test "$(docker image inspect "$image_tag" --format '{{.Id}}')" = "$image_id"
release_root=/opt/fynix-suite/cyberaudit-releases; incoming="$release_root/$revision"; mkdir -p "$release_root"; if [[ -d "$incoming" ]]; then test "$(cat "$incoming/.manifest-sha256")" = "$manifest_digest"; test "$(cat "$incoming/.artifact-sha256")" = "$artifact_sha"; else mkdir "$incoming"; tar -xf "$bundle/source.tar" -C "$incoming" --strip-components=1 --no-same-owner; printf '%s\n' "$manifest_digest" >"$incoming/.manifest-sha256"; printf '%s\n' "$artifact_sha" >"$incoming/.artifact-sha256"; fi
"$incoming/deploy/install-change-evidence-materializer.sh"; test -s /run/fynix-cyberaudit/change.env; set -a; source "$target/.env"; source /run/fynix-cyberaudit/change.env; set +a
tuple="$target/.release-tuple.json"; previous_revision="$(jq -r .release_sha "$tuple" 2>/dev/null || true)"; previous_image="$(jq -r .image_id "$tuple" 2>/dev/null || true)"; previous_artifact="$(jq -r .artifact_sha256 "$tuple" 2>/dev/null || true)"; mutated=0
[[ "$previous_revision" =~ ^[0-9a-f]{40}$ && "$previous_image" =~ ^sha256:[0-9a-f]{64}$ && "$previous_artifact" =~ ^[0-9a-f]{64}$ ]] || { echo "verified previous release tuple is required before deployment" >&2; exit 1; }
if [[ "$previous_revision" =~ ^[0-9a-f]{40}$ && "$previous_image" =~ ^sha256:[0-9a-f]{64}$ ]]; then
  docker image inspect "$previous_image" >/dev/null; docker tag "$previous_image" "fynix-cyberaudit-release:$previous_revision"
  if [[ ! -d "$release_root/$previous_revision" ]]; then mkdir "$release_root/$previous_revision"; rsync -a --exclude=.env --exclude=.release-tuple.json "$target/" "$release_root/$previous_revision/"; fi
fi
write_tuple(){ python3 - "$tuple" "$1" "$2" "$3" <<'PY'
import json, os, sys
path, release, image, artifact = sys.argv[1:]
tmp = path + ".new"
fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
with os.fdopen(fd, "w") as out:
    json.dump({"version":"fynix-cyberaudit-release-tuple/v1","release_sha":release,"image_id":image,"artifact_sha256":artifact}, out, separators=(",", ":"))
    out.write("\n"); out.flush(); os.fsync(out.fileno())
os.replace(tmp, path)
directory = os.open(os.path.dirname(path), os.O_RDONLY | os.O_DIRECTORY)
try: os.fsync(directory)
finally: os.close(directory)
PY
}
rollback(){ rc=$?; trap - ERR; if [[ $mutated == 1 && "$previous_revision" =~ ^[0-9a-f]{40}$ && -d "$release_root/$previous_revision" && "$previous_image" =~ ^sha256:[0-9a-f]{64}$ ]]; then rsync -a --delete --exclude=.env --exclude=.release-tuple.json "$release_root/$previous_revision/" "$target/"; write_tuple "$previous_revision" "$previous_image" "$previous_artifact"; jq -e --arg r "$previous_revision" --arg i "$previous_image" --arg a "$previous_artifact" '.release_sha==$r and .image_id==$i and .artifact_sha256==$a' "$tuple" >/dev/null; CYBERAUDIT_IMAGE="fynix-cyberaudit-release:$previous_revision" FYNIX_RELEASE_SHA="$previous_revision" docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" up -d --no-build; prior_container="$(docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" ps -q app)"; prior_worker="$(docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" ps -q worker)"; for _ in $(seq 1 60); do [[ "$(docker inspect -f '{{.State.Health.Status}}' "$prior_container" 2>/dev/null || true)" == healthy ]]&&break; sleep 2; done; test "$(docker inspect -f '{{.Image}}' "$prior_container")" = "$previous_image"; test "$(docker inspect -f '{{.Image}}' "$prior_worker")" = "$previous_image"; test "$(docker inspect -f '{{.State.Health.Status}}' "$prior_container")" = healthy; fi; exit $rc; }; trap rollback ERR
rsync -a --delete --exclude=.env --exclude=.release-tuple.json "$incoming/" "$target/"; mutated=1; write_tuple "$revision" "$image_id" "$artifact_sha"; export CYBERAUDIT_IMAGE="$image_tag" FYNIX_RELEASE_SHA="$revision"
docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" config -q
docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" run --rm --no-deps app php artisan migrate --force
docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" run --rm --no-deps app php artisan fynix:suite-preflight
docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" up -d --no-build
container="$(docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" ps -q app)"; for _ in $(seq 1 60); do [[ "$(docker inspect -f '{{.State.Health.Status}}' "$container" 2>/dev/null || true)" == healthy ]]&&break; sleep 2; done
worker="$(docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" ps -q worker)"; test "$(docker inspect -f '{{.State.Health.Status}}' "$container")" = healthy; test "$(docker inspect -f '{{.Image}}' "$container")" = "$image_id"; test "$(docker inspect -f '{{.Image}}' "$worker")" = "$image_id"; docker compose -p fynixcyberaudit --env-file "$target/.env" -f "$target/docker-compose.yml" exec -T app php artisan migrate:status >/dev/null
trap - ERR; printf 'release=%s image=%s artifact_sha256=%s previous_release=%s previous_image=%s previous_artifact_sha256=%s\n' "$revision" "$image_id" "$artifact_sha" "$previous_revision" "$previous_image" "$previous_artifact"
