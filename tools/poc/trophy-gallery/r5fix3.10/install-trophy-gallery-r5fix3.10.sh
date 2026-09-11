#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
MODE="${2:-install}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/p2k-trophy-r5310.XXXXXX")"
BACKUP="$ROOT/.trophy-r5fix3.10-backup-$STAMP"
UI="$ROOT/ui-v2.html"; STANDALONE="$ROOT/trophies/index.html"
JS_DEST="$ROOT/assets/js/admin/trophy-gallery-r5fix3.10.js"
R538_JS="$ROOT/assets/js/admin/trophy-gallery-r5fix3.8.js"; R538_CSS="$ROOT/assets/trophy-gallery/trophy-gallery-r5fix3.8.css"
JS_KEY="r5310-924e1cb5d9a8"; JS_SHA="924e1cb5d9a8db8d05eece254fb4726bf77be57f96169d6ddb090cc44c63d306"
R538_JS_SHA="d52193a717125f33359b12ac62dbdb77c35e71bb5c8632eae17e1ad3fff1ef9c"; R538_CSS_SHA="fdcea54d62ba4c16b35d8c8bc1b109f6491200a3ede65f32373aac07712a9e0e"
cleanup(){ rm -rf "$STAGE"; }; trap cleanup EXIT
fail(){ echo "ERROR: $*" >&2; exit 1; }; log(){ printf '\n[%s] %s\n' "$1" "$2"; }; need(){ [[ -f "$1" ]] || fail "required file missing: $1"; }
sha256_file(){ python3 - "$1" <<'PYI'
from pathlib import Path
import hashlib,sys
print(hashlib.sha256(Path(sys.argv[1]).read_bytes()).hexdigest())
PYI
}
verify_manifest(){ python3 - "$HERE/MANIFEST.sha256" "$HERE" <<'PYI'
from pathlib import Path
import hashlib,sys
m=Path(sys.argv[1]); root=Path(sys.argv[2])
for raw in m.read_text(encoding='utf-8').splitlines():
    if not raw.strip(): continue
    sha,name=raw.split(None,1); p=root/name.strip()
    if not p.is_file(): raise SystemExit('package payload missing: '+name.strip())
    if hashlib.sha256(p.read_bytes()).hexdigest()!=sha: raise SystemExit('package checksum mismatch: '+name.strip())
print('Package manifest OK.')
PYI
}
verify_loader(){ python3 - "$1" "$2" "$3" <<'PYI'
from pathlib import Path
import sys
p=Path(sys.argv[1]); key=sys.argv[2]; prefix=sys.argv[3]; t=p.read_text(encoding='utf-8')
b='<!-- P2K_TROPHY_R5FIX310_BEGIN -->'; e='<!-- P2K_TROPHY_R5FIX310_END -->'
if t.count(b)!=1 or t.count(e)!=1: raise SystemExit(str(p)+': r5fix3.10 loader marker count != 1')
needle=f'{prefix}assets/js/admin/trophy-gallery-r5fix3.10.js?v={key}'
r538=f'{prefix}assets/js/admin/trophy-gallery-r5fix3.8.js?v=r538-d52193a71712'
if t.count(needle)!=1: raise SystemExit(str(p)+': r5fix3.10 immutable loader missing/duplicated')
if r538 not in t or t.index(r538)>t.index(needle): raise SystemExit(str(p)+': approved r5fix3.8 loader ordering invalid')
print(str(p)+': loader OK')
PYI
}
verify_live(){
  for f in "$UI" "$STANDALONE" "$R538_JS" "$R538_CSS" "$JS_DEST"; do need "$f"; done
  [[ "$(cat "$ROOT/VERSION")" == "2.11.5" ]] || fail 'P2K VERSION must be 2.11.5'
  [[ "$(sha256_file "$R538_JS")" == "$R538_JS_SHA" ]] || fail 'approved r5fix3.8 JS checksum mismatch'
  [[ "$(sha256_file "$R538_CSS")" == "$R538_CSS_SHA" ]] || fail 'approved r5fix3.8 CSS checksum mismatch'
  [[ "$(sha256_file "$JS_DEST")" == "$JS_SHA" ]] || fail 'installed r5fix3.10 JS checksum mismatch'
  verify_loader "$UI" "$JS_KEY" ""
  verify_loader "$STANDALONE" "$JS_KEY" "../"
  echo 'Trophy Gallery r5fix3.10 verification PASSED.'; echo "JS key: $JS_KEY"
}
if [[ "$MODE" == verify ]]; then log VERIFY 'Verifying r5fix3.10'; verify_live; exit 0; fi
[[ "$MODE" == install ]] || fail 'usage: installer [P2K_ROOT] [install|verify]'
log PREFLIGHT 'Checking approved r5fix3.9 / 2.11.5 Trophy baseline'
for f in "$ROOT/VERSION" "$UI" "$STANDALONE" "$R538_JS" "$R538_CSS" "$HERE/MANIFEST.sha256" "$HERE/patch-loaders.py" "$HERE/trophy-gallery-r5fix3.10.js"; do need "$f"; done
command -v python3 >/dev/null 2>&1 || fail 'python3 is required'
[[ "$(cat "$ROOT/VERSION")" == "2.11.5" ]] || fail 'r5fix3.10 requires P2K VERSION 2.11.5'
[[ "$(sha256_file "$R538_JS")" == "$R538_JS_SHA" ]] || fail 'approved r5fix3.8 JS baseline not found'
[[ "$(sha256_file "$R538_CSS")" == "$R538_CSS_SHA" ]] || fail 'approved r5fix3.8 CSS baseline not found'
verify_manifest
log STAGE 'Preparing loader changes without touching live files'
cp -p "$UI" "$STAGE/ui-v2.html"; cp -p "$STANDALONE" "$STAGE/trophies-index.html"
python3 "$HERE/patch-loaders.py" --file "$STAGE/ui-v2.html" --js-key "$JS_KEY"
python3 "$HERE/patch-loaders.py" --file "$STAGE/trophies-index.html" --js-key "$JS_KEY" --standalone
verify_loader "$STAGE/ui-v2.html" "$JS_KEY" ""; verify_loader "$STAGE/trophies-index.html" "$JS_KEY" "../"
log BACKUP 'Creating byte-for-byte backup'
mkdir -p "$BACKUP"; cp -p "$UI" "$BACKUP/ui-v2.html"; cp -p "$STANDALONE" "$BACKUP/trophies-index.html"
[[ -f "$JS_DEST" ]] && cp -p "$JS_DEST" "$BACKUP/old-js" || : > "$BACKUP/js-was-absent"
rollback(){ rc=$?; trap - ERR; echo; echo "[ROLLBACK] Restoring pre-install files from $BACKUP"; cp -p "$BACKUP/ui-v2.html" "$UI" || true; cp -p "$BACKUP/trophies-index.html" "$STANDALONE" || true; if [[ -f "$BACKUP/js-was-absent" ]]; then rm -f "$JS_DEST"; else cp -p "$BACKUP/old-js" "$JS_DEST" || true; fi; exit "$rc"; }
trap 'echo; echo "[FAIL] line $LINENO"; echo "[FAIL] command: $BASH_COMMAND"; rollback' ERR
log COMMIT 'Installing r5fix3.10 companion atomically'
mkdir -p "$(dirname "$JS_DEST")"
cp "$HERE/trophy-gallery-r5fix3.10.js" "$JS_DEST.r5310.new"; cp -p "$STAGE/ui-v2.html" "$UI.r5310.new"; cp -p "$STAGE/trophies-index.html" "$STANDALONE.r5310.new"
chmod 0644 "$JS_DEST.r5310.new"
mv -f "$JS_DEST.r5310.new" "$JS_DEST"; mv -f "$UI.r5310.new" "$UI"; mv -f "$STANDALONE.r5310.new" "$STANDALONE"
log FINAL-VERIFY 'Verifying exact installed bytes and loader order'; verify_live; trap - ERR
echo; echo 'Trophy Gallery r5fix3.10 installed successfully'; echo "Backup: $BACKUP"; echo 'No PHP, API/store, catalogue data, OAuth/session, CRON or approved r5fix3.8 files were modified.'
