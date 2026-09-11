#!/usr/bin/env bash
set -euo pipefail
ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
MODE="${2:-install}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
JS="$ROOT/assets/js/admin/trophy-gallery-poc.js"; REG="$ROOT/assets/js/admin/tool-registry.js"; MAIN="$ROOT/index.html"; STANDALONE="$ROOT/trophies/index.html"; EDITOR="$ROOT/assets/trophy-gallery/engraving/editor.html"
CSS_DIR="$ROOT/assets/trophy-gallery"; CSS_TARGET="$CSS_DIR/r5fix2.2.css"; STAMP="$(date +%Y%m%d-%H%M%S)"; BACKUP="$ROOT/.trophy-r5fix2.2-backup-$STAMP"
fail(){ echo "ERROR: $*" >&2; exit 1; }
for f in "$JS" "$REG" "$MAIN" "$STANDALONE" "$EDITOR" "$HERE/r5fix2.2.css" "$HERE/r5fix2.2-enhancement.js" "$HERE/consolidate-trophy-runtime.py"; do [[ -f "$f" ]] || fail "Required file missing: $f"; done
command -v python3 >/dev/null 2>&1 || fail "python3 required"; command -v sha256sum >/dev/null 2>&1 || fail "sha256sum required"

verify(){
 grep -q '__P2K_TROPHY_R5FIX2_2' "$JS" || fail "r5fix2.2 marker missing"
 ! grep -q '__P2K_TROPHY_R5FIX1' "$JS" || fail "obsolete r5fix1 enhancer remains"
 ! grep -q '__P2K_TROPHY_R5FIX2_1_PATCHED' "$JS" || fail "obsolete r5fix2.1 observer remains"
 grep -q 'class="p2k-trophy-meta"' "$JS" || fail "rich modal metadata missing"
 grep -q 'data-engraver title="Trophy engraving editor"' "$JS" || fail "engraver selector fix missing"
 [[ -s "$CSS_TARGET" ]] || fail "r5fix2.2 stylesheet missing"
 JSKEY="poc-$(sha256sum "$JS"|awk '{print substr($1,1,12)}')-20260910-r5fix2.2"
 grep -Fq "trophy-gallery-poc.js?v=$JSKEY" "$REG" || fail "registry -> Trophy cache key mismatch"
 grep -Fq "trophy-gallery-poc.js?v=$JSKEY" "$STANDALONE" || fail "standalone -> Trophy cache key mismatch"
 REGKEY="trophyreg-$(sha256sum "$REG"|awk '{print substr($1,1,12)}')-20260910-r5fix2.2"
 grep -Fq "tool-registry.js?v=$REGKEY" "$MAIN" || fail "main index -> registry cache key mismatch"
 grep -q 'p2k-trophy-editor-open' "$EDITOR" || fail "engraver default-team hook missing"
 echo "Trophy Gallery r5fix2.2 verification passed."
 echo "Trophy runtime key: $JSKEY"
 echo "Tool registry key: $REGKEY"
}
if [[ "$MODE" == verify ]]; then verify; exit 0; fi
[[ "$MODE" == install ]] || fail "Usage: $0 [P2K_ROOT] [install|verify]"

mkdir -p "$BACKUP" "$CSS_DIR"; cp -a "$JS" "$BACKUP/trophy-gallery-poc.js"; cp -a "$REG" "$BACKUP/tool-registry.js"; cp -a "$MAIN" "$BACKUP/index.html"; cp -a "$STANDALONE" "$BACKUP/trophies-index.html"; cp -a "$EDITOR" "$BACKUP/editor.html"
if [[ -f "$CSS_TARGET" ]]; then cp -a "$CSS_TARGET" "$BACKUP/r5fix2.2.css"; else : > "$BACKUP/css-was-absent"; fi
rollback(){ rc=$?;trap - ERR INT TERM;cp -a "$BACKUP/trophy-gallery-poc.js" "$JS" 2>/dev/null||true;cp -a "$BACKUP/tool-registry.js" "$REG" 2>/dev/null||true;cp -a "$BACKUP/index.html" "$MAIN" 2>/dev/null||true;cp -a "$BACKUP/trophies-index.html" "$STANDALONE" 2>/dev/null||true;cp -a "$BACKUP/editor.html" "$EDITOR" 2>/dev/null||true;if [[ -f "$BACKUP/css-was-absent" ]]; then rm -f "$CSS_TARGET"; else cp -a "$BACKUP/r5fix2.2.css" "$CSS_TARGET" 2>/dev/null||true;fi;echo "Update failed; restored from $BACKUP" >&2;exit "$rc"; }
trap rollback ERR INT TERM

