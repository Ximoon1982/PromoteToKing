#!/usr/bin/env bash
# Promote to King v2.10.9.2 incremental MCA date-recovery corrective.
# Production boundary is VERSION-only; no repository/source-manifest/test assumptions.
set -Eeuo pipefail

TARGET_VERSION="2.10.9.2"
PREDECESSOR_VERSION="2.10.9.1"
TARGET="${1:-${P2K_SITE_ROOT:-$PWD}}"
MARKER="__P2K_V21092_PAYLOAD_BELOW__"
SELF="$0"
MANIFEST=".p2k-v21092-payload-manifest.json"
EXPECTED_FILES="16"

fail(){ echo "ERROR: $*" >&2; exit 2; }
command -v python3 >/dev/null 2>&1 || fail "python3 is required."
command -v tar >/dev/null 2>&1 || fail "tar is required."

choose_php(){
  local candidate resolved
  for candidate in "${P2K_PHP_CLI:-}" \
    /usr/bin/php8.5-cli /usr/bin/php8.5 \
    /usr/bin/php8.4-cli /usr/bin/php8.4 \
    /usr/bin/php8.3-cli /usr/bin/php8.3 \
    /usr/bin/php8.2-cli /usr/bin/php8.2 \
    /usr/bin/php8.1-cli /usr/bin/php8.1 \
    php8.5-cli php8.5 php8.4-cli php8.4 php8.3-cli php8.3 php8.2-cli php8.2 php8.1-cli php8.1 php; do
    [[ -n "$candidate" ]] || continue
    resolved="$candidate"; [[ "$candidate" == */* ]] || resolved="$(command -v "$candidate" 2>/dev/null || true)"
    [[ -n "$resolved" && -x "$resolved" ]] || continue
    if "$resolved" -r 'exit(PHP_SAPI === "cli" && PHP_VERSION_ID >= 80100 ? 0 : 1);' >/dev/null 2>&1; then printf '%s\n' "$resolved"; return 0; fi
  done
  return 1
}
PHP_BIN="$(choose_php || true)"
[[ -n "$PHP_BIN" ]] || fail "Compatible PHP CLI not found (PHP 8.1+ required). On IONOS prefer /usr/bin/php8.5-cli."
echo "Using PHP CLI: $PHP_BIN ($($PHP_BIN -r 'echo PHP_VERSION;' 2>/dev/null || true))"

[[ -d "$TARGET" ]] || fail "Target directory does not exist: $TARGET"
TARGET="$(cd "$TARGET" && pwd -P)"
[[ -f "$TARGET/VERSION" ]] || fail "VERSION file is missing from target."
CURRENT="$(tr -d '\r\n' < "$TARGET/VERSION")"
case "$CURRENT" in "$PREDECESSOR_VERSION"|"$TARGET_VERSION") ;; *) fail "Unsupported target VERSION '$CURRENT'. Expected $PREDECESSOR_VERSION (or $TARGET_VERSION for replay)." ;; esac

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/p2k-v21092.XXXXXX")"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.p2k-backups/v2.10.9.2-$STAMP-$$"
NEW_LIST="$BACKUP/new-files.txt"
ROLLBACK_ARMED=0
cleanup(){ rm -rf "$STAGE"; }
restore_files(){
  python3 - "$TARGET" "$BACKUP" "$NEW_LIST" <<'PYRESTORE'
from pathlib import Path
import shutil,sys
root=Path(sys.argv[1]); backup=Path(sys.argv[2]); new_list=Path(sys.argv[3])
if new_list.exists():
    for rel in reversed(new_list.read_text(encoding='utf-8').splitlines()):
        if not rel: continue
        p=root/rel
        try:
            if p.is_symlink() or p.is_file(): p.unlink()
            elif p.is_dir(): shutil.rmtree(p)
        except FileNotFoundError: pass
saved=backup/'files'
if saved.exists():
    for p in sorted(saved.rglob('*')):
        if not p.is_file() and not p.is_symlink(): continue
        rel=p.relative_to(saved); dst=root/rel; dst.parent.mkdir(parents=True,exist_ok=True)
        if p.is_symlink():
            try: dst.unlink()
            except FileNotFoundError: pass
            dst.symlink_to(p.readlink())
        else: shutil.copy2(p,dst)
PYRESTORE
}
on_error(){
  rc=$?
  if [[ "$ROLLBACK_ARMED" == 1 ]]; then echo "Installation failed; restoring touched files from $BACKUP ..." >&2; restore_files || true; fi
  cleanup; exit "$rc"
}
trap on_error ERR INT TERM
trap cleanup EXIT

PAYLOAD_LINE="$(awk -v m="$MARKER" '$0==m {print NR+1; exit}' "$SELF")"
[[ -n "$PAYLOAD_LINE" ]] || fail "Embedded payload marker not found."

echo "[1/5] Extracting and verifying v$TARGET_VERSION payload..."
tail -n +"$PAYLOAD_LINE" "$SELF" | tar -xzf - -C "$STAGE"
[[ -f "$STAGE/$MANIFEST" ]] || fail "Payload manifest missing."
python3 - "$STAGE" "$STAGE/$MANIFEST" "$EXPECTED_FILES" <<'PYVERIFY'
from pathlib import Path
import hashlib,json,sys
root=Path(sys.argv[1]); m=json.loads(Path(sys.argv[2]).read_text(encoding='utf-8')); expected=int(sys.argv[3])
if m.get('version')!='2.10.9.2' or m.get('predecessor')!='2.10.9.1': raise SystemExit('manifest version boundary mismatch')
if len(m.get('files',[]))!=expected: raise SystemExit(f'unexpected payload file count: {len(m.get("files",[]))} != {expected}')
for row in m['files']:
    rel=Path(row['path'])
    if rel.is_absolute() or '..' in rel.parts: raise SystemExit(f'unsafe payload path: {rel}')
    p=root/rel
    if not p.is_file(): raise SystemExit(f'missing payload file: {rel}')
    data=p.read_bytes()
    if len(data)!=row['size'] or hashlib.sha256(data).hexdigest()!=row['sha256']: raise SystemExit(f'payload hash mismatch: {rel}')
print(f"verified {len(m['files'])} complete payload files")
PYVERIFY

echo "[2/5] Verifying production version boundary and staged syntax..."
for f in server/team-points/src/McaIndexParser.php server/team-points/src/McaSourceCatalogue.php server/team-points/src/LiveRanksService.php server/team-points/src/McaResultsCronService.php server/team-points/bin/mca-results-sync.php; do
  "$PHP_BIN" -d display_errors=1 -l "$STAGE/$f" >/dev/null; echo "syntax OK: $f"
done
python3 -m json.tool "$STAGE/site-manifest.json" >/dev/null
python3 -m json.tool "$STAGE/RELEASE_v2.10.9.2.json" >/dev/null
echo "production VERSION boundary accepted: $CURRENT"

echo "[3/5] Backing up all touched production files..."
mkdir -p "$BACKUP/files"; : > "$NEW_LIST"
python3 - "$TARGET" "$STAGE/$MANIFEST" "$BACKUP" "$NEW_LIST" <<'PYBACKUP'
from pathlib import Path
import json,shutil,sys
root=Path(sys.argv[1]); m=json.loads(Path(sys.argv[2]).read_text()); backup=Path(sys.argv[3]); new=Path(sys.argv[4])
new_rows=[]; existing=0
for row in m['files']:
    rel=Path(row['path']); dst=root/rel
    if dst.exists() or dst.is_symlink():
        if not dst.is_file() and not dst.is_symlink(): raise SystemExit(f'destination is not a file: {rel}')
        b=backup/'files'/rel; b.parent.mkdir(parents=True,exist_ok=True)
        if dst.is_symlink(): b.symlink_to(dst.readlink())
        else: shutil.copy2(dst,b)
        existing += 1
    else: new_rows.append(str(rel))
new.write_text('\n'.join(new_rows)+('\n' if new_rows else ''),encoding='utf-8')
print(f"backup: {existing} existing, {len(new_rows)} new")
PYBACKUP
ROLLBACK_ARMED=1

echo "[4/5] Installing complete v$TARGET_VERSION corrective files..."
python3 - "$TARGET" "$STAGE" "$STAGE/$MANIFEST" <<'PYINSTALL'
from pathlib import Path
import json,os,shutil,sys
root=Path(sys.argv[1]); stage=Path(sys.argv[2]); m=json.loads(Path(sys.argv[3]).read_text())
for row in m['files']:
    rel=Path(row['path']); src=stage/rel; dst=root/rel; dst.parent.mkdir(parents=True,exist_ok=True)
    tmp=dst.with_name(dst.name+f'.p2k-v21092-{os.getpid()}.tmp'); shutil.copy2(src,tmp); os.replace(tmp,dst)
PYINSTALL
if [[ "${P2K_INSTALL_TEST_FAIL_AFTER_COPY:-0}" == 1 ]]; then echo "TEST: forcing failure after file installation." >&2; false; fi

echo "[5/5] Verifying installed release..."
python3 - "$TARGET" "$STAGE/$MANIFEST" <<'PYPOST'
from pathlib import Path
import hashlib,json,sys
root=Path(sys.argv[1]); m=json.loads(Path(sys.argv[2]).read_text())
for row in m['files']:
    p=root/row['path']
    if not p.is_file(): raise SystemExit(f'installed file missing: {row["path"]}')
    data=p.read_bytes()
    if len(data)!=row['size'] or hashlib.sha256(data).hexdigest()!=row['sha256']: raise SystemExit(f'installed hash mismatch: {row["path"]}')
print(f"verified {len(m['files'])} installed files")
PYPOST
[[ "$(tr -d '\r\n' < "$TARGET/VERSION")" == "$TARGET_VERSION" ]] || fail "VERSION did not advance to $TARGET_VERSION"
[[ "$(tr -d '\r\n' < "$TARGET/MIGRATION_VERSION")" == "$TARGET_VERSION" ]] || fail "MIGRATION_VERSION did not advance to $TARGET_VERSION"
for f in server/team-points/src/McaIndexParser.php server/team-points/src/McaSourceCatalogue.php server/team-points/src/LiveRanksService.php server/team-points/src/McaResultsCronService.php server/team-points/bin/mca-results-sync.php; do "$PHP_BIN" -d display_errors=1 -l "$TARGET/$f" >/dev/null; done
ROLLBACK_ARMED=0

echo "Promote to King v$TARGET_VERSION installed successfully."
echo "Backup retained at: $BACKUP"
echo "No database schema or CRON definition changes were made. Existing CSV bytes were preserved."
echo "MCA date repair uses the canonical arena/index parsers; browser-suffixed duplicate sources are retained for audit but excluded from derived statistics."
exit 0

__P2K_V21092_PAYLOAD_BELOW__
