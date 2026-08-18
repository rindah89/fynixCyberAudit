#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || exit 1
source_dir="$(cd "$(dirname "$0")" && pwd)"
install -m 0755 "$source_dir/materialize-change-evidence-secrets.sh" /usr/local/sbin/fynix-cyberaudit-materialize-secrets
install -m 0644 "$source_dir/fynix-cyberaudit-secrets.service" /etc/systemd/system/fynix-cyberaudit-secrets.service
[ -d /usr/local/sbin ]
systemctl daemon-reload
systemctl enable --now fynix-cyberaudit-secrets.service
systemctl is-active --quiet fynix-cyberaudit-secrets.service
test "$(stat -c %a /run/fynix-cyberaudit/change-signing-private-key)" = 400
