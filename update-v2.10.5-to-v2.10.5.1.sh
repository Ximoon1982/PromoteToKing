#!/usr/bin/env bash
set -Eeuo pipefail

TARGET="2.10.5.1"
PKG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PAYLOAD="$PKG_DIR/payload"
SUMS="$PKG_DIR/SHA256SUMS.txt"
ROOT="${1:-$PWD}"
ROOT="$(cd "$ROOT" && pwd -P)"

BACKUP=""
ROLLBACK_ACTIVE=0

rollback(){
  local rc="${1:-1}"
  trap - ERR INT TERM
  set +e
  if [[ "$ROLLBACK_ACTIVE" -eq 1 && -n "$BACKUP" && -d "$BACKUP" ]]; then
    echo "Corrective failed; rolling source files back..." >&2
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
    echo "Rollback complete: $BACKUP" >&2
  fi
  exit "$rc"
}

fail(){
  echo "ERROR: $*" >&2
  if [[ "$ROLLBACK_ACTIVE" -eq 1 ]]; then rollback 2; fi
  exit 2
}
trim(){ tr -d '\r\n[:space:]' < "$1"; }

[[ -f "$ROOT/VERSION" && -f "$ROOT/MIGRATION_VERSION" ]] || fail "VERSION markers missing from $ROOT"
CURRENT="$(trim "$ROOT/VERSION")"
case "$CURRENT" in 2.10.5|2.10.5.1) ;; *) fail "Expected 2.10.5 or 2.10.5.1; found $CURRENT";; esac
[[ -d "$PAYLOAD" && -f "$SUMS" ]] || fail "Corrective payload/checksum manifest is incomplete"
command -v sha256sum >/dev/null || fail "sha256sum required"
command -v python3 >/dev/null || fail "python3 required"

(
  cd "$PKG_DIR"
  sha256sum -c SHA256SUMS.txt >/dev/null
) || fail "Corrective package checksum verification failed"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="$ROOT/_backup/${STAMP}_v2.10.5-to-v2.10.5.1-runtime-version"
mkdir -p "$BACKUP/files"
: > "$BACKUP/existed.txt"
: > "$BACKUP/new-files.txt"

ROLLBACK_ACTIVE=1
trap 'rc=$?; rollback "$rc"' ERR INT TERM

mapfile -t FILES < <(cd "$PAYLOAD" && find . -type f -print | sed 's#^./##' | LC_ALL=C sort)
for rel in "${FILES[@]}"; do
  [[ "$rel" != server/team-points/config/config.local.php ]] || fail "Protected config unexpectedly present in payload"
  [[ "$rel" != server/team-points/config/oauth.local.php ]] || fail "Protected OAuth config unexpectedly present in payload"
  [[ "$rel" != server/team-points-green/config/green.local.php ]] || fail "Protected Green config unexpectedly present in payload"
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

# Post-install runtime/version/cache validation.
[[ "$(trim "$ROOT/VERSION")" == "$TARGET" ]] || fail "VERSION did not advance to $TARGET"
[[ "$(trim "$ROOT/MIGRATION_VERSION")" == "$TARGET" ]] || fail "MIGRATION_VERSION did not advance to $TARGET"
grep -q 'version: "2.10.5.1"' "$ROOT/assets/js/site-config.js" || fail "site-config version marker is stale"
grep -q "public const VERSION = '2.10.5.1';" "$ROOT/server/team-points-green/src/GreenConfig.php" || fail "GreenConfig release marker is stale"
grep -q "'release'=>'2.10.5.1'" "$ROOT/server/team-points-green/public/api.php" || fail "Green API release marker is stale"
python3 - "$ROOT" <<'PY'
from pathlib import Path
import json,re,sys
root=Path(sys.argv[1]); target='2.10.5.1'
m=json.loads((root/'site-manifest.json').read_text())
assert m.get('version')==target and m.get('releaseVersion')==target and m.get('migrationVersion')==target
for p in sorted(list(root.glob('*.html'))+list(root.glob('*.htm'))):
    versions=set(re.findall(r'\?v=(2\.10\.[0-9.]+)',p.read_text(encoding='utf-8')))
    assert not versions or versions=={target}, (p.name, sorted(versions))
cfg=(root/'assets/js/site-config.js').read_text(encoding='utf-8')
versions=set(re.findall(r'\?v=(2\.10\.[0-9.]+)',cfg))
assert not versions or versions=={target}, sorted(versions)
PY

trap - ERR INT TERM
ROLLBACK_ACTIVE=0
printf '%s\n' "$TARGET" > "$BACKUP/installed-version.txt"
echo "SUCCESS: Promote to King $TARGET runtime-version corrective installed."
echo "Backup: $BACKUP"
echo "No database, reseed, CRON, or worker configuration was changed."
