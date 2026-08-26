#!/usr/bin/env bash
set -Eeuo pipefail

TARGET_VERSION="2.10.5.5"
ROOT="${1:-$PWD}"
PKG="${2:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)}"
ROOT="$(cd "$ROOT" && pwd -P)"
PKG="$(cd "$PKG" && pwd -P)"
FILES="$PKG/FILES.tsv"
PAYLOAD="$PKG/payload"
fail(){ echo "ERROR: $*" >&2; exit 2; }
trim(){ tr -d '\r\n[:space:]' < "$1"; }
[[ -f "$ROOT/VERSION" ]] || fail "VERSION missing from $ROOT"
SOURCE_VERSION="$(trim "$ROOT/VERSION")"
if [[ "$SOURCE_VERSION" == "$TARGET_VERSION" ]]; then
  echo "Promote to King v$TARGET_VERSION is already installed; no files were changed."
  exit 0
fi
case "$SOURCE_VERSION" in
  2.10.5.3|2.10.5.4) ;;
  *) fail "Expected v2.10.5.3 or v2.10.5.4, found '$SOURCE_VERSION'." ;;
esac
[[ -f "$FILES" && -d "$PAYLOAD" ]] || fail "Cumulative package payload/manifest missing."
command -v sha256sum >/dev/null 2>&1 || fail "sha256sum is required."

while IFS=$'\t' read -r rel expected; do
  [[ -n "$rel" ]] || continue
  [[ "$rel" != /* && "$rel" != *".."* ]] || fail "Unsafe payload path: $rel"
  src="$PAYLOAD/$rel"
  [[ -f "$src" ]] || fail "Payload file missing: $rel"
  got="$(sha256sum "$src" | awk '{print $1}')"
  [[ "$got" == "$expected" ]] || fail "Payload SHA-256 mismatch: $rel"
done < "$FILES"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="$ROOT/_p2k_backups/v${SOURCE_VERSION}-to-v${TARGET_VERSION}-$STAMP"
mkdir -p "$BACKUP/files"
EXISTED="$BACKUP/existed.tsv"
: > "$EXISTED"

rollback(){
  rc=$?
  trap - ERR INT TERM
  echo "Update failed; restoring v$SOURCE_VERSION files..." >&2
  while IFS=$'\t' read -r rel existed; do
    [[ -n "$rel" ]] || continue
    if [[ "$existed" == "1" ]]; then
      mkdir -p "$(dirname "$ROOT/$rel")"
      cp -p "$BACKUP/files/$rel" "$ROOT/$rel"
    else
      rm -f "$ROOT/$rel"
    fi
  done < "$EXISTED"
  echo "Rollback complete. Backup: $BACKUP" >&2
  exit "$rc"
}
trap rollback ERR INT TERM

while IFS=$'\t' read -r rel expected; do
  [[ -n "$rel" ]] || continue
  dst="$ROOT/$rel"
  if [[ -f "$dst" ]]; then
    printf '%s\t1\n' "$rel" >> "$EXISTED"
    mkdir -p "$BACKUP/files/$(dirname "$rel")"
    cp -p "$dst" "$BACKUP/files/$rel"
  else
    printf '%s\t0\n' "$rel" >> "$EXISTED"
  fi
  mkdir -p "$(dirname "$dst")"
  cp -p "$PAYLOAD/$rel" "$dst"
done < "$FILES"

if [[ "${P2K_V21055_FORCE_FAIL_AFTER_COPY:-0}" == "1" ]]; then
  echo "Forced post-copy failure requested for rollback validation." >&2
  false
fi

[[ "$(trim "$ROOT/VERSION")" == "$TARGET_VERSION" ]] || fail "VERSION did not advance to $TARGET_VERSION."
[[ "$(trim "$ROOT/MIGRATION_VERSION")" == "$TARGET_VERSION" ]] || fail "MIGRATION_VERSION did not advance to $TARGET_VERSION."
grep -q 'version: "2.10.5.5"' "$ROOT/assets/js/site-config.js" || fail "Browser site-config marker is stale."
grep -q 'canonicalBoardProjection' "$ROOT/server/team-points-green/src/GreenCompatibility.php" || fail "GAB duplicate-lineup compatibility corrective missing."
grep -q 'recoverQuickCompleteTransition' "$ROOT/server/team-points-green/src/GreenRepository.php" || fail "quick_complete recovery missing."
grep -q 'bilinearDensity' "$ROOT/assets/js/pages/opponent-balance-analyzer.js" || fail "Interpolated Zoned Density Heatmap missing."
grep -q 'const kernel = \[\[1,2,1\],\[2,4,2\],\[1,2,1\]\]' "$ROOT/assets/js/pages/opponent-balance-analyzer.js" || fail "Agreed heatmap smoothing kernel missing."
grep -q 'MATCH_MONITORING_AUTO_STOP_AFTER_START_SECONDS = 86400' "$ROOT/api/_common.php" || fail "v2.10.5.4 tracking retirement feature missing."
grep -q 'recentCycleDurations' "$ROOT/server/team-points-green/src/GreenRepository.php" || fail "v2.10.5.4 cycle-duration telemetry missing."

trap - ERR INT TERM
printf 'SUCCESS: Promote to King v%s installed from v%s.\nBackup: %s\n' "$TARGET_VERSION" "$SOURCE_VERSION" "$BACKUP"
printf '%s\n' 'No DB/schema/reseed/CRON change was performed.'
