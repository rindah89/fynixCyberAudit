#!/usr/bin/env bash
set -euo pipefail
region="${AWS_REGION:-us-east-2}"
secret_id="${CYBERAUDIT_CHANGE_SECRET_ID:-fynix/cyberaudit/change-evidence}"
destination="${CYBERAUDIT_CHANGE_SECRET_DIR:-/run/fynix-cyberaudit}"
[[ "$(id -u)" == 0 && "$destination" == /run/* ]] || exit 1
findmnt -n -o FSTYPE /run | grep -qx tmpfs
umask 077
stage="$(mktemp -d /run/fynix-cyberaudit.XXXXXX)"
trap 'rm -rf -- "$stage"' EXIT
aws secretsmanager get-secret-value --region "$region" --secret-id "$secret_id" --query SecretString --output text >"$stage/payload.json"
chmod 0600 "$stage/payload.json"
python3 - "$stage/payload.json" "$stage" <<'PY'
import base64,json,os,pathlib,sys
p=json.loads(pathlib.Path(sys.argv[1]).read_text()); expected={'requester_key','signing_private_key','signing_key_id','signing_public_keys','executive_public_keys'}
if set(p)!=expected or not all(isinstance(p[k],str) and p[k] for k in expected): raise SystemExit('invalid secret contract')
private=base64.b64decode(p['signing_private_key'],validate=True)
if len(private)!=64 or len(p['requester_key'])<32: raise SystemExit('invalid key material')
for field in ('signing_public_keys','executive_public_keys'):
 keys=json.loads(p[field])
 if not isinstance(keys,dict) or not 1<=len(keys)<=2: raise SystemExit('invalid public trust set')
 for key_id,value in keys.items():
  if not isinstance(key_id,str) or not isinstance(value,str) or len(base64.b64decode(value,validate=True))!=32: raise SystemExit('invalid public key')
 if field=='signing_public_keys' and p['signing_key_id'] not in keys: raise SystemExit('current key absent')
root=pathlib.Path(sys.argv[2]); files={'change-requester-key':p['requester_key'],'change-signing-private-key':p['signing_private_key'],'change-signing-public-keys.json':p['signing_public_keys'],'executive-public-keys.json':p['executive_public_keys']}
for name,value in files.items():
 path=root/name; path.write_text(value); path.chmod(0o600)
(root/'change.env').write_text('CYBERAUDIT_CHANGE_SIGNING_KEY_ID='+p['signing_key_id']+'\n');(root/'change.env').chmod(0o600)
PY
install -d -m 0700 "$destination"
expected='change-requester-key change-signing-private-key change-signing-public-keys.json executive-public-keys.json change.env'
for existing in "$destination"/*; do [[ -e "$existing" ]] || continue; [[ " $expected " == *" $(basename "$existing") "* ]] || { echo "unexpected runtime secret file" >&2; exit 1; }; done
for name in $expected; do install -m 0400 "$stage/$name" "$destination/.$name.new"; mv -f "$destination/.$name.new" "$destination/$name"; done
printf '[cyberaudit-secrets] materialized %s files\n' "$(find "$destination" -maxdepth 1 -type f | wc -l)"
