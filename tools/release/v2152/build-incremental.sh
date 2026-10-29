#!/usr/bin/env bash
set -euo pipefail
trap 'rc=$?; echo "v2.15.2 package build failed at line $LINENO (exit $rc)" >&2; exit $rc' ERR

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
OUT="${1:-$ROOT/dist/v2.15.2}"
PKG="PromoteToKing_v2.15.2_INCREMENTAL_FROM_2.15.1"
DIR="$OUT/$PKG"
ZIP="$OUT/$PKG.zip"
HEAD="$(git -C "$ROOT" rev-parse HEAD)"
BUILD_ID="v2152-match-recruitment-history"
BASE_HEAD="8c0e52b58efee658d72bf9786a89568cd21af930"

[[ "$(cat "$ROOT/VERSION")" == "2.15.2" ]] || { echo 'VERSION must be 2.15.2' >&2; exit 1; }
git -C "$ROOT" merge-base --is-ancestor "$BASE_HEAD" "$HEAD" || { echo "HEAD must descend from qualified v2.15.1 $BASE_HEAD" >&2; exit 1; }

OVERLAY_FILES=(
  VERSION
  ui-v2.html
  ClubIntelligence.html
  TaskControl.html
  TournamentManagement.html
  TeamPointsAdmin.html
  ChallengeListAssistant.html
  trophies/index.html
  assets/css/simulated-oauth.css
  assets/css/task-control.css
  assets/css/challenge-list-assistant.css
  assets/js/shared/real-oauth.js
  assets/js/shared/upcoming-analysis-core.js
  assets/js/shared/recruitment-lineup-core.js
  AnalyzeMatches.htm
  assets/js/pages/dashboard-v2.js
  assets/js/pages/task-control.js
  assets/js/pages/challenge-list-assistant.js
  assets/js/pages/team-points-admin.js
  assets/js/pages/club-intelligence.js
  assets/js/admin/admin-shell.js
  assets/js/admin/admin-session-controller.js
  assets/js/admin/events-showcase-v2122.js
  assets/js/admin/trophy-gallery-v2150-modal-layout.js
  assets/trophy-gallery/trophy-gallery-v2150-mobile.css
  server/team-points-green/tools/audit-club-points.php
  server/team-points-green/public/api.php
  server/team-points-green/sql/core-schema.sql
  server/team-points-green/src/GreenCompatibility.php
  server/team-points-green/src/GreenRepository.php
  server/team-points-green/src/GreenWorker.php
  server/team-points-green/public/cron.php
  server/team-points/src/bootstrap.php
  server/team-points/src/CronMaintenanceCoordinator.php
  server/team-points/src/AnalyticsBuilder.php
  server/team-points/src/FairPlayPriorityRunner.php
  server/team-points/src/FairPlayReconciliationService.php
  server/team-points/src/StorageMetricsService.php
  server/team-points/bin/maintenance.php
  server/team-points/bin/install-analytics-convergence-cron.sh
  server/team-points/bin/analytics-convergence.php
  server/team-points/bin/cutover-blue-maintenance-cron.sh
  server/team-points/src/MiacService.php
  server/team-points/src/MemberInsightsTableService.php
  server/team-points/src/Repository.php
  server/team-points/public/cron.php
  server/team-points/public/player-stats.php
  server/team-points/public/player-cards.php
  server/team-points/public/opponent-icons.php
  server/team-points/public/members-insights.php
  server/team-points/public/members-insights-export.php
  server/team-points/public/intelligence.php
  server/team-points/public/insights-health.php
  server/team-points/bin/post-cutover-audit.php
  server/team-points/public/fair-play-maintenance.php
  server/team-points/sql/core-schema.sql
  server/team-points/sql/core-migration-v2.15.0.sql
  server/tournaments/src/TournamentService.php
  server/tournaments/src/BrowseIndex.php
  server/tournaments/config/config.example.php
  server/shared/MatchAssistantUsage.php
  RecruitMatch.html
  assets/css/recruit-match.css
  assets/js/pages/recruit-match.js
  assets/js/pages/recruit-match-v2121-bootstrap.js
  server/team-points/src/RecruitmentHistoryStore.php
  server/team-points/public/recruitment-history.php
  server/team-points/public/hall-search.php
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

CACHE_KEY="$(python3 "$ROOT/tools/release/static_asset_cache_key.py" key --version 2.15.2 --source-head "$HEAD" --build-id "$BUILD_ID")"
python3 "$ROOT/tools/release/static_asset_cache_key.py" stamp --root "$DIR/payload" --version 2.15.2 --source-head "$HEAD" --build-id "$BUILD_ID" >/dev/null

grep -Fq "$CACHE_KEY" "$DIR/payload/ui-v2.html"
grep -Fq "$CACHE_KEY" "$DIR/payload/ClubIntelligence.html"
grep -Fq "$CACHE_KEY" "$DIR/payload/trophies/index.html"
grep -Fq "$CACHE_KEY" "$DIR/payload/ChallengeListAssistant.html"
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
  "version":"2.15.2",
  "source_head":head,
  "cache_key":cache_key,
  "build_id":build_id,
  "qualification_workflow":"P2K v2.15.2 package",
  "payload_manifest_sha256":manifest_sha,
  "accepted_public_builds":[
    {"version":"2.15.1","source_head":"8c0e52b58efee658d72bf9786a89568cd21af930"},
    {"version":"2.15.2","source_head":"d35bc3bc91d0ab8040dd006b20b24c5e96adcd3c"}
  ]}
Path(out).write_text(json.dumps(payload,indent=2)+"\n")
PY

cp "$ROOT/tools/release/v2152/prepare-candidate-preview-v2.15.2.sh" "$DIR/prepare-candidate-preview-v2.15.2.sh"
chmod +x "$DIR/prepare-candidate-preview-v2.15.2.sh"

cat > "$DIR/README_INSTALL.txt" <<TXT
Promote to King v2.15.2 cumulative incremental candidate package
Source HEAD: $HEAD
Static asset cache key: $CACHE_KEY
Accepted public bases:
- v2.15.1 at 8c0e52b58efee658d72bf9786a89568cd21af930
- v2.15.2 at d35bc3bc91d0ab8040dd006b20b24c5e96adcd3c

Contents:
- Match Recruitment archives the exact completed eligible-player CSV automatically.
- Finished recruitment history is persistent across releases, newest-first and paginated 10 per page.
- Stored recruitment CSVs can be downloaded or explicitly deleted by an administrator.
- The package is cumulative over the qualified v2.15.1 runtime overlay.

Recommended installation:
  cd ~/PromoteToKing
  unzip -q $PKG.zip
  cd $PKG
  bash prepare-candidate-preview-v2.15.2.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing ximoon

This installs and prepares the candidate preview only. It does not promote or deploy the candidate publicly.
You can also upload the ZIP directly in Release Control.
TXT

bash -n "$DIR/prepare-candidate-preview-v2.15.2.sh"
php -l "$DIR/payload/server/team-points-green/tools/audit-club-points.php" >/dev/null

rm -f "$ZIP"
( cd "$OUT" && zip -X -qr "$PKG.zip" "$PKG" )
unzip -t "$ZIP" >/dev/null
sha256sum "$ZIP"
