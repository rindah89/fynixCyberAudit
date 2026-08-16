#!/usr/bin/env bash
set -euo pipefail
umask 077

deploy_dir=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --deploy-dir) deploy_dir="${2:-}"; shift 2 ;;
    *) echo "Unsupported release-verification argument." >&2; exit 2 ;;
  esac
done
[[ "$deploy_dir" == /* && -d "$deploy_dir" && ! -L "$deploy_dir" ]] || {
  echo "A safe absolute deployment directory is required." >&2
  exit 2
}
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
receipt="$(mktemp)"
cleanup() { rm -f -- "$receipt"; }
trap cleanup EXIT
operation_id="$(python3 -c 'import uuid; print(uuid.uuid4())')"
change_id="CHG-RELEASE-$(date -u +%Y%m%d%H%M%S)"
export FYNIX_DOCFLOW_DEPLOY_DIR="$deploy_dir"
export FYNIX_HR_DEPLOY_DIR="$deploy_dir"
export FYNIX_FINANCE_DEPLOY_DIR="$deploy_dir"
export FYNIX_CYBERAUDIT_DEPLOY_DIR="$deploy_dir"
export FYNIX_ITSM_DEPLOY_DIR="$deploy_dir"
"$script_dir/support-post-restore-verify.sh" \
  --operation-id "$operation_id" --change-id "$change_id" --json-output "$receipt"
python3 - "$receipt" <<'PY'
import json, sys
receipt = json.load(open(sys.argv[1], encoding="utf-8"))
if not all(receipt.get("checks", {}).values()):
    raise SystemExit("deep release verification receipt contains a failed check")
print(json.dumps({"verified": True, "application": receipt["application"]}, separators=(",", ":")))
PY
