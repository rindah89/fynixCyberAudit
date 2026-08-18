#!/usr/bin/env bash
set -euo pipefail
region="${AWS_REGION:-us-east-2}"; source_id="${CYBERAUDIT_CHANGE_SECRET_ID:-fynix/cyberaudit/change-evidence}"; target_id="${FYNIX_SUPPORT_CYBERAUDIT_SECRET_ID:-fynix/support/cyberaudit-change-evidence}"; kms="${FYNIX_SUPPORT_KMS_KEY_ID:-alias/fynix-support-secrets}"
[[ "$(id -u)" == 0 ]]; findmnt -n -o FSTYPE /run | grep -qx tmpfs; umask 077; stage="$(mktemp -d /run/fynix-cyber-support-handoff.XXXXXX)"; trap 'rm -rf -- "$stage"' EXIT
aws secretsmanager get-secret-value --region "$region" --secret-id "$source_id" --query SecretString --output text >"$stage/source.json"; chmod 0600 "$stage/source.json"
jq -e 'keys|sort==["executive_public_keys","requester_key","signing_key_id","signing_private_key","signing_public_keys"]' "$stage/source.json" >/dev/null
jq '{cyberaudit_change_requester_api_key:.requester_key,cyberaudit_change_signing_public_keys:.signing_public_keys}' "$stage/source.json" >"$stage/support.json"; chmod 0600 "$stage/support.json"
jq -e 'keys|sort==["cyberaudit_change_requester_api_key","cyberaudit_change_signing_public_keys"] and (.cyberaudit_change_requester_api_key|length>=32) and (.cyberaudit_change_signing_public_keys|fromjson|type=="object")' "$stage/support.json" >/dev/null
if aws secretsmanager describe-secret --region "$region" --secret-id "$target_id" >/dev/null 2>&1; then aws secretsmanager put-secret-value --region "$region" --secret-id "$target_id" --secret-string "file://$stage/support.json" --query VersionId --output text >/dev/null; else aws secretsmanager create-secret --region "$region" --name "$target_id" --kms-key-id "$kms" --secret-string "file://$stage/support.json" --tags Key=Project,Value=FynixSupport Key=DataClassification,Value=VendorOperationsRestricted --query ARN --output text >/dev/null; fi
printf '[cyberaudit-secrets] public-only Support handoff completed\n'
