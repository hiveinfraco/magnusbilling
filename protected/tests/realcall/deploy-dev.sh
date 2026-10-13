#!/bin/bash
# Deploy this fork onto a DEV MagnusBilling 8 VM that was installed with the
# upstream install.sh (spec Phase 0). Run from the repo root on your machine:
#
#   protected/tests/realcall/deploy-dev.sh root@DEV_VM_IP [ssh options...]
#
# Copies only files tracked by git, keeps the installed (build-generated)
# index.html, fixes ownership like install.sh, applies the trunk caps
# migrations through UpdateMysql, and installs SIPp for the real-call tests.
# NEVER point this at production.

set -euo pipefail
TARGET=${1:?usage: deploy-dev.sh root@host [ssh options]}
shift
SSH=(ssh "$@")
DEST=/var/www/html/mbilling

cd "$(git rev-parse --show-toplevel)"
git ls-files -z | grep -zv '^index.html$' | grep -zv '^CLAUDE.md$' |
    rsync -a --from0 --files-from=- -e "${SSH[*]}" ./ "$TARGET:$DEST/"

"${SSH[@]}" "$TARGET" bash -s <<'EOF'
set -e
cd /var/www/html/mbilling
chown -R asterisk:asterisk /var/www/html/mbilling
chmod +x resources/asterisk/mbilling.php
php cron.php UpdateMysql 2>&1 | grep -E 'TrunkCaps|version|rror' || true
php cron.php TrunkCapMigrate
command -v sipp >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y -q sip-tester >/dev/null
sipp -v 2>&1 | head -1
EOF
echo "Deployed $(git rev-parse --short HEAD) to $TARGET"
