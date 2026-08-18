#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"; cd "$root"
region="${AWS_REGION:-us-east-2}"; bucket="${RELEASE_BUCKET:-fynix-releases-172670236523-us-east-2}"; instance="${INSTANCE_ID:-i-04578bd74b67567c1}"
revision="$(git rev-parse HEAD)"; artifact="${TMPDIR:-/tmp}/fynix-cyberaudit-$revision.tar.gz"; archive="s3://$bucket/cyberaudit/$revision.tar.gz"; work="/tmp/fynix-cyberaudit-$revision"
test -z "$(git status --porcelain --untracked-files=no)"
test "$revision" = "$(git rev-parse origin/main)"
test "$(aws sts get-caller-identity --query Account --output text)" = 172670236523
git ls-files '*.sh' | while read -r script; do bash -n "$script"; done
php artisan test
bash tests/deployment_scripts_test.sh
docker build --label "org.opencontainers.image.revision=$revision" -t "fynix-cyberaudit-release:$revision" .
git archive --format=tar HEAD | gzip -n >"$artifact"
local_size="$(wc -c <"$artifact"|tr -d ' ')"; aws s3 cp "$artifact" "$archive" --region "$region" --only-show-errors
test "$local_size" = "$(aws s3api head-object --bucket "$bucket" --key "cyberaudit/$revision.tar.gz" --region "$region" --query ContentLength --output text)"
ssm_payload="$(jq -nc --arg a "$archive" --arg w "$work" --arg r "$revision" '{commands:["set -eu","mkdir -p "+($w|@sh),"aws s3 cp "+($a|@sh)+" "+(($w+"/release.tar.gz")|@sh)+" --only-show-errors","tar -xzf "+(($w+"/release.tar.gz")|@sh)+" -C "+($w|@sh),"bash "+(($w+"/deploy/aws-update.sh")|@sh)+" /opt/fynix-suite/cyberaudit "+($r|@sh)]}')"
command_id="$(aws ssm send-command --region "$region" --instance-ids "$instance" --document-name AWS-RunShellScript --comment "Deploy CyberAudit $revision" --parameters "$ssm_payload" --query Command.CommandId --output text)"
for _ in $(seq 1 360); do status="$(aws ssm get-command-invocation --region "$region" --command-id "$command_id" --instance-id "$instance" --query Status --output text)"; [[ "$status" == Success ]]&&break; [[ "$status" =~ ^(Failed|Cancelled|TimedOut)$ ]]&&break; sleep 5; done
[[ "$status" == Success ]]; curl -fsS https://cyberaudit.fynixhq.com/api/suite/ready >/dev/null
printf 'revision=%s artifact=%s ssm_command_id=%s\n' "$revision" "$archive" "$command_id"
