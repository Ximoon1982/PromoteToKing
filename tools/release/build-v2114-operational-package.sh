#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
BUILD=${1:-"$ROOT/build-v2114"}
PAYLOAD="$BUILD/payload"
INSTALLER="$BUILD/PromoteToKing_v2.11.4_OPERATIONAL_ROBUSTNESS.run"
LAUNCHER="$BUILD/install-promote-to-king-2.11.4.sh"
SOURCE_HEAD=$(git -C "$ROOT" rev-parse HEAD)
BUILD_ID=${P2K_BUILD_ID:-local-$(date -u +%Y%m%dT%H%M%S%N)-$$}
CACHE_KEY=$(python3 "$ROOT/tools/release/static_asset_cache_key.py" key --version 2.11.4 --source-head "$SOURCE_HEAD" --build-id "$BUILD_ID")
REQUIRED_CACHE_ASSETS=(admin-shell.js admin-session-controller.js tool-registry.js match-assistant.js dashboard-v2.js api-client.js)
FILES=(
  AnalyzeMatch.html
  AnalyzeMatchModal.html
  AnalyzeMatches.htm
  ChallengeListAssistant.html
  DataReconciliation.html
  FindMatch.htm
  MatchCreationAnalyzer.htm
  RecruitMatch.html
  RecruitmentAdmin.html
  RecruitmentDemandPlanner.html
  TaskControl.html
  TeamPointsAdmin.html
  TeamPointsMigration.html
  TournamentAchievementBadgesDemo.html
  TournamentManagement.html
  index.html
  ui-v2.html
  assets/js/shared/api-client.js
  assets/js/shared/api-request-semantics.js
  assets/js/shared/api-oauth-context.js
  assets/js/shared/api-transport.js
  assets/js/shared/api-request-coordinator.js
  assets/js/pages/admin-features.js
  assets/js/pages/dashboard-v2.js
  assets/js/admin/admin-runtime.js
  assets/js/admin/admin-session-controller.js
  assets/js/admin/admin-shell.js
  assets/js/admin/diagnostics-controller.js
  assets/js/admin/embedded-detail-host.js
  assets/js/admin/history-controller.js
  assets/js/admin/logs-controller.js
  assets/js/admin/match-management.js
  assets/js/admin/recording-controller.js
  assets/js/admin/tool-registry.js
  assets/js/dashboard/dashboard-bootstrap.js
  assets/js/dashboard/insights-controller.js
  assets/js/dashboard/match-assistant.js
  assets/js/dashboard/match-list-dialog.js
  assets/js/dashboard/personal-home.js
  assets/js/dashboard/team-summary.js
  server/team-points/src/AchievementArtwork.php
  server/team-points/src/AchievementCatalog.php
  server/team-points/src/AnalyticsBuilder.php
  server/team-points/src/AnalyticsRefreshRuntime.php
  server/team-points/src/ClubIntelligenceService.php
  server/team-points/src/SqlReadGateway.php
  server/team-points/src/AdminJob/JobCheckpointStore.php
  server/team-points/src/AdminJob/JobRunner.php
  server/team-points/src/AdminJob/JobStateReader.php
  server/team-points/src/AdminJob/JobState.php
  server/team-points/src/AdminJob/JobTelemetry.php
  server/team-points/src/AdminJob/RecruitmentRunStateReader.php
  server/team-points/src/InternalErrorCategory.php
  server/team-points/src/OAuthSession.php
  server/team-points/public/recruitment-admin.php
  server/team-points-green/public/api.php
  server/team-points-green/src/GreenAnalyticsBootstrap.php
  server/team-points-green/src/GreenCompatibility.php
  server/team-points-green/src/GreenRepository.php
  server/team-points-green/src/GreenWorker.php
)

rm -rf "$BUILD"
mkdir -p "$PAYLOAD/server/team-points/src"
for file in "${FILES[@]}"; do mkdir -p "$PAYLOAD/$(dirname "$file")"; install -m 0644 "$ROOT/$file" "$PAYLOAD/$file"; done
python3 "$ROOT/tools/release/static_asset_cache_key.py" stamp --root "$PAYLOAD" --version 2.11.4 --source-head "$SOURCE_HEAD" --build-id "$BUILD_ID" >/dev/null
VERIFY_ARGS=(); for asset in "${REQUIRED_CACHE_ASSETS[@]}"; do VERIFY_ARGS+=(--require-basename "$asset"); done
python3 "$ROOT/tools/release/static_asset_cache_key.py" verify --root "$PAYLOAD" --version 2.11.4 --source-head "$SOURCE_HEAD" --build-id "$BUILD_ID" "${VERIFY_ARGS[@]}" >/dev/null
printf 'release=2.11.4\nsource_head=%s\nsupported_baseline=2.11.x\n' "$SOURCE_HEAD" > "$PAYLOAD/V2114_SOURCE_HEAD.txt"
printf 'release=2.11.4\nsource_head=%s\nbuild_id=%s\ncache_key=%s\n' "$SOURCE_HEAD" "$BUILD_ID" "$CACHE_KEY" > "$PAYLOAD/STATIC_ASSET_CACHE_KEY.txt"
(cd "$PAYLOAD" && find . -type f ! -name MANIFEST.sha256 -print0 | sort -z | xargs -0 sha256sum > MANIFEST.sha256)
tar -C "$PAYLOAD" -czf "$BUILD/payload.tar.gz" .
PAYLOAD_SHA=$(sha256sum "$BUILD/payload.tar.gz" | awk '{print $1}')

