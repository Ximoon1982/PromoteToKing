#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
OUT="${1:-$ROOT/dist/v2.13.3}"
PKG="PromoteToKing_v2.13.3_INCREMENTAL_FROM_2.13.2"
DIR="$OUT/$PKG"; ZIP="$OUT/$PKG.zip"
TEMPLATE="$ROOT/tools/release/v2133/install-promote-to-king-v2.13.3.sh.in"
BASE="4307bc8e7abfac51cc2e2169722876a2eca98e89"
HEAD="$(git -C "$ROOT" rev-parse HEAD)"
BUILD_ID="v2133-trophy-admin-incremental"
[[ "$(cat "$ROOT/VERSION")" == "2.13.3" ]] || { echo 'VERSION must be 2.13.3' >&2; exit 1; }
git -C "$ROOT" merge-base --is-ancestor "$BASE" "$HEAD" || { echo "HEAD must descend from qualified v2.13.2 $BASE" >&2; exit 1; }
hash_ref(){ git -C "$ROOT" show "$1:$2" | sha256sum | awk '{print $1}'; }
hash_payload(){ sha256sum "$DIR/payload/$1" | awk '{print $1}'; }
rm -rf "$OUT"; mkdir -p "$DIR/payload"
FILES=(
  VERSION
  ui-v2.html
  assets/js/admin/admin-shell.js
  assets/js/admin/tool-registry.js
  assets/js/admin/trophy-gallery-admin-v2121.js
  assets/js/admin/trophy-gallery-engraver-v2121.js
  assets/js/admin/trophy-gallery-poc.js
  server/trophy-gallery/src/TrophyGalleryStore.php
)
for path in "${FILES[@]}"; do mkdir -p "$DIR/payload/$(dirname "$path")"; cp -p "$ROOT/$path" "$DIR/payload/$path"; done
CACHE_KEY="$(python3 "$ROOT/tools/release/static_asset_cache_key.py" key --version 2.13.3 --source-head "$HEAD" --build-id "$BUILD_ID")"
python3 "$ROOT/tools/release/v2133/stamp-trophy-cache-key.py" "$DIR/payload" "$CACHE_KEY"
grep -Fq "assets/js/admin/admin-shell.js?v=$CACHE_KEY" "$DIR/payload/ui-v2.html"
grep -Fq "assets/js/admin/tool-registry.js?v=$CACHE_KEY" "$DIR/payload/ui-v2.html"
grep -Fq "const TROPHY_RUNTIME_KEY = \"$CACHE_KEY\";" "$DIR/payload/assets/js/admin/tool-registry.js"

cp "$TEMPLATE" "$DIR/install-promote-to-king-v2.13.3.sh"
python3 - "$DIR/install-promote-to-king-v2.13.3.sh" "$HEAD" "$CACHE_KEY" "$BASE" "$DIR/payload" <<'PY'
from pathlib import Path
import hashlib,subprocess,sys
installer=Path(sys.argv[1]);head=sys.argv[2];key=sys.argv[3];base=sys.argv[4];payload=Path(sys.argv[5])
files=[
"VERSION","ui-v2.html","assets/js/admin/admin-shell.js","assets/js/admin/tool-registry.js",
"assets/js/admin/trophy-gallery-admin-v2121.js","assets/js/admin/trophy-gallery-engraver-v2121.js",
"assets/js/admin/trophy-gallery-poc.js","server/trophy-gallery/src/TrophyGalleryStore.php"]
def href(path):
    return hashlib.sha256(subprocess.check_output(["git","show",f"{base}:{path}"])).hexdigest()
def hp(path):
    return hashlib.sha256((payload/path).read_bytes()).hexdigest()
text=installer.read_text().replace("@@SOURCE_HEAD@@",head).replace("@@CACHE_KEY@@",key)
for i,path in enumerate(files):
    text=text.replace(f"@@BASE_{i}@@",href(path)).replace(f"@@TARGET_{i}@@",hp(path))
installer.write_text(text)
PY
chmod +x "$DIR/install-promote-to-king-v2.13.3.sh"
cat > "$DIR/README_INSTALL.txt" <<TXT
Promote to King v2.13.3 incremental installer
Source HEAD: $HEAD
Baseline: qualified v2.13.2 $BASE
Static cache key: $CACHE_KEY

Install:
  cd /kunden/homepages/43/d141198007/htdocs/PromoteToKing
  unzip -q $PKG.zip
  cd $PKG
  bash install-promote-to-king-v2.13.3.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing
TXT
printf '%s\n' "$HEAD" > "$DIR/SOURCE_HEAD.txt"
printf '%s\n' "$CACHE_KEY" > "$DIR/ASSET_CACHE_KEY.txt"
( cd "$DIR/payload"; find . -type f -print0 | sort -z | xargs -0 sha256sum ) > "$DIR/PAYLOAD.sha256"
if grep -q '@@' "$DIR/install-promote-to-king-v2.13.3.sh"; then echo 'Unresolved installer token' >&2; exit 1; fi
bash -n "$DIR/install-promote-to-king-v2.13.3.sh"
rm -f "$ZIP"; ( cd "$OUT"; zip -X -qr "$PKG.zip" "$PKG" )
unzip -t "$ZIP" >/dev/null
sha256sum "$ZIP"