python3 "$HERE/consolidate-trophy-runtime.py" "$JS"

export P2K_EDITOR="$EDITOR"
python3 <<'PY'
from pathlib import Path
import os,re
p=Path(os.environ["P2K_EDITOR"]);s=p.read_text(encoding="utf-8")
s=re.sub(r'<title>.*?</title>','<title>Trophy engraving editor</title>',s,count=1,flags=re.S)
s=re.sub(r'\s*<h1>P2K 1WL Engraving POC v1\.9</h1>\s*<p class="sub">.*?</p>\s*','\n',s,count=1,flags=re.S)
if "P2K_R5FIX2_DEFAULT_TEAM" not in s:
 hook="""
/* P2K_R5FIX2_DEFAULT_TEAM */
function p2kDefaultTeam(){
 const team='Promote to King';
 if(els.middleText)els.middleText.value=team;
 if(els.cupPlaque)els.cupPlaque.value=team;
 if(els.crystalTop)els.crystalTop.value=team;
}
window.addEventListener('message',event=>{if(event.origin!==location.origin||event.data?.type!=='p2k-trophy-editor-open')return;p2kDefaultTeam();render();});
p2kDefaultTeam();
"""
 needle="loadImages().catch"
 if needle not in s: raise SystemExit("Engraver initialization not found")
 s=s.replace(needle,hook+"\n"+needle,1)
p.write_text(s,encoding="utf-8")
PY

ET="engrave-$(sha256sum "$EDITOR"|awk '{print substr($1,1,12)}')"
export P2K_JS="$JS" P2K_ET="$ET" P2K_ENH="$HERE/r5fix2.2-enhancement.js"
python3 <<'PY'
from pathlib import Path
import os,re
p=Path(os.environ["P2K_JS"]);s=p.read_text(encoding="utf-8");et=os.environ["P2K_ET"]
s,n=re.subn(r'assets/trophy-gallery/engraving/editor\.html(?:\?v=[^"]*)?',f'assets/trophy-gallery/engraving/editor.html?v={et}',s,count=1)
if n!=1: raise SystemExit("Engraver URL not found in Trophy runtime")
s=s.rstrip()+"\n\n"+Path(os.environ["P2K_ENH"]).read_text(encoding="utf-8").lstrip()+"\n"
p.write_text(s,encoding="utf-8")
PY
cp -a "$HERE/r5fix2.2.css" "$CSS_TARGET"

JSKEY="poc-$(sha256sum "$JS"|awk '{print substr($1,1,12)}')-20260910-r5fix2.2"
export P2K_REG="$REG" P2K_STANDALONE="$STANDALONE" P2K_JSKEY="$JSKEY"
python3 <<'PY'
from pathlib import Path
import os,re
key=os.environ["P2K_JSKEY"]
for name in ("P2K_REG","P2K_STANDALONE"):
 p=Path(os.environ[name]);s=p.read_text(encoding="utf-8")
 if re.search(r'trophy-gallery-poc\.js\?v=[^"\']+',s): s,n=re.subn(r'trophy-gallery-poc\.js\?v=[^"\']+',f'trophy-gallery-poc.js?v={key}',s)
 else:
  if 'trophy-gallery-poc.js' not in s: raise SystemExit(f"Trophy loader missing in {p}")
  s=s.replace('trophy-gallery-poc.js',f'trophy-gallery-poc.js?v={key}',1);n=1
 if n<1: raise SystemExit(f"Trophy loader missing in {p}")
 p.write_text(s,encoding="utf-8")
PY

REGKEY="trophyreg-$(sha256sum "$REG"|awk '{print substr($1,1,12)}')-20260910-r5fix2.2"
export P2K_MAIN="$MAIN" P2K_REGKEY="$REGKEY"
python3 <<'PY'
from pathlib import Path
import os,re
p=Path(os.environ["P2K_MAIN"]);s=p.read_text(encoding="utf-8");key=os.environ["P2K_REGKEY"]
s,n=re.subn(r'assets/js/admin/tool-registry\.js\?v=[^"\']+',f'assets/js/admin/tool-registry.js?v={key}',s,count=1)
if n!=1: raise SystemExit("tool-registry.js loader not found in main index.html")
p.write_text(s,encoding="utf-8")
PY

verify
trap - ERR INT TERM
echo "Installed Trophy Gallery r5fix2.2 consolidated repair."
echo "Hard-refresh the MAIN P2K page once (Ctrl+F5)."