cat > "$INSTALLER" <<'INSTALLER_HEAD'
#!/usr/bin/env bash
set -euo pipefail
TARGET=${1:?Usage: installer.run TARGET}
MARKER=__P2K_V2114_PAYLOAD_BELOW__
EXPECTED=__PAYLOAD_SHA__
FILES=(AnalyzeMatch.html AnalyzeMatchModal.html AnalyzeMatches.htm ChallengeListAssistant.html DataReconciliation.html FindMatch.htm MatchCreationAnalyzer.htm RecruitMatch.html RecruitmentAdmin.html RecruitmentDemandPlanner.html TaskControl.html TeamPointsAdmin.html TeamPointsMigration.html TournamentAchievementBadgesDemo.html TournamentManagement.html index.html ui-v2.html assets/js/shared/api-client.js assets/js/shared/api-request-semantics.js assets/js/shared/api-oauth-context.js assets/js/shared/api-transport.js assets/js/shared/api-request-coordinator.js assets/js/pages/admin-features.js assets/js/pages/dashboard-v2.js assets/js/admin/admin-runtime.js assets/js/admin/admin-session-controller.js assets/js/admin/admin-shell.js assets/js/admin/diagnostics-controller.js assets/js/admin/embedded-detail-host.js assets/js/admin/history-controller.js assets/js/admin/logs-controller.js assets/js/admin/match-management.js assets/js/admin/recording-controller.js assets/js/admin/tool-registry.js assets/js/dashboard/dashboard-bootstrap.js assets/js/dashboard/insights-controller.js assets/js/dashboard/match-assistant.js assets/js/dashboard/match-list-dialog.js assets/js/dashboard/personal-home.js assets/js/dashboard/team-summary.js server/team-points/src/AchievementArtwork.php server/team-points/src/AchievementCatalog.php server/team-points/src/AnalyticsBuilder.php server/team-points/src/AnalyticsRefreshRuntime.php server/team-points/src/ClubIntelligenceService.php server/team-points/src/SqlReadGateway.php server/team-points/src/AdminJob/JobCheckpointStore.php server/team-points/src/AdminJob/JobRunner.php server/team-points/src/AdminJob/JobStateReader.php server/team-points/src/AdminJob/JobState.php server/team-points/src/AdminJob/JobTelemetry.php server/team-points/src/AdminJob/RecruitmentRunStateReader.php server/team-points/src/InternalErrorCategory.php server/team-points/src/OAuthSession.php server/team-points/public/recruitment-admin.php server/team-points-green/public/api.php server/team-points-green/src/GreenAnalyticsBootstrap.php server/team-points-green/src/GreenCompatibility.php server/team-points-green/src/GreenRepository.php server/team-points-green/src/GreenWorker.php)
METADATA=(V2114_SOURCE_HEAD.txt STATIC_ASSET_CACHE_KEY.txt MANIFEST_v2.11.4.sha256)
MANAGED=("${FILES[@]}" "${METADATA[@]}")
TMP=$(mktemp -d)
BACKUP=$(mktemp -d)
transaction=0
CRONTAB_BIN=$(command -v crontab || true)
if test -n "$CRONTAB_BIN"; then "$CRONTAB_BIN" -l > "$BACKUP/crontab.before" 2>/dev/null || : > "$BACKUP/crontab.before"; fi
cleanup(){ rm -rf "$TMP" "$BACKUP"; }
rollback(){
  if ((transaction)); then
    for file in "${MANAGED[@]}"; do rm -f "$TARGET/$file"; done
    if test -s "$BACKUP/existing.tar"; then tar -C "$TARGET" -xf "$BACKUP/existing.tar"; fi
  fi
}
trap 'code=$?; rollback; cleanup; exit $code' ERR INT TERM
trap cleanup EXIT
test -f "$TARGET/VERSION"
INSTALLED=$(tr -d '\r\n[:space:]' < "$TARGET/VERSION")
case "$INSTALLED" in 2.11|2.11.*) ;; *) echo "ERROR: v2.11.4 requires an installed Promote to King 2.11.x tree; found '$INSTALLED'." >&2; exit 2;; esac
echo "Installing Promote to King v2.11.4 operational robustness and migration closure over v$INSTALLED."
echo "Application behavior, UI, data, authentication and CRON configuration will be preserved."
line=$(awk -v marker="$MARKER" '$0==marker {print NR+1; exit}' "$0")
tail -n +"$line" "$0" | base64 -d > "$TMP/payload.tar.gz"
test "$(sha256sum "$TMP/payload.tar.gz" | awk '{print $1}')" = "$EXPECTED"
mkdir -p "$TMP/payload"
tar -C "$TMP/payload" -xzf "$TMP/payload.tar.gz"
(cd "$TMP/payload" && sha256sum -c MANIFEST.sha256)
existing=(); for file in "${MANAGED[@]}"; do test ! -f "$TARGET/$file" || existing+=("$file"); done
if ((${#existing[@]})); then tar -C "$TARGET" -cf "$BACKUP/existing.tar" "${existing[@]}"; else : > "$BACKUP/existing.tar"; fi
transaction=1
for file in "${FILES[@]}"; do mkdir -p "$TARGET/$(dirname "$file")"; install -m 0644 "$TMP/payload/$file" "$TARGET/$file"; done
test "${P2K_FORCE_INSTALL_FAILURE:-0}" != 1
install -m 0644 "$TMP/payload/V2114_SOURCE_HEAD.txt" "$TARGET/V2114_SOURCE_HEAD.txt"
install -m 0644 "$TMP/payload/STATIC_ASSET_CACHE_KEY.txt" "$TARGET/STATIC_ASSET_CACHE_KEY.txt"
install -m 0644 "$TMP/payload/MANIFEST.sha256" "$TARGET/MANIFEST_v2.11.4.sha256"
if test -n "$CRONTAB_BIN"; then
  "$CRONTAB_BIN" -l > "$BACKUP/crontab.after" 2>/dev/null || : > "$BACKUP/crontab.after"
  cmp -s "$BACKUP/crontab.before" "$BACKUP/crontab.after" || { echo 'ERROR: CRON entries changed unexpectedly.' >&2; false; }
fi
transaction=0
echo 'Promote to King v2.11.4 operational robustness and migration closure installed.'
echo 'Existing CRON entries were preserved unchanged.'
exit 0
__P2K_V2114_PAYLOAD_BELOW__
INSTALLER_HEAD
sed -i "s/__PAYLOAD_SHA__/$PAYLOAD_SHA/" "$INSTALLER"
base64 -w 76 "$BUILD/payload.tar.gz" >> "$INSTALLER"
chmod +x "$INSTALLER"
cat > "$LAUNCHER" <<'LAUNCHER_BODY'
#!/usr/bin/env bash
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
TARGET=${1:-${P2K_SITE_ROOT:-}}
if test -z "$TARGET"; then
  echo "Usage: $0 /absolute/path/to/promote-to-king" >&2
  echo "Or set P2K_SITE_ROOT and run: $0" >&2
  exit 2
fi
test -d "$TARGET" || { echo "ERROR: target directory does not exist: $TARGET" >&2; exit 2; }
exec "$HERE/PromoteToKing_v2.11.4_OPERATIONAL_ROBUSTNESS.run" "$TARGET"
LAUNCHER_BODY
chmod +x "$LAUNCHER"
cat > "$BUILD/INSTALL_v2.11.4.txt" <<'INSTRUCTIONS'
Promote to King v2.11.4 — Operational Robustness & Migration Closure

Requirements
  - An existing Promote to King 2.11.x installation.
  - Bash plus standard tar, base64 and sha256sum utilities.
  - Write access to the Promote to King installation directory.

Recommended command
  ./install-promote-to-king-2.11.4.sh /absolute/path/to/promote-to-king

Environment-variable form
  P2K_SITE_ROOT=/absolute/path/to/promote-to-king ./install-promote-to-king-2.11.4.sh

Direct installer form
  ./PromoteToKing_v2.11.4_OPERATIONAL_ROBUSTNESS.run /absolute/path/to/promote-to-king

The installer validates the embedded payload and installed VERSION, applies the
cumulative v2.11.3 runtime consolidation plus focused v2.11.4 operational-hardening
files, preserves existing CRON entries, and rolls back all changed/new files if
validation or installation fails. It does not alter configuration, runtime data,
schemas, visual styles/assets, authentication settings or scheduled jobs.
INSTRUCTIONS
mkdir -p "$BUILD/source"
git -C "$ROOT" archive HEAD | tar -x -C "$BUILD/source"
python3 "$ROOT/tools/release/static_asset_cache_key.py" stamp --root "$BUILD/source" --version 2.11.4 --source-head "$SOURCE_HEAD" --build-id "$BUILD_ID" >/dev/null
python3 "$ROOT/tools/release/static_asset_cache_key.py" verify --root "$BUILD/source" --version 2.11.4 --source-head "$SOURCE_HEAD" --build-id "$BUILD_ID" "${VERIFY_ARGS[@]}" >/dev/null
install -m 0644 "$PAYLOAD/STATIC_ASSET_CACHE_KEY.txt" "$BUILD/source/STATIC_ASSET_CACHE_KEY.txt"
(cd "$BUILD/source" && zip -qr "$BUILD/PromoteToKing-v2.11.4-source.zip" .)
(cd "$BUILD" && sha256sum PromoteToKing_v2.11.4_OPERATIONAL_ROBUSTNESS.run install-promote-to-king-2.11.4.sh INSTALL_v2.11.4.txt PromoteToKing-v2.11.4-source.zip > SHA256SUMS_v2.11.4.txt)
(cd "$BUILD" && sha256sum -c SHA256SUMS_v2.11.4.txt)
