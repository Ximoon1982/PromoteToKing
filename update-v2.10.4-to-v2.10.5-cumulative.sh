#!/usr/bin/env bash
set -Eeuo pipefail

TARGET_VERSION="2.10.5"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PAYLOAD="$SCRIPT_DIR/payload"
ROOT="${1:-$(pwd)}"
ROOT="$(cd "$ROOT" && pwd -P)"

fail(){ echo "ERROR: $*" >&2; exit 2; }
trim_file(){ tr -d '\r\n[:space:]' < "$1"; }

[[ -d "$PAYLOAD" ]] || fail "Incremental payload directory is missing next to this updater."
[[ -f "$ROOT/MIGRATION_VERSION" ]] || fail "MIGRATION_VERSION is missing from target site."
CURRENT="$(trim_file "$ROOT/MIGRATION_VERSION")"
case "$CURRENT" in
  2.10.4|2.10.4.1|2.10.4.2|2.10.4.3|2.10.4.4|2.10.4.5|2.10.4.6|2.10.5) ;;
  *) fail "Expected a v2.10.4.x migration baseline or idempotent 2.10.5; found '$CURRENT'." ;;
esac

# Payload must never carry protected local credentials/runtime state.
for rel in \
  server/team-points/config/config.local.php \
  server/team-points/config/oauth.local.php \
  server/team-points-green/config/green.local.php \
  data/server-config.json; do
  [[ ! -e "$PAYLOAD/$rel" ]] || fail "Protected local file unexpectedly present in payload: $rel"
done

PHP_BIN=""
for cand in /usr/bin/php8.5-cli /usr/bin/php8.5 /usr/bin/php8.4-cli /usr/bin/php8.4 /usr/bin/php8.3-cli /usr/bin/php8.3 /usr/bin/php8.2-cli /usr/bin/php8.2 /usr/bin/php8.1-cli /usr/bin/php8.1 /usr/bin/php8.0-cli /usr/bin/php8.0; do
  [[ -x "$cand" ]] || continue
  ver="$($cand -r 'echo PHP_VERSION;' 2>/dev/null || true)"; major="${ver%%.*}"
  [[ "$major" =~ ^[0-9]+$ ]] && (( major >= 8 )) && { PHP_BIN="$cand"; break; }
done
[[ -n "$PHP_BIN" ]] || fail "PHP 8 CLI not found. On IONOS this release expects /usr/bin/php8.5-cli or another PHP 8 CLI."
command -v python3 >/dev/null 2>&1 || fail "python3 is required."

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="$ROOT/_backup/${STAMP}_v${CURRENT}-to-v${TARGET_VERSION}"
mkdir -p "$BACKUP/files"
NEW_LIST="$BACKUP/new-files.txt"
EXISTED_LIST="$BACKUP/existed.txt"
: > "$NEW_LIST"; : > "$EXISTED_LIST"

CRON_BEFORE="$BACKUP/crontab-before.txt"
HAVE_CRONTAB=0
if command -v crontab >/dev/null 2>&1; then
  crontab -l > "$CRON_BEFORE" 2>/dev/null || true
  HAVE_CRONTAB=1
fi

rollback(){
  rc=$?
  if (( rc != 0 )); then
    echo "Failure detected; restoring source files from $BACKUP ..." >&2
    while IFS= read -r -d '' f; do
      rel="${f#"$BACKUP/files/"}"
      mkdir -p "$ROOT/$(dirname "$rel")"
      cp -p "$f" "$ROOT/$rel"
    done < <(find "$BACKUP/files" -type f -print0)
    if [[ -f "$NEW_LIST" ]]; then
      while IFS= read -r rel; do [[ -n "$rel" ]] && rm -f "$ROOT/$rel"; done < "$NEW_LIST"
    fi
    if (( HAVE_CRONTAB )) && [[ -f "$CRON_BEFORE" ]]; then crontab "$CRON_BEFORE" 2>/dev/null || true; fi
    echo "Source/CRON rollback complete. Any already-applied v2.10.5 Green schema step is additive/idempotent and is not destructively rolled back." >&2
  fi
  exit "$rc"
}
trap rollback EXIT

echo "===== Promote to King v2.10.5 cumulative update ====="
echo "Site root:       $ROOT"
echo "Current release: $CURRENT"
echo "Target release:  $TARGET_VERSION"
echo "PHP CLI:         $PHP_BIN"
echo "Backup:          $BACKUP"
echo

