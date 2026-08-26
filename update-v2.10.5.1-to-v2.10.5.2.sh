#!/usr/bin/env bash
set -Eeuo pipefail

# Promote to King v2.10.5.1 -> v2.10.5.2 cumulative Green cutover update.
#
# Scope: Green Analytics Bootstrap (GAB), Green Factorized Freshness Lane (GFFL),
# Green public read/write compatibility routing, migration-control corrective,
# Green-first Scheduled Task Control, and Dashboard Match Assistant visibility.
#
# No Green Core reseed. No destructive database reset. No image payload.

TARGET="2.10.5.2"
PKG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PAYLOAD="$PKG_DIR/payload"
SUMS="$PKG_DIR/SHA256SUMS.txt"
ROOT="${1:-$PWD}"
ROOT="$(cd "$ROOT" && pwd -P)"

BACKUP=""
ROLLBACK_ACTIVE=0
SCHEMA_ATTEMPTED=0

trim(){ tr -d '\r\n[:space:]' < "$1"; }

rollback(){
  local rc="${1:-1}"
  trap - ERR INT TERM
  set +e
  if [[ "$ROLLBACK_ACTIVE" -eq 1 && -n "$BACKUP" && -d "$BACKUP" ]]; then
    echo "v2.10.5.2 install failed; rolling source files back..." >&2
    if [[ -f "$BACKUP/existed.txt" ]]; then
      while IFS= read -r rel; do
        [[ -n "$rel" ]] || continue
        if [[ -f "$BACKUP/files/$rel" ]]; then
          mkdir -p "$ROOT/$(dirname "$rel")"
          cp -p "$BACKUP/files/$rel" "$ROOT/$rel"
        fi
      done < "$BACKUP/existed.txt"
    fi
    if [[ -f "$BACKUP/new-files.txt" ]]; then
      while IFS= read -r rel; do
        [[ -n "$rel" ]] || continue
        rm -f "$ROOT/$rel"
      done < "$BACKUP/new-files.txt"
    fi
    echo "Source rollback complete: $BACKUP" >&2
    if [[ "$SCHEMA_ATTEMPTED" -eq 1 ]]; then
      echo "Note: additive Green schema CREATE/ALTER operations are intentionally not rolled back; they are backward-compatible and idempotent." >&2
    fi
  fi
  exit "$rc"
}

fail(){
  echo "ERROR: $*" >&2
  if [[ "$ROLLBACK_ACTIVE" -eq 1 ]]; then rollback 2; fi
  exit 2
}

[[ -f "$ROOT/VERSION" && -f "$ROOT/MIGRATION_VERSION" ]] || fail "VERSION/MIGRATION_VERSION markers are missing from $ROOT"
CURRENT="$(trim "$ROOT/VERSION")"
CURRENT_MIG="$(trim "$ROOT/MIGRATION_VERSION")"
case "$CURRENT" in 2.10.5.1|2.10.5.2) ;; *) fail "Expected v2.10.5.1 or idempotent v2.10.5.2; found VERSION=$CURRENT";; esac
case "$CURRENT_MIG" in 2.10.5.1|2.10.5.2) ;; *) fail "Expected migration baseline v2.10.5.1 or v2.10.5.2; found MIGRATION_VERSION=$CURRENT_MIG";; esac
[[ -d "$PAYLOAD" && -f "$SUMS" ]] || fail "Updater payload/checksum manifest is incomplete"
command -v sha256sum >/dev/null 2>&1 || fail "sha256sum is required"
command -v python3 >/dev/null 2>&1 || fail "python3 is required"

(
  cd "$PKG_DIR"
  sha256sum -c SHA256SUMS.txt >/dev/null
) || fail "Updater package checksum verification failed"

# Protect deployment-specific credentials and local runtime configuration.
PROTECTED=(
  "server/team-points/config/config.local.php"
  "server/team-points/config/oauth.local.php"
  "server/team-points-green/config/green.local.php"
)
for rel in "${PROTECTED[@]}"; do
  [[ ! -e "$PAYLOAD/$rel" ]] || fail "Protected deployment file unexpectedly present in payload: $rel"
done

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="$ROOT/_backup/${STAMP}_v2.10.5.1-to-v2.10.5.2-green-cutover"
mkdir -p "$BACKUP/files"
: > "$BACKUP/existed.txt"
: > "$BACKUP/new-files.txt"
ROLLBACK_ACTIVE=1
trap 'rc=$?; rollback "$rc"' ERR INT TERM

