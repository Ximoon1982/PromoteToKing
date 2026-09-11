#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
MODE="${2:-install}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/p2k-trophy-r538.XXXXXX")"
BACKUP="$ROOT/.trophy-r5fix3.8-backup-$STAMP"
UI="$ROOT/ui-v2.html"; STANDALONE="$ROOT/trophies/index.html"; CORE_JS="$ROOT/assets/js/admin/trophy-gallery-poc.js"; META_API="$ROOT/server/trophy-gallery/public/editor-meta.php"
JS_DEST="$ROOT/assets/js/admin/trophy-gallery-r5fix3.8.js"; CSS_DEST="$ROOT/assets/trophy-gallery/trophy-gallery-r5fix3.8.css"
JS_KEY="r538-d52193a71712"; CSS_KEY="r538-fdcea54d62ba"; JS_SHA="d52193a717125f33359b12ac62dbdb77c35e71bb5c8632eae17e1ad3fff1ef9c"; CSS_SHA="fdcea54d62ba4c16b35d8c8bc1b109f6491200a3ede65f32373aac07712a9e0e"
cleanup(){ rm -rf "$STAGE"; }; trap cleanup EXIT
fail(){ echo "ERROR: $*" >&2; exit 1; }; log(){ printf '
[%s] %s
' "$1" "$2"; }; need(){ [[ -f "$1" ]] || fail "required file missing: $1"; }
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
p=Path(sys.argv[1]); js=sys.argv[2]; css=sys.argv[3]; t=p.read_text(encoding='utf-8')
if t.count('<!-- P2K_TROPHY_R5FIX38_BEGIN -->')!=1: raise SystemExit(str(p)+': loader begin marker count != 1')
if t.count('<!-- P2K_TROPHY_R5FIX38_END -->')!=1: raise SystemExit(str(p)+': loader end marker count != 1')
if js not in t: raise SystemExit(str(p)+': JS immutable key missing')
if css not in t: raise SystemExit(str(p)+': CSS immutable key missing')
print(str(p)+': loader OK')
PYI
}
verify_live(){
 for f in "$UI" "$STANDALONE" "$CORE_JS" "$META_API" "$JS_DEST" "$CSS_DEST"; do need "$f"; done
 [[ "$(sha256_file "$JS_DEST")" == "$JS_SHA" ]] || fail 'installed JS checksum mismatch'
 [[ "$(sha256_file "$CSS_DEST")" == "$CSS_SHA" ]] || fail 'installed CSS checksum mismatch'
 verify_loader "$UI" "trophy-gallery-r5fix3.8.js?v=$JS_KEY" "trophy-gallery-r5fix3.8.css?v=$CSS_KEY"
 verify_loader "$STANDALONE" "trophy-gallery-r5fix3.8.js?v=$JS_KEY" "trophy-gallery-r5fix3.8.css?v=$CSS_KEY"
 echo 'Trophy Gallery r5fix3.8 verification PASSED.'
 echo "JS key: $JS_KEY"; echo "CSS key: $CSS_KEY"
}
if [[ "$MODE" == verify ]]; then log VERIFY 'Verifying r5fix3.8 without invoking shell PHP'; verify_live; exit 0; fi
[[ "$MODE" == install ]] || fail 'usage: installer [P2K_ROOT] [install|verify]'
log PREFLIGHT 'Checking current Trophy infrastructure and package'
for f in "$UI" "$STANDALONE" "$CORE_JS" "$META_API" "$HERE/MANIFEST.sha256" "$HERE/patch-loaders.py" "$HERE/trophy-gallery-r5fix3.8.js" "$HERE/trophy-gallery-r5fix3.8.css"; do need "$f"; done
command -v python3 >/dev/null 2>&1 || fail 'python3 is required'
log PACKAGE 'Verifying immutable .8 payload'; verify_manifest
log RUNTIME 'Shell PHP is deliberately not used'; echo 'This containment increment does not modify PHP or Trophy data.'
log STAGE 'Preparing loader-only HTML changes in isolation'
cp -p "$UI" "$STAGE/ui-v2.html"; cp -p "$STANDALONE" "$STAGE/trophies-index.html"
python3 "$HERE/patch-loaders.py" --file "$STAGE/ui-v2.html" --js-key "$JS_KEY" --css-key "$CSS_KEY"
python3 "$HERE/patch-loaders.py" --file "$STAGE/trophies-index.html" --js-key "$JS_KEY" --css-key "$CSS_KEY" --standalone
log VERIFY-STAGE 'Proving managed loader scope before touching live files'
verify_loader "$STAGE/ui-v2.html" "trophy-gallery-r5fix3.8.js?v=$JS_KEY" "trophy-gallery-r5fix3.8.css?v=$CSS_KEY"
verify_loader "$STAGE/trophies-index.html" "trophy-gallery-r5fix3.8.js?v=$JS_KEY" "trophy-gallery-r5fix3.8.css?v=$CSS_KEY"
log BACKUP 'Creating byte-for-byte backup'
mkdir -p "$BACKUP"; cp -p "$UI" "$BACKUP/ui-v2.html"; cp -p "$STANDALONE" "$BACKUP/trophies-index.html"
[[ -f "$JS_DEST" ]] && cp -p "$JS_DEST" "$BACKUP/old-js" || : > "$BACKUP/js-was-absent"
[[ -f "$CSS_DEST" ]] && cp -p "$CSS_DEST" "$BACKUP/old-css" || : > "$BACKUP/css-was-absent"
rollback(){ rc=$?; trap - ERR; echo; echo "[ROLLBACK] Restoring pre-install files from $BACKUP"; cp -p "$BACKUP/ui-v2.html" "$UI" || true; cp -p "$BACKUP/trophies-index.html" "$STANDALONE" || true; if [[ -f "$BACKUP/js-was-absent" ]]; then rm -f "$JS_DEST"; else cp -p "$BACKUP/old-js" "$JS_DEST" || true; fi; if [[ -f "$BACKUP/css-was-absent" ]]; then rm -f "$CSS_DEST"; else cp -p "$BACKUP/old-css" "$CSS_DEST" || true; fi; exit "$rc"; }
trap 'echo; echo "[FAIL] line $LINENO"; echo "[FAIL] command: $BASH_COMMAND"; rollback' ERR
log COMMIT 'All checks passed; installing .8 companion atomically'
mkdir -p "$(dirname "$JS_DEST")" "$(dirname "$CSS_DEST")"
cp "$HERE/trophy-gallery-r5fix3.8.js" "$JS_DEST.r538.new"; cp "$HERE/trophy-gallery-r5fix3.8.css" "$CSS_DEST.r538.new"; cp -p "$STAGE/ui-v2.html" "$UI.r538.new"; cp -p "$STAGE/trophies-index.html" "$STANDALONE.r538.new"
chmod 0644 "$JS_DEST.r538.new" "$CSS_DEST.r538.new"
mv -f "$JS_DEST.r538.new" "$JS_DEST"; mv -f "$CSS_DEST.r538.new" "$CSS_DEST"; mv -f "$UI.r538.new" "$UI"; mv -f "$STANDALONE.r538.new" "$STANDALONE"
log FINAL-VERIFY 'Verifying exact installed bytes and loader markers'; verify_live; trap - ERR
echo; echo 'Trophy Gallery r5fix3.8 installed successfully'; echo "Backup: $BACKUP"; echo 'No PHP, API/store, catalogue data, OAuth/session, CRON or tool registry was modified.'