echo "[1/6] Preflight payload validation..."
[[ "$(trim_file "$PAYLOAD/VERSION")" == "$TARGET_VERSION" ]] || fail "Payload VERSION is not $TARGET_VERSION."
[[ "$(trim_file "$PAYLOAD/MIGRATION_VERSION")" == "$TARGET_VERSION" ]] || fail "Payload MIGRATION_VERSION is not $TARGET_VERSION."
"$PHP_BIN" -l "$PAYLOAD/server/team-points-green/src/GreenRepository.php" >/dev/null
"$PHP_BIN" -l "$PAYLOAD/server/team-points-green/src/GreenWorker.php" >/dev/null
"$PHP_BIN" -l "$PAYLOAD/server/team-points-green/public/api.php" >/dev/null
"$PHP_BIN" -l "$PAYLOAD/server/team-points-green/tools/migrate-v2.10.5.php" >/dev/null
bash -n "$PAYLOAD/reset-install-green-cron-v2.10.5.sh"

echo "[2/6] Backing up and overlaying sanitized release source..."
COUNT=0
SKIPPED=0
while IFS= read -r -d '' src; do
  rel="${src#"$PAYLOAD/"}"
  # assets/images are deliberately unchanged and excluded from the incremental package.
  [[ "$rel" == assets/images/* ]] && continue
  case "$rel" in
    server/team-points/config/config.local.php|server/team-points/config/oauth.local.php|server/team-points-green/config/green.local.php|data/server-config.json) fail "Protected path encountered: $rel" ;;
  esac
  dst="$ROOT/$rel"
  # A cumulative package carries the complete sanitized non-image source, but
  # identical bytes do not need backup/rewrite. This keeps upgrades from later
  # v2.10.4.x hotfixes fast while still converging pristine v2.10.4 safely.
  if [[ -f "$dst" ]] && cmp -s "$src" "$dst"; then
    SKIPPED=$((SKIPPED+1))
    continue
  fi
  if [[ -f "$dst" ]]; then
    mkdir -p "$BACKUP/files/$(dirname "$rel")"
    cp -p "$dst" "$BACKUP/files/$rel"
    printf '%s\n' "$rel" >> "$EXISTED_LIST"
  else
    printf '%s\n' "$rel" >> "$NEW_LIST"
  fi
  mkdir -p "$(dirname "$dst")"
  cp -p "$src" "$dst"
  COUNT=$((COUNT+1))
done < <(find "$PAYLOAD" -type f -print0 | sort -z)
echo "Overlayed $COUNT changed/new non-image source files; $SKIPPED identical files left untouched."

echo "[3/6] Validating installed source identity and syntax..."
[[ "$(trim_file "$ROOT/VERSION")" == "$TARGET_VERSION" ]] || fail "VERSION did not advance."
[[ "$(trim_file "$ROOT/MIGRATION_VERSION")" == "$TARGET_VERSION" ]] || fail "MIGRATION_VERSION did not advance."
"$PHP_BIN" -l "$ROOT/server/team-points-green/src/GreenRepository.php" >/dev/null
"$PHP_BIN" -l "$ROOT/server/team-points-green/src/GreenWorker.php" >/dev/null
"$PHP_BIN" -l "$ROOT/server/team-points-green/public/api.php" >/dev/null
if command -v node >/dev/null 2>&1; then
  node --check "$ROOT/assets/js/pages/dashboard-v2.js" >/dev/null
  node --check "$ROOT/assets/js/pages/task-control.js" >/dev/null
  node --check "$ROOT/assets/js/pages/team-points-admin.js" >/dev/null
  node --check "$ROOT/assets/js/pages/team-points-migration.js" >/dev/null
fi

echo "[4/6] Applying idempotent Green schema compatibility..."
"$PHP_BIN" "$ROOT/server/team-points-green/tools/migrate-v2.10.5.php"

echo "[5/6] Installing accepted Green scheduler contract when configured..."
bash "$ROOT/reset-install-green-cron-v2.10.5.sh" "$ROOT" "${P2K_BASE_URL:-https://www.promotetoking.org}"

echo "[6/6] Final safety checks..."
[[ "$(trim_file "$ROOT/VERSION")" == "$TARGET_VERSION" ]] || fail "Final VERSION mismatch."
[[ "$(trim_file "$ROOT/MIGRATION_VERSION")" == "$TARGET_VERSION" ]] || fail "Final MIGRATION_VERSION mismatch."
grep -q "public_source'=>'blue_hardwired'" "$ROOT/server/team-points-green/public/api.php" || fail "Default public-source safety marker missing."
grep -q 'retireIneligibleQuickBoardItems' "$ROOT/server/team-points-green/src/GreenRepository.php" || fail "GQAC retirement fix missing."
grep -q 'p2k_g_member_events' "$ROOT/server/team-points-green/src/GreenRepository.php" || fail "Member chronology backend missing."

trap - EXIT

echo
echo "SUCCESS: Promote to King v2.10.5 installed."
echo "Source baseline accepted: $CURRENT"
echo "Backup: $BACKUP"
echo "No database reset or reseed was performed. Public reads remain Blue by default."
