#!/usr/bin/env bash
set -euo pipefail

release_dir="${1:?extracted previous bundle directory required}"
deploy_dir="${2:-/opt/fynix-suite/cyberaudit}"
revision="${3:?previous release SHA required}"
artifact_sha="${4:?previous release artifact SHA-256 required}"
test -x "$release_dir/source/deploy/aws-update.sh"
exec "$release_dir/source/deploy/aws-update.sh" "$release_dir" "$deploy_dir" "$revision" "$artifact_sha"
