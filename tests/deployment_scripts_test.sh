#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
for script in "$root"/deploy/*.sh; do bash -n "$script"; done
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
if "$root/deploy/restore.sh" "$tmp" "$tmp/missing" WRONG 2>/dev/null; then
  echo 'restore accepted an invalid confirmation' >&2
  exit 1
fi
if "$root/deploy/rollback.sh" "$tmp" "$tmp/target" deadbeef 2>/dev/null; then
  echo 'rollback accepted a release without aws-update.sh' >&2
  exit 1
fi
