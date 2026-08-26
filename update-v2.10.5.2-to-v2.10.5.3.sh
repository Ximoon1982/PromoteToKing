#!/usr/bin/env bash
set -Eeuo pipefail

# Promote to King v2.10.5.2 -> v2.10.5.3
# Exact source-overlay corrective. Accepts pristine v2.10.5.2 or v2.10.5.2+GABFIX1.
# No DB/schema/reseed/CRON operation is performed by this updater.

TARGET_VERSION="2.10.5.3"
ROOT="${1:-$PWD}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PACKAGE_DIR="${2:-$SCRIPT_DIR}"
PAYLOAD="$PACKAGE_DIR/payload"
SUMS="$PACKAGE_DIR/SHA256SUMS.txt"

fail(){ echo "ERROR: $*" >&2; exit 2; }
trim(){ tr -d '\r\n[:space:]' < "$1"; }

[[ -d "$ROOT" ]] || fail "Site root not found: $ROOT"
ROOT="$(cd "$ROOT" && pwd -P)"
[[ -d "$PAYLOAD" ]] || fail "Release payload missing: $PAYLOAD"
[[ -f "$SUMS" ]] || fail "Release checksum file missing: $SUMS"
[[ -f "$ROOT/VERSION" ]] || fail "VERSION missing from site root"
CURRENT="$(trim "$ROOT/VERSION")"
case "$CURRENT" in
  2.10.5.2|2.10.5.3) ;;
  *) fail "Expected v2.10.5.2 (with or without GABFIX1) or idempotent v2.10.5.3; found '$CURRENT'" ;;
esac

command -v sha256sum >/dev/null 2>&1 || fail "sha256sum is required"
(
  cd "$PACKAGE_DIR"
  sha256sum -c SHA256SUMS.txt >/dev/null
) || fail "Release payload checksum validation failed"

PROTECTED=(
  "server/team-points/config/config.local.php"
  "server/team-points/config/oauth.local.php"
  "server/team-points-green/config/green.local.php"
)
is_protected(){
  local r="$1" p
  for p in "${PROTECTED[@]}"; do [[ "$r" == "$p" ]] && return 0; done
  return 1
}

BACKUP="$(mktemp -d "${TMPDIR:-/tmp}/p2k-v21053-rollback.XXXXXX")"
MUTATED=0
COMMITTED=0
rollback(){
  local rel
  [[ "$MUTATED" == 1 && "$COMMITTED" == 0 ]] || return 0
  echo "Restoring v2.10.5.2 source after failed v2.10.5.3 update..." >&2
  if [[ -f "$BACKUP/existing.list" ]]; then
    while IFS= read -r rel; do
      [[ -n "$rel" ]] || continue
      mkdir -p "$ROOT/$(dirname "$rel")"
      cp -p "$BACKUP/files/$rel" "$ROOT/$rel"
    done < "$BACKUP/existing.list"
  fi
  if [[ -f "$BACKUP/added.list" ]]; then
    while IFS= read -r rel; do
      [[ -n "$rel" ]] || continue
      rm -f "$ROOT/$rel"
    done < "$BACKUP/added.list"
  fi
}
cleanup(){
  local rc=$?
  if [[ $rc -ne 0 ]]; then rollback || true; fi
  rm -rf "$BACKUP"
  exit $rc
}
trap cleanup EXIT

: > "$BACKUP/existing.list"
: > "$BACKUP/added.list"
mkdir -p "$BACKUP/files"

while IFS= read -r -d '' src; do
  rel="${src#$PAYLOAD/}"
  is_protected "$rel" && continue
  if [[ -f "$ROOT/$rel" ]]; then
    mkdir -p "$BACKUP/files/$(dirname "$rel")"
    cp -p "$ROOT/$rel" "$BACKUP/files/$rel"
    printf '%s\n' "$rel" >> "$BACKUP/existing.list"
  else
    printf '%s\n' "$rel" >> "$BACKUP/added.list"
  fi
done < <(find "$PAYLOAD" -type f -print0 | sort -z)

MUTATED=1
while IFS= read -r -d '' src; do
  rel="${src#$PAYLOAD/}"
  is_protected "$rel" && continue
  mkdir -p "$ROOT/$(dirname "$rel")"
  cp -p "$src" "$ROOT/$rel"
done < <(find "$PAYLOAD" -type f -print0 | sort -z)

[[ "${P2K_FORCE_POST_COPY_FAIL:-0}" != 1 ]] || fail "Forced post-copy failure requested for rollback validation"

PHP_BIN=""
for p in /usr/bin/php8.5-cli /usr/bin/php8.4-cli /usr/bin/php8.3-cli "$(command -v php 2>/dev/null || true)"; do
  [[ -n "$p" && -x "$p" ]] && { PHP_BIN="$p"; break; }
done
[[ -n "$PHP_BIN" ]] || fail "No PHP CLI available for post-copy syntax validation"
for rel in \
  server/team-points-green/src/GreenRepository.php \
  server/team-points-green/src/GreenWorker.php \
  server/team-points-green/src/GreenAnalyticsBootstrap.php \
  server/team-points-green/public/api.php; do
  "$PHP_BIN" -l "$ROOT/$rel" >/dev/null || fail "PHP syntax validation failed: $rel"
done

if command -v node >/dev/null 2>&1; then
  node --check "$ROOT/assets/js/pages/task-control.js" >/dev/null || fail "JavaScript syntax validation failed: task-control.js"
  node --check "$ROOT/assets/js/pages/team-points-migration.js" >/dev/null || fail "JavaScript syntax validation failed: team-points-migration.js"
fi

[[ "$(trim "$ROOT/VERSION")" == "$TARGET_VERSION" ]] || fail "VERSION marker is not $TARGET_VERSION"
[[ "$(trim "$ROOT/MIGRATION_VERSION")" == "$TARGET_VERSION" ]] || fail "MIGRATION_VERSION marker is not $TARGET_VERSION"
grep -q 'version: "2.10.5.3"' "$ROOT/assets/js/site-config.js" || fail "Browser runtime version marker is not v2.10.5.3"
grep -q 'GQAC_TRANSIENT_ATTEMPT_LIMIT = 3' "$ROOT/server/team-points-green/src/GreenRepository.php" || fail "GQAC bounded-transient corrective is missing"
grep -q 'softTargetSeconds\*0.70' "$ROOT/server/team-points-green/src/GreenWorker.php" || fail "Finite-cycle fairness slice is missing"
grep -q 'ordinaryLimit=max(0,\$limit-count(\$urls))' "$ROOT/server/team-points-green/src/GreenRepository.php" || fail "Accelerator remaining-slot guard is missing"

COMMITTED=1
trap - EXIT
rm -rf "$BACKUP"

echo "============================================================"
echo "SUCCESS: Promote to King v2.10.5.3 installed."
echo "- No DB/schema/reseed/CRON operation was performed."
echo "- Existing Green cycle/GAB/GFFL state was preserved."
echo "- GFFL should remain enabled."
echo "- The current finite cycle may self-heal exhausted transient GQAC rows."
echo "============================================================"
