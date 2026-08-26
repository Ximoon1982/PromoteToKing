#!/usr/bin/env bash
# v2.9.14: compact rollback-state backup helper for the v2.9.14+ updater path.
# Creates O(1) backup filesystem objects instead of copying large runtime trees.
set -euo pipefail

SITE_ROOT=${1:-$(pwd)}
LABEL=${2:-pre-update}
RETENTION=${P2K_BACKUP_RETENTION:-3}
STAMP=${P2K_BACKUP_STAMP:-$(date -u +%Y%m%dT%H%M%SZ)}
BACKUP_ROOT="$SITE_ROOT/_backup"
SAFE_LABEL=$(printf '%s' "$LABEL" | tr -cs 'A-Za-z0-9._-' '_')
ARCHIVE="$BACKUP_ROOT/${STAMP}_${SAFE_LABEL}.tar.gz"
CHECKSUM="$ARCHIVE.sha256"

mkdir -p "$BACKUP_ROOT"
chmod 700 "$BACKUP_ROOT" 2>/dev/null || true
TMP=$(mktemp "${TMPDIR:-/tmp}/p2k-backup.XXXXXX")
trap 'rm -f "$TMP"' EXIT

# Inventory only paths that actually exist. Runtime caches/rate-limit/lock state are
# reconstructible and intentionally excluded from rollback state.
cd "$SITE_ROOT"
for path in \
  config \
  server/team-points/config \
  data \
  logs \
  server/logs \
  server/team-points/logs
  do
    [[ -e "$path" ]] && printf '%s\n' "$path" >> "$TMP"
  done

if [[ ! -s "$TMP" ]]; then
  echo "No mutable P2K state paths exist; no archive created."
  exit 0
fi

# One archive object replaces potentially tens of thousands of copied files.
# Exclusions are all reconstructible/ephemeral. Historical match tracking, Live
# Ranks data, tournament durable state, intelligence snapshots and reconciliation
# outputs remain in the archive unless explicitly classified ephemeral here.
tar -czf "$ARCHIVE" \
  --exclude='data/runtime-v280/cache' \
  --exclude='data/runtime-v280/cache/**' \
  --exclude='data/runtime-v280/public-response-cache' \
  --exclude='data/runtime-v280/public-response-cache/**' \
  --exclude='data/runtime-v280/browser-observations' \
  --exclude='data/runtime-v280/browser-observations/**' \
  --exclude='data/runtime-v280/acamr' \
  --exclude='data/runtime-v280/acamr/**' \
  --exclude='data/runtime-v280/telemetry' \
  --exclude='data/runtime-v280/telemetry/**' \
  --exclude='data/runtime-v280/fresh-init' \
  --exclude='data/runtime-v280/fresh-init/**' \
  --exclude='data/runtime-v280/.filesystem-object-stats.json' \
  --exclude='data/runtime-v280/housekeeping-state.json' \
  --exclude='data/runtime-v280/cron-shell' \
  --exclude='data/runtime-v280/cron-shell/**' \
  --exclude='data/tournaments/cache' \
  --exclude='data/tournaments/cache/**' \
  --exclude='data/tournaments/locks' \
  --exclude='data/tournaments/locks/**' \
  --files-from="$TMP"
chmod 600 "$ARCHIVE" 2>/dev/null || true
(cd "$BACKUP_ROOT" && sha256sum "$(basename "$ARCHIVE")") > "$CHECKSUM.tmp"
mv "$CHECKSUM.tmp" "$CHECKSUM"
chmod 600 "$CHECKSUM" 2>/dev/null || true

# Retain a small number of compact rollback archives. Never remove the archive
# just created, and prune checksum sidecars with their archive.
mapfile -t archives < <(find "$BACKUP_ROOT" -maxdepth 1 -type f -name '*_state-*.tar.gz' -printf '%T@ %p\n' 2>/dev/null | sort -nr | cut -d' ' -f2-)
keep=$(( RETENTION < 1 ? 1 : RETENTION ))
for ((i=keep; i<${#archives[@]}; i++)); do
  old=${archives[$i]}
  [[ "$old" == "$ARCHIVE" ]] && continue
  rm -f -- "$old" "$old.sha256"
done

echo "Backup archive: $ARCHIVE"
echo "Backup objects created: 2 (archive + checksum)"
