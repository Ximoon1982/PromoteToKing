#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
cleanup(){
  find "$TMP" -type d -exec chmod u+w {} + 2>/dev/null || true
  rm -rf "$TMP" || true
}
trap cleanup EXIT
OUT="$TMP/build"
PKG="PromoteToKing_v2.14.4_INCREMENTAL_FROM_2.13.x"

bash "$ROOT/tools/release/v2144/build-universal-2.13x.sh" "$OUT" >/dev/null
INSTALLER="$OUT/$PKG/install-promote-to-king-v2.14.4.sh"
grep -Fq 'Supported installed versions: 2.13.0, 2.13.1, 2.13.2, 2.13.3, 2.13.4, 2.13.5, 2.14.0, 2.14.1, 2.14.2, 2.14.3, 2.14.4.' "$OUT/$PKG/README_INSTALL.txt"
grep -Fq 'Super Admin-only candidate preview' "$OUT/$PKG/README_INSTALL.txt"
grep -Fq 'prepare-candidate-preview-v2.14.4.sh' "$OUT/$PKG/README_INSTALL.txt"
if find "$OUT/$PKG" -type f \( -name '*.pyc' -o -name '*.pyo' \) -print -quit | grep -q .; then
  echo "v2.14.4 package contains Python bytecode" >&2
  exit 1
fi
if find "$OUT/$PKG" -type d -name '__pycache__' -print -quit | grep -q .; then
  echo "v2.14.4 package contains __pycache__" >&2
  exit 1
fi

