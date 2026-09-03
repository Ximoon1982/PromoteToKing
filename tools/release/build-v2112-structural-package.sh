#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
BUILD=${1:-"$ROOT/build-v2112"}
PAYLOAD="$BUILD/payload"
INSTALLER="$BUILD/PromoteToKing_v2.11.2_STRUCTURAL_CONSOLIDATION.run"
LAUNCHER="$BUILD/install-promote-to-king-2.11.2.sh"
FILES=(
  index.html
  ui-v2.html
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
  server/team-points/src/AdminJob/JobState.php
  server/team-points/src/AdminJob/JobTelemetry.php
  server/team-points/src/InternalErrorCategory.php
)

rm -rf "$BUILD"
mkdir -p "$PAYLOAD/server/team-points/src"
for file in "${FILES[@]}"; do mkdir -p "$PAYLOAD/$(dirname "$file")"; install -m 0644 "$ROOT/$file" "$PAYLOAD/$file"; done
printf 'release=2.11.2\nsource_head=%s\nbaseline=2.11.1\n' "$(git -C "$ROOT" rev-parse HEAD)" > "$PAYLOAD/V2112_SOURCE_HEAD.txt"
(cd "$PAYLOAD" && find . -type f ! -name MANIFEST.sha256 -print0 | sort -z | xargs -0 sha256sum > MANIFEST.sha256)
tar -C "$PAYLOAD" -czf "$BUILD/payload.tar.gz" .
PAYLOAD_SHA=$(sha256sum "$BUILD/payload.tar.gz" | awk '{print $1}')

cat > "$INSTALLER" <<'INSTALLER_HEAD'
#!/usr/bin/env bash
set -euo pipefail
TARGET=${1:?Usage: installer.run TARGET}
MARKER=__P2K_V2112_PAYLOAD_BELOW__
EXPECTED=__PAYLOAD_SHA__
FILES=(index.html ui-v2.html assets/js/pages/admin-features.js assets/js/pages/dashboard-v2.js assets/js/admin/admin-runtime.js assets/js/admin/admin-session-controller.js assets/js/admin/admin-shell.js assets/js/admin/diagnostics-controller.js assets/js/admin/embedded-detail-host.js assets/js/admin/history-controller.js assets/js/admin/logs-controller.js assets/js/admin/match-management.js assets/js/admin/recording-controller.js assets/js/admin/tool-registry.js assets/js/dashboard/dashboard-bootstrap.js assets/js/dashboard/insights-controller.js assets/js/dashboard/match-assistant.js assets/js/dashboard/match-list-dialog.js assets/js/dashboard/personal-home.js assets/js/dashboard/team-summary.js server/team-points/src/AchievementArtwork.php server/team-points/src/AchievementCatalog.php server/team-points/src/AnalyticsBuilder.php server/team-points/src/AnalyticsRefreshRuntime.php server/team-points/src/ClubIntelligenceService.php server/team-points/src/SqlReadGateway.php server/team-points/src/AdminJob/JobCheckpointStore.php server/team-points/src/AdminJob/JobRunner.php server/team-points/src/AdminJob/JobState.php server/team-points/src/AdminJob/JobTelemetry.php server/team-points/src/InternalErrorCategory.php)
METADATA=(V2112_SOURCE_HEAD.txt MANIFEST_v2.11.2.sha256)
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
case "$INSTALLED" in 2.11|2.11.*) ;; *) echo "ERROR: v2.11.2 requires an installed Promote to King 2.11.x tree; found '$INSTALLED'." >&2; exit 2;; esac
echo "Installing Promote to King v2.11.2 structural consolidation over v$INSTALLED."
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
install -m 0644 "$TMP/payload/V2112_SOURCE_HEAD.txt" "$TARGET/V2112_SOURCE_HEAD.txt"
install -m 0644 "$TMP/payload/MANIFEST.sha256" "$TARGET/MANIFEST_v2.11.2.sha256"
if test -n "$CRONTAB_BIN"; then
  "$CRONTAB_BIN" -l > "$BACKUP/crontab.after" 2>/dev/null || : > "$BACKUP/crontab.after"
  cmp -s "$BACKUP/crontab.before" "$BACKUP/crontab.after" || { echo 'ERROR: CRON entries changed unexpectedly.' >&2; false; }
fi
transaction=0
echo 'Promote to King v2.11.2 structural consolidation installed.'
echo 'Existing CRON entries were preserved unchanged.'
exit 0
__P2K_V2112_PAYLOAD_BELOW__
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
exec "$HERE/PromoteToKing_v2.11.2_STRUCTURAL_CONSOLIDATION.run" "$TARGET"
LAUNCHER_BODY
chmod +x "$LAUNCHER"
cat > "$BUILD/INSTALL_v2.11.2.txt" <<'INSTRUCTIONS'
Promote to King v2.11.2 — Structural Consolidation & Maintainability

Requirements
  - An existing Promote to King 2.11.x installation.
  - Bash plus standard tar, base64 and sha256sum utilities.
  - Write access to the Promote to King installation directory.

Recommended command
  ./install-promote-to-king-2.11.2.sh /absolute/path/to/promote-to-king

Environment-variable form
  P2K_SITE_ROOT=/absolute/path/to/promote-to-king ./install-promote-to-king-2.11.2.sh

Direct installer form
  ./PromoteToKing_v2.11.2_STRUCTURAL_CONSOLIDATION.run /absolute/path/to/promote-to-king

The installer validates the embedded payload and installed VERSION, applies only the
v2.11.2 structural runtime files, preserves existing CRON entries, and rolls back all
changed/new files if validation or installation fails. It does not alter configuration,
runtime data, schemas, visual styles/assets, authentication settings or scheduled jobs.
INSTRUCTIONS
git -C "$ROOT" archive --format=zip --output="$BUILD/PromoteToKing-v2.11.2-source.zip" HEAD
(cd "$BUILD" && sha256sum PromoteToKing_v2.11.2_STRUCTURAL_CONSOLIDATION.run install-promote-to-king-2.11.2.sh INSTALL_v2.11.2.txt PromoteToKing-v2.11.2-source.zip > SHA256SUMS_v2.11.2.txt)
(cd "$BUILD" && sha256sum -c SHA256SUMS_v2.11.2.txt)
