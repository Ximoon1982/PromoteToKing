#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
OUT="$TMP/build"
PKG="PromoteToKing_v2.13.3_INCREMENTAL_FROM_2.13.x"

bash "$ROOT/tools/release/v2133/build-universal-2.13x.sh" "$OUT" >/dev/null
INSTALLER="$OUT/$PKG/install-promote-to-king-v2.13.3.sh"

FILES=(
  VERSION ui-v2.html trophies/index.html RecruitMatch.html MaxRatingBackfill.php
  assets/js/admin/admin-shell.js assets/js/admin/tool-registry.js
  assets/js/admin/trophy-gallery-admin-v2121.js assets/js/admin/trophy-gallery-admin-view-v2121.js
  assets/js/admin/trophy-gallery-engraver-v2121.js assets/js/admin/trophy-gallery-poc.js
  assets/js/admin/trophy-gallery-r5fix3.8.js assets/trophy-gallery/trophy-gallery-r5fix3.8.css
  assets/trophy-gallery/engraving/editor-v2121.html assets/js/pages/recruit-match-v2121-bootstrap.js
  assets/js/pages/recruit-match.js server/team-points/sql/analytics-schema.sql
  server/team-points/src/McaResultsCronService.php server/team-points/src/Repository.php
  server/team-points-green/sql/core-schema.sql server/team-points-green/src/GreenCompatibility.php
  server/team-points-green/src/GreenRepository.php server/team-points-green/public/max-rating-backfill.php
  server/team-points-green/tools/converge-v2.13.1.php server/team-points-green/tools/converge-v2.13.2.php
  server/trophy-gallery/src/TrophyGalleryStore.php
)

materialize_ref(){
  local ref="$1" dst="$2" path
  rm -rf "$dst"; mkdir -p "$dst"
  for path in "${FILES[@]}"; do
    if git -C "$ROOT" cat-file -e "$ref:$path" 2>/dev/null; then
      mkdir -p "$dst/$(dirname "$path")"
      git -C "$ROOT" show "$ref:$path" > "$dst/$path"
    fi
  done
}

stamp_dynamic_fixture_keys(){
  local dst="$1"
  python3 - "$dst" <<'PY'
from pathlib import Path
import re,sys
root=Path(sys.argv[1])
registry=root/'assets/js/admin/tool-registry.js'
if registry.exists():
    text=registry.read_text()
    text=re.sub(r'const\s+TROPHY_RUNTIME_KEY\s*=\s*"[^"]*"\s*;', 'const TROPHY_RUNTIME_KEY = "fixture-cache-key";', text)
    registry.write_text(text)
bootstrap=root/'assets/js/pages/recruit-match-v2121-bootstrap.js'
if bootstrap.exists():
    text=bootstrap.read_text()
    text=re.sub(r'(assets/js/pages/(?:recruit-match-v2-core|recruit-match)\.js\?v=)[^"\']+', r'\1fixture-cache-key', text)
    bootstrap.write_text(text)
PY
}

preflight_ref(){
  local version="$1" ref="$2" stamp="${3:-0}"
  local dst="$TMP/$version-${ref:0:8}"
  materialize_ref "$ref" "$dst"
  [[ "$(tr -d '\r\n' < "$dst/VERSION")" == "$version" ]]
  if [[ "$stamp" == "1" ]]; then
    python3 "$ROOT/tools/release/static_asset_cache_key.py" stamp --root "$dst" --version "$version" --source-head "$ref" --build-id universal-installer-fixture >/dev/null
    stamp_dynamic_fixture_keys "$dst"
  fi
  P2K_INSTALL_PREFLIGHT_ONLY=1 bash "$INSTALLER" "$dst" >/dev/null
}

preflight_ref 2.13.0 9c2f08e3f59945ae983f30d6b214f10140dbd345 1
preflight_ref 2.13.0 385faccdbf18725b4487144b8a7cf3e2630ed1b1 0
preflight_ref 2.13.1 d2b7468f3f660dd7194d402386ce5d24b69ae349 0
preflight_ref 2.13.2 cba52fd96ad196cb95321d3a607f99c53f4a2283 1
preflight_ref 2.13.3 49cfc7b7d201281d03a24a0c4b29907631732018 0
preflight_ref 2.13.3 675ef78011d749730236af5da9d75f4069dfb102 1
preflight_ref 2.13.3 082aab7d5b8b30547fb14bd8e6143aa84f74e105 1
preflight_ref 2.13.3 f0b11fd53dac013852a1e47c163fc4d2c3659d1c 1
preflight_ref 2.13.3 "$(git -C "$ROOT" rev-parse HEAD)" 0

DRIFT="$TMP/drift"
materialize_ref cba52fd96ad196cb95321d3a607f99c53f4a2283 "$DRIFT"
printf '\n/* unexpected local drift */\n' >> "$DRIFT/assets/js/admin/trophy-gallery-admin-v2121.js"
if P2K_INSTALL_PREFLIGHT_ONLY=1 bash "$INSTALLER" "$DRIFT" >/dev/null 2>&1; then
  echo "Universal installer accepted unexpected source drift" >&2
  exit 1
fi

FUTURE="$TMP/future"
materialize_ref cba52fd96ad196cb95321d3a607f99c53f4a2283 "$FUTURE"
printf '2.13.4\n' > "$FUTURE/VERSION"
if P2K_INSTALL_PREFLIGHT_ONLY=1 bash "$INSTALLER" "$FUTURE" >/dev/null 2>&1; then
  echo "Universal installer accepted unsupported future version" >&2
  exit 1
fi

echo "v2.13.3 universal 2.13.x installer baseline gate passed"
