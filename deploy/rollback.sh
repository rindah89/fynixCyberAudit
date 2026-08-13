#!/usr/bin/env bash
set -euo pipefail

release_dir="${1:?extracted previous release directory required}"
deploy_dir="${2:-/opt/fynix-suite/cyberaudit}"
revision="${3:?previous release SHA required}"
test -x "$release_dir/deploy/aws-update.sh"
exec "$release_dir/deploy/aws-update.sh" "$deploy_dir" "$revision"
