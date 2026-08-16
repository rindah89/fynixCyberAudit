#!/usr/bin/env bash
set -euo pipefail
umask 077

deploy_dir="${FYNIX_CYBERAUDIT_DEPLOY_DIR:-/opt/fynix-suite/cyberaudit}"
api_url="${FYNIX_CYBERAUDIT_PROBE_URL:-https://cyberaudit.fynixhq.com}"
api_url="${api_url%/}"
token_file="${FYNIX_CYBERAUDIT_PROBE_TOKEN_FILE:-/etc/fynix/cyberaudit-support-probe-token}"
operation_id=""; change_id=""; json_output=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --operation-id) operation_id="${2:-}"; shift 2 ;;
    --change-id) change_id="${2:-}"; shift 2 ;;
    --json-output) json_output="${2:-}"; shift 2 ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done
[[ "$operation_id" =~ ^[0-9a-f-]{36}$ && -n "$change_id" ]] || { echo 'Operation and change identifiers are required.' >&2; exit 2; }
[[ -f "$json_output" && ! -L "$json_output" ]] || { echo 'The evidence target must be an existing regular file.' >&2; exit 2; }
[[ -s "$deploy_dir/.env" && -s "$token_file" ]] || { echo 'CyberAudit deployment or support-probe credential is missing.' >&2; exit 2; }

cd "$deploy_dir"
compose=(docker compose -p fynixcyberaudit --env-file .env)
work_dir="$(mktemp -d)"
trap 'rm -rf -- "$work_dir"' EXIT
probe_token="$(< "$token_file")"
[[ "$probe_token" =~ ^[A-Za-z0-9._|~-]+$ ]] || { echo 'The support-probe token has an invalid format.' >&2; exit 2; }
printf 'header = "Authorization: Bearer %s"\n' "$probe_token" > "$work_dir/curl-auth.conf"
unset probe_token

for service in app db; do
  container="$("${compose[@]}" ps -q "$service")"
  [[ -n "$container" && "$(docker inspect -f '{{.State.Running}}' "$container")" == true ]] || { echo "Required CyberAudit service $service is not running." >&2; exit 1; }
  health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$container")"
  [[ "$health" == none || "$health" == healthy ]] || { echo "Required CyberAudit service $service is unhealthy." >&2; exit 1; }
done

# --pending=1 exits non-zero when even one release migration is absent.
"${compose[@]}" exec -T app php artisan migrate:status --pending=1 --no-ansi >/dev/null
"${compose[@]}" exec -T app php artisan fynix:suite-preflight >/dev/null
"${compose[@]}" exec -T app php artisan fynix:vendor-ledger-verify >/dev/null
curl -fsS "$api_url/health" >/dev/null
curl -fsS "$api_url/api/suite/ready" -o "$work_dir/ready.json"
python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); assert d["status"] == "ok" and d["vendor_operations"]["integrity"] == "ok"' "$work_dir/ready.json"

curl -fsS --config "$work_dir/curl-auth.conf" -X POST -H 'Content-Type: application/json' \
  --data "{\"operation_id\":\"$operation_id\"}" "$api_url/api/support/restore-probe" -o "$work_dir/probe.json"
python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); assert d["authenticated"] is True and d["evidence_storage"] == "read-write" and d["probe_cleanup"] is True and isinstance(d["audit_records"],int)' "$work_dir/probe.json"

RECEIPT_OPERATION_ID="$operation_id" RECEIPT_CHANGE_ID="$change_id" RECEIPT_OUTPUT="$json_output" python3 - <<'PY'
import json, os, stat
from datetime import UTC, datetime
r={"application":"cyberaudit","operation_id":os.environ["RECEIPT_OPERATION_ID"],"change_id":os.environ["RECEIPT_CHANGE_ID"],"verified_at":datetime.now(UTC).isoformat(timespec="seconds").replace("+00:00","Z"),"checks":{"schema":True,"restored_data":True,"workers":True,"public_route":True,"authenticated_rw":True,"probe_cleanup":True}}
fd=os.open(os.environ["RECEIPT_OUTPUT"],os.O_WRONLY|os.O_TRUNC|os.O_NOFOLLOW)
try:
    if not stat.S_ISREG(os.fstat(fd).st_mode): raise RuntimeError('receipt output is not a regular file')
    with os.fdopen(fd,'wb',closefd=False) as out: out.write(json.dumps(r,separators=(',',':'),sort_keys=True).encode()); out.flush()
finally: os.close(fd)
PY
printf 'CyberAudit deep post-restore verification passed for %s.\n' "$operation_id"
