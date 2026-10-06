#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'find "$TMP" -type d -exec chmod u+w {} + 2>/dev/null || true; rm -rf "$TMP"' EXIT
OUT="$TMP/build"
PKG="PromoteToKing_v2.14.5_INCREMENTAL_FROM_2.13.x"

bash "$ROOT/tools/release/v2145/build-universal-2.13x.sh" "$OUT" >/dev/null
PKGDIR="$OUT/$PKG"
INSTALLER="$PKGDIR/install-promote-to-king-v2.14.5.sh"

test -s "$PKGDIR/CANDIDATE_RELEASE.json"
test -s "$PKGDIR/CANDIDATE_PAYLOAD.sha256"
test -x "$PKGDIR/prepare-candidate-preview-v2.14.5.sh"
grep -Fq '"version": "2.14.5"' "$PKGDIR/CANDIDATE_RELEASE.json"
grep -Fq '"version": "2.14.2"' "$PKGDIR/CANDIDATE_RELEASE.json"
grep -Fq '"version": "2.14.4"' "$PKGDIR/CANDIDATE_RELEASE.json"
grep -Fxq VERSION "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt"
grep -Fxq ui-v2.html "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt"
! grep -Fxq .htaccess "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt"
! grep -Fxq ReleaseControl.php "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt"
! grep -Fxq PreviewRouter.php "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt"
! grep -Fxq PublicRouter.php "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt"
! grep -Fq 'server/release-control/' "$PKGDIR/CANDIDATE_OVERLAY_PATHS.txt"
grep -Fq 'v2.14.5 · Atomic promotion and rollback' "$PKGDIR/payload/ReleaseControl.php"
grep -Fq 'class ReleaseDeploymentManager' "$PKGDIR/payload/server/release-control/src/ReleaseDeploymentManager.php"
grep -Fq 'class ReleaseRuntimeTree' "$PKGDIR/payload/server/release-control/src/ReleaseRuntimeTree.php"
grep -Fq -- '--replace-candidate' "$PKGDIR/prepare-candidate-preview-v2.14.5.sh"
bash -n "$PKGDIR/prepare-candidate-preview-v2.14.5.sh"
bash -n "$INSTALLER"

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
bash "$PKGDIR/prepare-candidate-preview-v2.14.5.sh" "$PREVIEW_ROOT" ximoon >/dev/null
[[ "$(sha256sum "$PREVIEW_ROOT/VERSION" | awk '{print $1}')" == "$PUBLIC_VERSION_SHA" ]]
[[ "$(sha256sum "$PREVIEW_ROOT/ui-v2.html" | awk '{print $1}')" == "$PUBLIC_UI_SHA" ]]
[[ "$(tr -d '\r\n' < "$PREVIEW_ROOT/VERSION")" == "2.14.2" ]]
grep -Fq 'v2.14.5 · Atomic promotion and rollback' "$PREVIEW_ROOT/ReleaseControl.php"
grep -Fq 'PublicRouter.php' "$PREVIEW_ROOT/.htaccess"
grep -Fq '"candidate_release": "2.14.5-' "$PREVIEW_ROOT/data/runtime-v280/release-control/state.json"

echo "v2.14.5 cumulative installer and candidate bootstrap gate passed"
