#!/usr/bin/env bash
set -euo pipefail
trap 'rc=$?; echo "v2.15.0 package build failed at line $LINENO (exit $rc)" >&2; exit $rc' ERR

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
OUT="${1:-$ROOT/dist/v2.15.0}"
PKG="PromoteToKing_v2.15.0_INCREMENTAL_FROM_2.14.9"
DIR="$OUT/$PKG"
ZIP="$OUT/$PKG.zip"
HEAD="$(git -C "$ROOT" rev-parse HEAD)"
BUILD_ID="v2150-trophy-modal-layout-club-points-audit"
BASE_HEAD="2603b3f57f9bde29bbf0f6adf490b2bc533623ab"

[[ "$(cat "$ROOT/VERSION")" == "2.15.0" ]] || { echo 'VERSION must be 2.15.0' >&2; exit 1; }
git -C "$ROOT" merge-base --is-ancestor "$BASE_HEAD" "$HEAD" || { echo "HEAD must descend from final v2.14.9 $BASE_HEAD" >&2; exit 1; }

OVERLAY_FILES=(
  VERSION
  ui-v2.html
  trophies/index.html
  assets/js/admin/trophy-gallery-v2150-modal-layout.js
  assets/trophy-gallery/trophy-gallery-v2150-mobile.css
  server/team-points-green/tools/audit-club-points.php
)

RECOVERY_FILES=(
  .htaccess
  ReleaseControl.php
  PreviewRouter.php
  PublicRouter.php
  server/release-control/config/.htaccess
  server/release-control/config/config.example.php
  server/release-control/public/preview-oauth-session.php
  server/release-control/public/release-control-upload.js
  server/release-control/src/bootstrap.php
  server/release-control/src/ReleaseControlAuth.php
  server/release-control/src/ReleaseControlState.php
  server/release-control/src/ReleaseVersionManager.php
  server/release-control/src/ReleasePackageUploadInstaller.php
  server/release-control/src/FilesystemCleanupManager.php
  server/release-control/src/FilesystemAuditManager.php
  server/release-control/src/ReleaseSlotPolicy.php
  server/release-control/src/ReleaseSlotFilesystemProbe.php
  server/release-control/src/ReleaseSlotStore.php
  server/release-control/src/ReleaseSlotMaterializer.php
  server/release-control/src/ReleaseCandidatePackage.php
  server/release-control/src/ReleaseStateStore.php
  server/release-control/src/ReleaseRuntimeTree.php
  server/release-control/src/ReleaseDeploymentManager.php
  server/release-control/src/ReleaseCandidateInstaller.php
  server/release-control/src/ReleasePreviewTree.php
  server/release-control/src/ReleasePreviewSession.php
  server/release-control/tools/materialize-current-slot.php
  server/release-control/tools/install-candidate.php
  server/release-control/tools/prepare-preview.php
  server/release-control/tools/verify-slot.php
  server/release-control/tools/release-slot-paths.py
  server/release-control/README.md
)

rm -rf "$OUT"
mkdir -p "$DIR/payload"
for path in "${OVERLAY_FILES[@]}" "${RECOVERY_FILES[@]}"; do
  [[ -f "$ROOT/$path" ]] || { echo "Missing package file: $path" >&2; exit 1; }
  mkdir -p "$DIR/payload/$(dirname "$path")"
  cp -p "$ROOT/$path" "$DIR/payload/$path"
done

CACHE_KEY="$(python3 "$ROOT/tools/release/static_asset_cache_key.py" key --version 2.15.0 --source-head "$HEAD" --build-id "$BUILD_ID")"
python3 "$ROOT/tools/release/static_asset_cache_key.py" stamp --root "$DIR/payload" --version 2.15.0 --source-head "$HEAD" --build-id "$BUILD_ID" >/dev/null

grep -Fq "$CACHE_KEY" "$DIR/payload/ui-v2.html"
grep -Fq "$CACHE_KEY" "$DIR/payload/trophies/index.html"
grep -Fq 'trophy-gallery-v2150-modal-layout.js' "$DIR/payload/ui-v2.html"
grep -Fq 'trophy-gallery-v2150-mobile.css' "$DIR/payload/ui-v2.html"
grep -Fq 'trophy-gallery-v2150-modal-layout.js' "$DIR/payload/trophies/index.html"
grep -Fq 'trophy-gallery-v2150-mobile.css' "$DIR/payload/trophies/index.html"
grep -Fq 'LANDSCAPE_RATIO = 1.10' "$DIR/payload/assets/js/admin/trophy-gallery-v2150-modal-layout.js"
grep -Fq 'height: 100dvh !important' "$DIR/payload/assets/trophy-gallery/trophy-gallery-v2150-mobile.css"

