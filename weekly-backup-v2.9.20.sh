#!/usr/bin/env bash
# Promote to King v2.9.20 — weekly single-archive backup of generated long-life filesystem data.
set -euo pipefail
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SITE_ROOT=${P2K_SITE_ROOT:-$SCRIPT_DIR}
DEST="$SITE_ROOT/_backup"
KEEP=${P2K_WEEKLY_BACKUP_KEEP:-52}
[[ "$KEEP" =~ ^[0-9]+$ ]] || KEEP=52
(( KEEP < 4 )) && KEEP=4
mkdir -p "$DEST"
chmod 700 "$DEST" 2>/dev/null || true
WEEK=$(date -u +'%G-W%V')
FINAL="$DEST/PromoteToKing_LongLife_${WEEK}.tar.gz"
if [[ -s "$FINAL" ]] && tar -tzf "$FINAL" >/dev/null 2>&1; then
  echo "Weekly long-life backup already exists and validates: ${FINAL#$SITE_ROOT/}"
  exit 0
fi
TMP="$DEST/.PromoteToKing_LongLife_${WEEK}.$$.tmp.tar.gz"
LIST=$(mktemp)
trap 'rm -f "$TMP" "$LIST"' EXIT
cd "$SITE_ROOT"
# Add only generated long-life roots that exist. Cache/lock/backup-of-backup trees are excluded below.
for p in \
  data/tournaments \
  data/live-ranks \
  data/match-tracking \
  data/traffic/aggregates \
  data/miac \
  data/archive-v280 \
  data/runtime-v280/intelligence/snapshots \
  data/runtime-v280/reconciliation \
  logs; do
  [[ -e "$p" ]] && printf '%s\n' "$p" >> "$LIST"
done
if [[ ! -s "$LIST" ]]; then echo "No long-life generated data roots exist; no archive created."; exit 0; fi
tar -czf "$TMP" \
  --exclude='data/tournaments/cache' --exclude='data/tournaments/locks' --exclude='data/tournaments/backups' \
  --exclude='data/live-ranks/backups' \
  --exclude='data/miac/seed/seed.zip' \
  --exclude='*/cache/*' --exclude='*/locks/*' --exclude='*/.pytest_cache/*' --exclude='*/__pycache__/*' \
  --exclude='_backup' \
  -T "$LIST"
tar -tzf "$TMP" >/dev/null
mv -f "$TMP" "$FINAL"
chmod 600 "$FINAL" 2>/dev/null || true
# Retain the newest KEEP successful weekly archives; never touch updater/state backups.
mapfile -t OLD < <(find "$DEST" -maxdepth 1 -type f -name 'PromoteToKing_LongLife_*.tar.gz' -printf '%T@ %p\n' | sort -nr | awk -v keep="$KEEP" 'NR>keep{sub(/^[^ ]+ /,"");print}')
for f in "${OLD[@]:-}"; do [[ -n "$f" ]] && rm -f -- "$f"; done
echo "Created weekly long-life backup: ${FINAL#$SITE_ROOT/}"
echo "Retention: newest $KEEP weekly archives."