FILES=(
  .htaccess VERSION ReleaseControl.php PreviewRouter.php ui-v2.html trophies/index.html RecruitMatch.html ChallengeListAssistant.html MaxRatingBackfill.php
  api/_common.php api/router.php server/shared/TaskRegistry.php
  server/team-points/public/session.php server/team-points/src/Auth.php server/team-points/src/Database.php
  server/team-points/src/PreviewIsolation.php server/team-points/src/bootstrap.php server/team-points-green/src/GreenConfig.php
  assets/js/admin/admin-shell.js assets/js/admin/tool-registry.js
  assets/js/admin/trophy-gallery-admin-v2121.js assets/js/admin/trophy-gallery-admin-view-v2121.js
  assets/js/admin/trophy-gallery-engraver-v2121.js assets/js/admin/trophy-gallery-poc.js
  assets/js/admin/trophy-gallery-r5fix3.8.js assets/trophy-gallery/trophy-gallery-r5fix3.8.css
  assets/trophy-gallery/engraving/editor-v2121.html assets/js/pages/recruit-match-v2121-bootstrap.js
  assets/js/pages/recruit-match.js assets/js/pages/challenge-list-assistant.js
  assets/js/dashboard/insights-controller.js assets/js/pages/dashboard-insights.js
  assets/js/shared/real-oauth.js assets/js/shared/team-points-client.js assets/js/site-config.js
  server/team-points/sql/analytics-schema.sql
  assets/js/shared/events-showcase-core.js assets/js/events-showcase-line-v2122.js
  server/events-showcase/public/embed.php server/events-showcase/public/embed-card.php
  server/team-points/public/arenas-insights-export.php server/team-points/public/arenas-insights.php
  server/team-points/src/LiveRanksService.php server/team-points/src/McaResultsCronService.php
  server/team-points/src/OAuthSession.php server/team-points/src/Repository.php
  server/team-points-green/sql/core-schema.sql server/team-points-green/src/GreenCompatibility.php
  server/team-points-green/src/GreenRepository.php server/team-points-green/public/max-rating-backfill.php
  server/team-points-green/tools/converge-v2.13.1.php server/team-points-green/tools/converge-v2.13.2.php
  server/trophy-gallery/src/TrophyGalleryStore.php
  server/release-control/config/.htaccess server/release-control/config/config.example.php
  server/release-control/public/preview-oauth-session.php server/release-control/src/bootstrap.php server/release-control/src/ReleaseControlAuth.php
  server/release-control/src/ReleaseControlState.php
  server/release-control/src/ReleaseSlotPolicy.php server/release-control/src/ReleaseSlotFilesystemProbe.php
  server/release-control/src/ReleaseSlotStore.php server/release-control/src/ReleaseSlotMaterializer.php
  server/release-control/src/ReleaseCandidatePackage.php server/release-control/src/ReleaseStateStore.php
  server/release-control/src/ReleaseCandidateInstaller.php
  server/release-control/src/ReleasePreviewTree.php server/release-control/src/ReleasePreviewSession.php
  server/release-control/tools/materialize-current-slot.php server/release-control/tools/install-candidate.php
  server/release-control/tools/prepare-preview.php server/release-control/tools/verify-slot.php
  server/release-control/tools/release-slot-paths.py server/release-control/README.md
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
preflight_ref 2.13.3 fe5feaa9d0cd15fec7a72de704dcd72cdbba4d7a 0
preflight_ref 2.13.4 abc70897db538935459d1c4ae7710433786a10d7 0
preflight_ref 2.13.4 debfcdfe2e828a5db95ba773d4bbb47ff1deb303 0
preflight_ref 2.13.5 f1549dae110ac95b98e15db7d1350ff1dd0e8088 0
preflight_ref 2.13.5 a1cafe7167752c3660a90bd941eeaa276ffb03b7 0
preflight_ref 2.14.0 92cc618e02fe6d0eaeefedf67fad887f16902e92 0
preflight_ref 2.14.1 9a46ee7a6f8972071289357d0dc23ccd5eb1fab9 0
preflight_ref 2.14.2 9257577544d8ee7d54a9d23152073f340fe90ed1 0
preflight_ref 2.14.3 "$(git -C "$ROOT" rev-parse HEAD)" 0

DRIFT="$TMP/drift"
materialize_ref cba52fd96ad196cb95321d3a607f99c53f4a2283 "$DRIFT"
printf '\n/* unexpected local drift */\n' >> "$DRIFT/assets/js/admin/trophy-gallery-admin-v2121.js"
if P2K_INSTALL_PREFLIGHT_ONLY=1 bash "$INSTALLER" "$DRIFT" >/dev/null 2>&1; then
  echo "Universal installer accepted unexpected source drift" >&2
  exit 1
fi

FUTURE="$TMP/future"
materialize_ref cba52fd96ad196cb95321d3a607f99c53f4a2283 "$FUTURE"
printf '2.14.4\n' > "$FUTURE/VERSION"
if P2K_INSTALL_PREFLIGHT_ONLY=1 bash "$INSTALLER" "$FUTURE" >/dev/null 2>&1; then
  echo "Universal installer accepted unsupported future version" >&2
  exit 1
fi

grep -Fq 'tmp="$dst.p2k-v2144-$$.tmp"' "$INSTALLER" || { echo "Installer PID suffix contract missing" >&2; exit 1; }
grep -Fq 'Materializing protected pre-upgrade release slot' "$INSTALLER" || { echo "Pre-upgrade slot materialization contract missing" >&2; exit 1; }
grep -Fq 'Materializing verified v2.14.4 release slot' "$INSTALLER" || { echo "Target slot materialization contract missing" >&2; exit 1; }
grep -Fq 'server/release-control/tools/release-slot-paths.py' "$INSTALLER" || { echo "Slot path selector contract missing" >&2; exit 1; }
python3 - "$INSTALLER" <<'PY'
from pathlib import Path
import sys
text=Path(sys.argv[1]).read_text()
pre=text.index("Materializing protected pre-upgrade release slot")
activate=text.index("Activating cumulative v2.14.4 runtime files")
target=text.index("Materializing verified v2.14.4 release slot")
assert pre < activate < target
PY
PKGDIR="$OUT/$PKG"
test -s "$PKGDIR/CANDIDATE_RELEASE.json"
test -s "$PKGDIR/CANDIDATE_PAYLOAD.sha256"
test -f "$PKGDIR/CANDIDATE_REMOVED_PATHS.txt"
grep -Fq '"version": "2.14.3"' "$PKGDIR/CANDIDATE_RELEASE.json"
grep -Fq '"version": "2.14.2"' "$PKGDIR/CANDIDATE_RELEASE.json"
grep -Fxq VERSION "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt"
grep -Fxq ui-v2.html "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt"
! grep -Fxq .htaccess "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt"
! grep -Fq 'ReleaseControl.php' "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt"
! grep -Fq 'PreviewRouter.php' "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt"
! grep -Fq 'server/release-control/' "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt"
for path in \
  assets/js/dashboard/insights-controller.js \
  assets/js/pages/dashboard-insights.js \
  assets/js/shared/real-oauth.js \
  assets/js/shared/team-points-client.js \
  assets/js/site-config.js \
  server/team-points/public/arenas-insights-export.php \
  server/team-points/public/arenas-insights.php \
  server/team-points/src/LiveRanksService.php \
  server/team-points/src/OAuthSession.php; do
  grep -Fxq "$path" "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt" || { echo "Candidate overlay missing $path" >&2; exit 1; }
  test -f "$PKGDIR/payload/$path" || { echo "Candidate payload missing $path" >&2; exit 1; }
done
test -f "$PKGDIR/payload/server/release-control/public/preview-oauth-session.php"
! grep -Fxq 'server/release-control/public/preview-oauth-session.php' "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt"
grep -Fq "PHP_SAPI !== 'cli'" "$OUT/$PKG/payload/server/release-control/tools/install-candidate.php"
test -x "$PKGDIR/prepare-candidate-preview-v2.14.4.sh"
bash -n "$PKGDIR/prepare-candidate-preview-v2.14.4.sh"
grep -Fq -- '--replace-candidate' "$PKGDIR/prepare-candidate-preview-v2.14.4.sh"
grep -Fq 'prepare-preview.php' "$PKGDIR/prepare-candidate-preview-v2.14.4.sh"
grep -Fq 'p2k-v2144-candidate-$$.json' "$PKGDIR/prepare-candidate-preview-v2.14.4.sh"
grep -Fq 'p2k-v2144-preview-$$.json' "$PKGDIR/prepare-candidate-preview-v2.14.4.sh"

PREVIEW_ROOT="$TMP/preview-root"
mkdir -p "$PREVIEW_ROOT"
git -C "$ROOT" archive 9257577544d8ee7d54a9d23152073f340fe90ed1 | tar -x -C "$PREVIEW_ROOT"
BASE_KEY="$(python3 "$ROOT/tools/release/static_asset_cache_key.py" key --version 2.14.2 --source-head 9257577544d8ee7d54a9d23152073f340fe90ed1 --build-id v2142-candidate-installation)"
python3 "$ROOT/tools/release/static_asset_cache_key.py" stamp --root "$PREVIEW_ROOT" --version 2.14.2 --source-head 9257577544d8ee7d54a9d23152073f340fe90ed1 --build-id v2142-candidate-installation >/dev/null
python3 "$ROOT/tools/release/v2142/stamp-runtime-cache-key.py" "$PREVIEW_ROOT" "$BASE_KEY"
mkdir -p "$PREVIEW_ROOT/data/runtime-v280"
python3 "$PREVIEW_ROOT/server/release-control/tools/release-slot-paths.py" --root "$PREVIEW_ROOT" > "$TMP/public-v2142-paths.txt"
php "$PREVIEW_ROOT/server/release-control/tools/materialize-current-slot.php" --root="$PREVIEW_ROOT" --version="2.14.2" --source-head="9257577544d8ee7d54a9d23152073f340fe90ed1" --cache-key="$BASE_KEY" --paths="$TMP/public-v2142-paths.txt" >/dev/null
PUBLIC_VERSION_SHA="$(sha256sum "$PREVIEW_ROOT/VERSION" | awk '{print $1}')"
PUBLIC_UI_SHA="$(sha256sum "$PREVIEW_ROOT/ui-v2.html" | awk '{print $1}')"
mkdir -p "$PREVIEW_ROOT/data/runtime-v280/release-control"
cat > "$PREVIEW_ROOT/data/runtime-v280/release-control/state.json" <<'JSON'
{
  "schema_version": 1,
  "mode": "direct-root",
  "public_release": "2.14.2 (direct root)",
  "previous_public_release": null,
  "candidate_release": "2.14.3-b7526eb705d0",
  "updated_at": "2026-09-30T11:54:26+00:00",
  "updated_by": "ximoon"
}
JSON
bash "$PKGDIR/prepare-candidate-preview-v2.14.4.sh" "$PREVIEW_ROOT" ximoon >/dev/null
[[ "$(sha256sum "$PREVIEW_ROOT/VERSION" | awk '{print $1}')" == "$PUBLIC_VERSION_SHA" ]]
[[ "$(sha256sum "$PREVIEW_ROOT/ui-v2.html" | awk '{print $1}')" == "$PUBLIC_UI_SHA" ]]
[[ "$(tr -d '\r\n' < "$PREVIEW_ROOT/VERSION")" == "2.14.2" ]]
grep -Fq 'v2.14.4 · Super Admin candidate preview' "$PREVIEW_ROOT/ReleaseControl.php"
grep -Fq 'P2KRC_PREVIEW' "$PREVIEW_ROOT/.htaccess"
test -f "$PREVIEW_ROOT/server/release-control/public/preview-oauth-session.php"
grep -Fq 'oauthSessionStatus' "$PREVIEW_ROOT/server/release-control/public/preview-oauth-session.php"
grep -Fq '"candidate_release": "2.14.3-' "$PREVIEW_ROOT/data/runtime-v280/release-control/state.json"
CANDIDATE_ID="$(python3 - "$PREVIEW_ROOT/data/runtime-v280/release-control/state.json" <<'PY'
import json,sys
print(json.load(open(sys.argv[1]))["candidate_release"])
PY
)"
test -s "$PREVIEW_ROOT/data/runtime-v280/release-control/previews/$CANDIDATE_ID/meta/preview.json"

echo "v2.14.4 cumulative installer and candidate-preview bootstrap gate passed"