mapfile -t FILES < <(cd "$PAYLOAD" && find . -type f -print | sed 's#^./##' | LC_ALL=C sort)
[[ ${#FILES[@]} -gt 0 ]] || fail "Updater payload is empty"

for rel in "${FILES[@]}"; do
  for protected in "${PROTECTED[@]}"; do [[ "$rel" != "$protected" ]] || fail "Protected deployment file in payload: $rel"; done
  [[ "$rel" != assets/images/* ]] || fail "Image payload must remain in the separate synchronized image archive: $rel"
  if [[ -f "$ROOT/$rel" ]]; then
    echo "$rel" >> "$BACKUP/existed.txt"
    mkdir -p "$BACKUP/files/$(dirname "$rel")"
    cp -p "$ROOT/$rel" "$BACKUP/files/$rel"
  else
    echo "$rel" >> "$BACKUP/new-files.txt"
  fi
  mkdir -p "$ROOT/$(dirname "$rel")"
  cp -p "$PAYLOAD/$rel" "$ROOT/$rel"
done

# Apply only additive/idempotent Green schema changes when Green is configured.
# GAB itself is NOT auto-started: the admin explicitly starts/resumes it from
# Scheduled Task Control after installation.
if [[ -f "$ROOT/server/team-points-green/config/green.local.php" ]]; then
  PHP_BIN="${P2K_PHP_BIN:-}"
  if [[ -z "$PHP_BIN" ]]; then
    for candidate in /usr/bin/php8.5-cli /usr/bin/php8.4-cli /usr/bin/php8.3-cli php8.5 php8.4 php8.3 php; do
      if [[ "$candidate" = /* ]]; then [[ -x "$candidate" ]] && { PHP_BIN="$candidate"; break; }
      elif command -v "$candidate" >/dev/null 2>&1; then PHP_BIN="$(command -v "$candidate")"; break
      fi
    done
  fi
  [[ -n "$PHP_BIN" && -x "$PHP_BIN" ]] || fail "PHP 8.1+ CLI is required for the additive Green schema upgrade"
  PHP_VERSION="$($PHP_BIN -r 'echo PHP_VERSION;' 2>/dev/null || true)"
  python3 - "$PHP_VERSION" <<'PY' || fail "PHP 8.1+ CLI required; selected version is $PHP_VERSION"
import sys
v=sys.argv[1].split('.')
try: ok=(int(v[0]),int(v[1])) >= (8,1)
except Exception: ok=False
raise SystemExit(0 if ok else 1)
PY
  SCHEMA_ATTEMPTED=1
  "$PHP_BIN" -d display_errors=1 -r '
    $root=$argv[1];
    require $root."/server/team-points-green/src/bootstrap.php";
    $repo=\P2K\Green\GreenRepository::open();
    $repo->initializeSchemas();
    echo "Green additive schema ready\n";
  ' "$ROOT" || fail "Additive Green v2.10.5.2 schema upgrade failed"
fi

# Final source/runtime invariants. These checks are deliberately local and do not
# require GAB to have been started yet or Green public reads to be selected.
[[ "$(trim "$ROOT/VERSION")" == "$TARGET" ]] || fail "VERSION did not advance to $TARGET"
[[ "$(trim "$ROOT/MIGRATION_VERSION")" == "$TARGET" ]] || fail "MIGRATION_VERSION did not advance to $TARGET"
grep -q 'version: "2.10.5.2"' "$ROOT/assets/js/site-config.js" || fail "site-config version marker is stale"
grep -q "public const VERSION = '2.10.5.2';" "$ROOT/server/team-points-green/src/GreenConfig.php" || fail "GreenConfig release marker is stale"
grep -q "'release'=>'2.10.5.2'" "$ROOT/server/team-points-green/public/api.php" || fail "Green API release marker is stale"
grep -q 'class GreenAnalyticsBootstrap' "$ROOT/server/team-points-green/src/GreenAnalyticsBootstrap.php" || fail "GAB source is missing"
grep -q 'p2k_g_gffl_match_debt' "$ROOT/server/team-points-green/src/GreenRepository.php" || fail "GFFL schema/source is missing"
grep -q 'class PublicReadDatabase' "$ROOT/server/team-points/src/PublicReadDatabase.php" || fail "Green public read router is missing"
grep -q 'data-task-tab="green"' "$ROOT/TaskControl.html" || fail "Green Scheduled Task Control tab is missing"

python3 - "$ROOT" <<'PY'
from pathlib import Path
import json,re,sys
root=Path(sys.argv[1]); target='2.10.5.2'
m=json.loads((root/'site-manifest.json').read_text())
assert m.get('version')==target and m.get('releaseVersion')==target and m.get('migrationVersion')==target
assert m.get('publicDataSource')=='blue_default_green_switchable'
r=m.get('v21052Release') or {}
assert r.get('GAB') is True and r.get('GFFL') is True and r.get('greenPublicCutover')=='validation_and_gab_gated'
for p in sorted(list(root.glob('*.html'))+list(root.glob('*.htm'))):
    versions=set(re.findall(r'\?v=(2\.10\.[0-9.]+)',p.read_text(encoding='utf-8',errors='ignore')))
    assert not versions or versions=={target}, (p.name, sorted(versions))
PY

trap - ERR INT TERM
ROLLBACK_ACTIVE=0
printf '%s\n' "$TARGET" > "$BACKUP/installed-version.txt"
echo "SUCCESS: Promote to King $TARGET installed."
echo "Backup: $BACKUP"
echo "Green Core was not reseeded and no destructive database reset was performed."
echo "Public reads remain on the currently stored migration target; Green cutover remains validation + GAB parity gated."
echo "Next: Administration -> Scheduled Task Control -> Green Team Points -> Start / resume GAB."
