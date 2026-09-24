#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
OUT="${1:-$ROOT/dist/v2.13.3}"
PKG="PromoteToKing_v2.13.3_INCREMENTAL_FROM_2.13.x"
DIR="$OUT/$PKG"
ZIP="$OUT/$PKG.zip"
TEMPLATE="$ROOT/tools/release/v2133/install-promote-to-king-v2.13.3-universal.sh.in"
CANON="$ROOT/tools/release/v2133/canonical-hash.py"
STAMPER="$ROOT/tools/release/v2133/stamp-runtime-cache-key.py"
EARLIEST="9c2f08e3f59945ae983f30d6b214f10140dbd345"
HEAD="$(git -C "$ROOT" rev-parse HEAD)"
BUILD_ID="v2133-universal-2.13x-trophy-admin-race"

[[ "$(cat "$ROOT/VERSION")" == "2.13.3" ]] || { echo 'VERSION must be 2.13.3' >&2; exit 1; }
git -C "$ROOT" merge-base --is-ancestor "$EARLIEST" "$HEAD" || { echo "HEAD must descend from qualified v2.13.0 $EARLIEST" >&2; exit 1; }

FILES=(
  VERSION
  ui-v2.html
  RecruitMatch.html
  MaxRatingBackfill.php
  assets/js/admin/admin-shell.js
  assets/js/admin/tool-registry.js
  assets/js/admin/trophy-gallery-admin-v2121.js
  assets/js/admin/trophy-gallery-engraver-v2121.js
  assets/js/admin/trophy-gallery-poc.js
  assets/js/pages/recruit-match-v2121-bootstrap.js
  assets/js/pages/recruit-match.js
  server/team-points/sql/analytics-schema.sql
  server/team-points/src/McaResultsCronService.php
  server/team-points/src/Repository.php
  server/team-points-green/sql/core-schema.sql
  server/team-points-green/src/GreenCompatibility.php
  server/team-points-green/src/GreenRepository.php
  server/team-points-green/public/max-rating-backfill.php
  server/team-points-green/tools/converge-v2.13.1.php
  server/team-points-green/tools/converge-v2.13.2.php
  server/trophy-gallery/src/TrophyGalleryStore.php
)

rm -rf "$OUT"
mkdir -p "$DIR/payload"
for path in "${FILES[@]}"; do
  [[ -f "$ROOT/$path" ]] || { echo "Missing target runtime file: $path" >&2; exit 1; }
  mkdir -p "$DIR/payload/$(dirname "$path")"
  cp -p "$ROOT/$path" "$DIR/payload/$path"
done

CACHE_KEY="$(python3 "$ROOT/tools/release/static_asset_cache_key.py" key --version 2.13.3 --source-head "$HEAD" --build-id "$BUILD_ID")"
python3 "$ROOT/tools/release/static_asset_cache_key.py" stamp --root "$DIR/payload" --version 2.13.3 --source-head "$HEAD" --build-id "$BUILD_ID" >/dev/null
python3 "$STAMPER" "$DIR/payload" "$CACHE_KEY"
python3 "$ROOT/tools/release/static_asset_cache_key.py" verify --root "$DIR/payload" --version 2.13.3 --source-head "$HEAD" --build-id "$BUILD_ID" >/dev/null

grep -Fq "const TROPHY_RUNTIME_KEY = \"$CACHE_KEY\";" "$DIR/payload/assets/js/admin/tool-registry.js"
grep -Fq "assets/js/pages/recruit-match.js?v=$CACHE_KEY" "$DIR/payload/assets/js/pages/recruit-match-v2121-bootstrap.js"

cp "$CANON" "$DIR/canonical-hash.py"
chmod +x "$DIR/canonical-hash.py"
cp "$TEMPLATE" "$DIR/install-promote-to-king-v2.13.3.sh"
chmod +x "$DIR/install-promote-to-king-v2.13.3.sh"

