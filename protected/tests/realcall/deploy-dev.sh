#!/bin/bash
# Deploy the trunk caps fork onto a DEV MagnusBilling 8 VM installed with the
# upstream install.sh (spec Phase 0). Run from your machine, inside the repo:
#
#   protected/tests/realcall/deploy-dev.sh root@DEV_VM_IP [ssh options...]
#
# The upstream download package can be newer than the GitHub source this fork
# is based on, so this does NOT copy the whole tree over the install:
#   1. files the fork adds are copied as they are;
#   2. the fork's small edits to upstream files are applied as a patch to the
#      installed (possibly newer) versions; the deploy stops if it does not
#      apply cleanly, and is skipped if it is already applied.
# Then: ownership and modes exactly like install.sh (a blanket chown breaks the
# panel: Apache must own protected/runtime), trunk caps migrations, SIPp.
# NEVER point this at production.

set -euo pipefail
TARGET=${1:?usage: deploy-dev.sh root@host [ssh options]}
shift
SSH=(ssh "$@")
DEST=/var/www/html/mbilling
BASE=$(git merge-base HEAD upstream/source 2>/dev/null || echo eae92c56)

cd "$(git rev-parse --show-toplevel)"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# 1. files added by the fork (docs and the spec stay out of the web root)
git diff --name-only -z --diff-filter=A "$BASE" HEAD | grep -zv '^docs/' | grep -zv '^CLAUDE.md$' >"$TMP/added"
rsync -a --from0 --files-from="$TMP/added" -e "${SSH[*]}" ./ "$TARGET:$DEST/"

# 2. the fork's edits to upstream PHP files, as a patch
git diff "$BASE" HEAD -- $(git diff --name-only --diff-filter=M "$BASE" HEAD | grep '\.php$') >"$TMP/core.patch"
rsync -a -e "${SSH[*]}" "$TMP/core.patch" "$TARGET:/root/trunkcaps-core.patch"

"${SSH[@]}" "$TARGET" bash -s <<'EOF'
set -e
cd /var/www/html/mbilling
if patch -p1 -R --dry-run -s -f < /root/trunkcaps-core.patch >/dev/null 2>&1; then
    echo "core patch: already applied"
elif patch -p1 --dry-run -s -f < /root/trunkcaps-core.patch >/dev/null; then
    patch -p1 -s -f --no-backup-if-mismatch < /root/trunkcaps-core.patch && echo "core patch: applied"
else
    echo "core patch: does NOT apply to the installed files; nothing patched" >&2
    exit 1
fi
# Same ownership and modes as install.sh: root-owned tree, web-writable dirs for
# Apache, resources/asterisk for the AGI (Asterisk) user only.
APACHE_USER=$(. /etc/apache2/envvars 2>/dev/null; echo "${APACHE_RUN_USER:-www-data}")
M=/var/www/html/mbilling
chown -R root:root $M
find $M -type d -exec chmod 755 {} \;
find $M -type f -exec chmod 644 {} \;
for d in protected/runtime assets tmp resources/reports resources/images; do
    [ -d "$M/$d" ] || continue
    chown -R "$APACHE_USER:$APACHE_USER" "$M/$d"
    find "$M/$d" -type d -exec chmod 750 {} \;
    find "$M/$d" -type f -exec chmod 640 {} \;
done
chown -R asterisk:asterisk $M/resources/asterisk
chmod +x $M/resources/asterisk/mbilling.php $M/protected/commands/*.sh
chmod 500 $M/resources/asterisk $M/resources/asterisk/mbilling.php
php cron.php UpdateMysql 2>&1 | grep -E 'TrunkCaps|version|rror' || true
php cron.php TrunkCapMigrate
command -v sipp >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y -q sip-tester >/dev/null
sipp -v 2>&1 | grep -m1 -i 'sipp v' || true
EOF
echo "Deployed $(git rev-parse --short HEAD) to $TARGET"
