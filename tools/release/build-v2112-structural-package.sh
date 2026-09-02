#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
BUILD=${1:-"$ROOT/build-v2112"}
PAYLOAD="$BUILD/payload"
INSTALLER="$BUILD/PromoteToKing_v2.11.2_STRUCTURAL_CONSOLIDATION.run"
FILES=(
  server/team-points/src/AchievementArtwork.php
  server/team-points/src/AchievementCatalog.php
  server/team-points/src/AnalyticsBuilder.php
  server/team-points/src/AnalyticsRefreshRuntime.php
  server/team-points/src/ClubIntelligenceService.php
  server/team-points/src/SqlReadGateway.php
)

rm -rf "$BUILD"
mkdir -p "$PAYLOAD/server/team-points/src"
for file in "${FILES[@]}"; do install -m 0644 "$ROOT/$file" "$PAYLOAD/$file"; done
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
FILES=(server/team-points/src/AchievementArtwork.php server/team-points/src/AchievementCatalog.php server/team-points/src/AnalyticsBuilder.php server/team-points/src/AnalyticsRefreshRuntime.php server/team-points/src/ClubIntelligenceService.php server/team-points/src/SqlReadGateway.php)
TMP=$(mktemp -d)
BACKUP=$(mktemp -d)
transaction=0
cleanup(){ rm -rf "$TMP" "$BACKUP"; }
rollback(){
  if ((transaction)); then
    for file in "${FILES[@]}"; do rm -f "$TARGET/$file"; done
    if test -s "$BACKUP/existing.tar"; then tar -C "$TARGET" -xf "$BACKUP/existing.tar"; fi
    rm -f "$TARGET/V2112_SOURCE_HEAD.txt" "$TARGET/MANIFEST_v2.11.2.sha256"
  fi
}
trap 'code=$?; rollback; cleanup; exit $code' ERR INT TERM
trap cleanup EXIT
test -f "$TARGET/VERSION"
test "$(tr -d '\r\n[:space:]' < "$TARGET/VERSION")" = 2.11.0
line=$(awk -v marker="$MARKER" '$0==marker {print NR+1; exit}' "$0")
tail -n +"$line" "$0" | base64 -d > "$TMP/payload.tar.gz"
test "$(sha256sum "$TMP/payload.tar.gz" | awk '{print $1}')" = "$EXPECTED"
mkdir -p "$TMP/payload"
tar -C "$TMP/payload" -xzf "$TMP/payload.tar.gz"
(cd "$TMP/payload" && sha256sum -c MANIFEST.sha256)
existing=(); for file in "${FILES[@]}"; do test ! -f "$TARGET/$file" || existing+=("$file"); done
if ((${#existing[@]})); then tar -C "$TARGET" -cf "$BACKUP/existing.tar" "${existing[@]}"; else : > "$BACKUP/existing.tar"; fi
transaction=1
for file in "${FILES[@]}"; do install -m 0644 "$TMP/payload/$file" "$TARGET/$file"; done
test "${P2K_FORCE_INSTALL_FAILURE:-0}" != 1
install -m 0644 "$TMP/payload/V2112_SOURCE_HEAD.txt" "$TARGET/V2112_SOURCE_HEAD.txt"
install -m 0644 "$TMP/payload/MANIFEST.sha256" "$TARGET/MANIFEST_v2.11.2.sha256"
transaction=0
echo 'Promote to King v2.11.2 structural consolidation installed.'
exit 0
__P2K_V2112_PAYLOAD_BELOW__
INSTALLER_HEAD
sed -i "s/__PAYLOAD_SHA__/$PAYLOAD_SHA/" "$INSTALLER"
base64 -w 76 "$BUILD/payload.tar.gz" >> "$INSTALLER"
chmod +x "$INSTALLER"
git -C "$ROOT" archive --format=zip --output="$BUILD/PromoteToKing-v2.11.2-source.zip" HEAD
(cd "$BUILD" && sha256sum PromoteToKing_v2.11.2_STRUCTURAL_CONSOLIDATION.run PromoteToKing-v2.11.2-source.zip > SHA256SUMS_v2.11.2.txt)
(cd "$BUILD" && sha256sum -c SHA256SUMS_v2.11.2.txt)