# Version-specific accepted source lineages. Cache-query values are canonicalized
# separately so production-stamped HTML remains verifiable without weakening drift checks.
REFS_2130=(
  9c2f08e3f59945ae983f30d6b214f10140dbd345
  1c17ca07fdf2b20ac872ff24fb92d87750f6938b
  385faccdbf18725b4487144b8a7cf3e2630ed1b1
)
REFS_2131=(
  c31865b0861445109300fabf9faab62f4a6883f1
  03c476147694220764c0b33448429d14c5e8d6c1
  d2b7468f3f660dd7194d402386ce5d24b69ae349
)
REFS_2132=(
  5672d1370ccd702b8f93fba436dafc7153f52f3a
  82004aaf83b81061c629c6812705c83fb257ce70
  081090f923deb8248158aab9709ce9eb29724428
  4307bc8e7abfac51cc2e2169722876a2eca98e89
  caa99dede51fbfcb59581e3721db78b2d77eb7cc
  011110f413e22d961b9ebba08c98672b42da8166
  3fc8198caca0d6632586faf80aa648f388a033b7
  69515a54b8d00128f72be3943674301fd2b4d210
  cba52fd96ad196cb95321d3a607f99c53f4a2283
)
REFS_2133=(
  a5bc57d25263b5b6006a34760e946cf5b9646a72
  2220bb0fff171f130497936e95077531e1b032bb
  51b1cbac05ce65bd76ad674639868eaee072a83b
  c7d4de40c2ca2538eb337e7e127d71e1e9d3a9d5
  b4c0e5939f4bb72245ee4f0ea0b8b79df374a910
  c2207f7fd60fce3d71780d054201d6aa35291341
  8c23a3515e8f53c2c1de3e7741db00e2d3a607b9
  49cfc7b7d201281d03a24a0c4b29907631732018
  675ef78011d749730236af5da9d75f4069dfb102
  082aab7d5b8b30547fb14bd8e6143aa84f74e105
  "$HEAD"
)

canonical_ref_hash() {
  local ref="$1" path="$2"
  git -C "$ROOT" show "$ref:$path" | python3 "$CANON" --logical-path "$path"
}

emit_baselines() {
  local version="$1" array_name="$2" path ref absent hashes hash
  local -n refs="$array_name"
  for path in "${FILES[@]}"; do
    absent=0
    hashes=""
    for ref in "${refs[@]}"; do
      if git -C "$ROOT" cat-file -e "$ref:$path" 2>/dev/null; then
        hash="$(canonical_ref_hash "$ref" "$path")"
        case ",$hashes," in
          *",$hash,"*) ;;
          *) if [[ -n "$hashes" ]]; then hashes="$hashes,$hash"; else hashes="$hash"; fi ;;
        esac
      else
        absent=1
      fi
    done
    [[ -n "$hashes" || "$absent" == "1" ]] || { echo "No baseline state for $version $path" >&2; exit 1; }
    printf '%s\t%s\t%s\t%s\n' "$version" "$path" "$absent" "$hashes"
  done
}

{
  emit_baselines "2.13.0" REFS_2130
  emit_baselines "2.13.1" REFS_2131
  emit_baselines "2.13.2" REFS_2132
  emit_baselines "2.13.3" REFS_2133
} > "$DIR/BASELINES.tsv"

python3 - "$DIR/install-promote-to-king-v2.13.3.sh" "$HEAD" "$CACHE_KEY" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1])
text=p.read_text()
text=text.replace("@@SOURCE_HEAD@@",sys.argv[2]).replace("@@CACHE_KEY@@",sys.argv[3])
p.write_text(text)
PY

cat > "$DIR/README_INSTALL.txt" <<TXT
Promote to King v2.13.3 cumulative incremental installer
Qualified source HEAD: $HEAD
Static asset cache key: $CACHE_KEY
Supported installed versions: 2.13.0, 2.13.1, 2.13.2, 2.13.3.

This is a cumulative 2.13.x -> 2.13.3 installer. It includes the v2.13.1 schema/MCA
corrections, v2.13.2 rating-cap and Recruitment corrections, and v2.13.3 Trophy
Gallery lifecycle/mount-race corrections. It preserves config, data, storage and CRON.

Install:
  cd ~/PromoteToKing
  unzip -q $PKG.zip
  cd $PKG
  bash install-promote-to-king-v2.13.3.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing
TXT
printf '%s\n' "$HEAD" > "$DIR/SOURCE_HEAD.txt"
printf '%s\n' "$CACHE_KEY" > "$DIR/ASSET_CACHE_KEY.txt"
(
  cd "$DIR/payload"
  find . -type f -print0 | sort -z | xargs -0 sha256sum
) > "$DIR/PAYLOAD.sha256"

if grep -q '@@' "$DIR/install-promote-to-king-v2.13.3.sh"; then
  echo 'Unresolved installer token' >&2
  exit 1
fi
bash -n "$DIR/install-promote-to-king-v2.13.3.sh"
python3 -m py_compile "$DIR/canonical-hash.py"
rm -f "$ZIP"
( cd "$OUT" && zip -X -qr "$PKG.zip" "$PKG" )
unzip -t "$ZIP" >/dev/null
sha256sum "$ZIP"