printf '%s\n' "$HEAD" > "$DIR/SOURCE_HEAD.txt"
printf '%s\n' "$CACHE_KEY" > "$DIR/ASSET_CACHE_KEY.txt"
: > "$DIR/CANDIDATE_OVERLAY_PATHS.txt"
for path in "${OVERLAY_FILES[@]}"; do printf '%s\n' "$path" >> "$DIR/CANDIDATE_OVERLAY_PATHS.txt"; done
sort -o "$DIR/CANDIDATE_OVERLAY_PATHS.txt" "$DIR/CANDIDATE_OVERLAY_PATHS.txt"
: > "$DIR/CANDIDATE_REMOVED_PATHS.txt"

: > "$DIR/CANDIDATE_PAYLOAD.sha256"
while IFS= read -r path; do
  ( cd "$DIR/payload" && sha256sum "./$path" ) >> "$DIR/CANDIDATE_PAYLOAD.sha256"
done < "$DIR/CANDIDATE_OVERLAY_PATHS.txt"

: > "$DIR/RECOVERY_PLANE.sha256"
for path in "${RECOVERY_FILES[@]}"; do
  ( cd "$DIR/payload" && sha256sum "./$path" ) >> "$DIR/RECOVERY_PLANE.sha256"
done

(
  cd "$DIR/payload"
  find . -type f -print0 | sort -z | xargs -0 sha256sum
) > "$DIR/PAYLOAD.sha256"

MANIFEST_SHA="$(sha256sum "$DIR/CANDIDATE_PAYLOAD.sha256" | awk '{print $1}')"
python3 - "$DIR/CANDIDATE_RELEASE.json" "$HEAD" "$CACHE_KEY" "$BUILD_ID" "$MANIFEST_SHA" <<'PY'
from pathlib import Path
import json,sys
out,head,cache_key,build_id,manifest_sha=sys.argv[1:]
payload={
  "schema_version":1,
  "version":"2.15.0",
  "source_head":head,
  "cache_key":cache_key,
  "build_id":build_id,
  "qualification_workflow":"P2K v2.15.0 package",
  "payload_manifest_sha256":manifest_sha,
  "accepted_public_builds":[
    {"version":"2.14.9","source_head":"2603b3f57f9bde29bbf0f6adf490b2bc533623ab"}
  ]
}
Path(out).write_text(json.dumps(payload,indent=2)+"\n")
PY

cp "$ROOT/tools/release/v2150/prepare-candidate-preview-v2.15.0.sh" "$DIR/prepare-candidate-preview-v2.15.0.sh"
chmod +x "$DIR/prepare-candidate-preview-v2.15.0.sh"

cat > "$DIR/README_INSTALL.txt" <<TXT
Promote to King v2.15.0 incremental candidate package
Source HEAD: $HEAD
Static asset cache key: $CACHE_KEY
Required public base: v2.14.9 at $BASE_HEAD

Contents:
- Trophy modal mobile gutter correction.
- Adaptive desktop Trophy modal layout based on artwork aspect ratio.
- Full-viewport Trophy artwork viewer.
- Club-points audit tool added in the current 2.15.0 branch.

Recommended installation:
  cd ~/PromoteToKing
  unzip -q $PKG.zip
  cd $PKG
  bash prepare-candidate-preview-v2.15.0.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing ximoon

This installs and prepares the candidate preview only. It does not promote or deploy the candidate publicly.
You can also upload the ZIP directly in Release Control.
TXT

bash -n "$DIR/prepare-candidate-preview-v2.15.0.sh"
php -l "$DIR/payload/server/team-points-green/tools/audit-club-points.php" >/dev/null

rm -f "$ZIP"
( cd "$OUT" && zip -X -qr "$PKG.zip" "$PKG" )
unzip -t "$ZIP" >/dev/null
sha256sum "$ZIP"
