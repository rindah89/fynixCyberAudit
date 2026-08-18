#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"; cd "$root"
out="${1:?output directory required}"; revision="$(git rev-parse HEAD)"; tag="fynix-cyberaudit-release:$revision"
[[ "$revision" =~ ^[0-9a-f]{40}$ ]]; mkdir -p "$out"; work="$(mktemp -d)"; trap 'rm -rf -- "$work"' EXIT
docker build --pull --label "org.opencontainers.image.revision=$revision" -t "$tag" .
image_id="$(docker image inspect "$tag" --format '{{.Id}}')"; [[ "$image_id" =~ ^sha256:[0-9a-f]{64}$ ]]
docker save "$tag" -o "$work/image.tar"; git archive --format=tar --prefix=source/ HEAD >"$work/source.tar"
image_sha="$(sha256sum "$work/image.tar"|cut -d' ' -f1)"; source_sha="$(sha256sum "$work/source.tar"|cut -d' ' -f1)"
jq -n --arg revision "$revision" --arg tag "$tag" --arg image_id "$image_id" --arg image_sha256 "$image_sha" --arg source_sha256 "$source_sha" '{version:"fynix-cyberaudit-release/v1",revision:$revision,image_tag:$tag,image_id:$image_id,image_sha256:$image_sha256,source_sha256:$source_sha256}' >"$work/manifest.json"
manifest_sha="$(sha256sum "$work/manifest.json"|cut -d' ' -f1)"; tar -C "$work" -czf "$out/fynix-cyberaudit-$revision-$manifest_sha.tar.gz" image.tar source.tar manifest.json
printf '%s\n' "$out/fynix-cyberaudit-$revision-$manifest_sha.tar.gz"
