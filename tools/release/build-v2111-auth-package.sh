#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
BUILD=${1:-"$ROOT/build-v2111"}
PAYLOAD="$BUILD/payload"
INSTALLER="$BUILD/PromoteToKing_v2.11.1_AUTH_SESSION_HARDENING.run"
MARKER=__P2K_V2111_PAYLOAD_BELOW__

rm -rf "$BUILD"
mkdir -p "$PAYLOAD/server/team-points/src"
install -m 0644 "$ROOT/server/team-points/src/OAuthSession.php" "$PAYLOAD/server/team-points/src/OAuthSession.php"
printf 'release=2.11.1\nsource_head=%s\nbaseline=2.11.0-R6\n' "$(git -C "$ROOT" rev-parse HEAD)" > "$PAYLOAD/V2111_SOURCE_HEAD.txt"
(cd "$PAYLOAD" && find . -type f ! -name MANIFEST.sha256 -print0 | sort -z | xargs -0 sha256sum > MANIFEST.sha256)
tar -C "$PAYLOAD" -czf "$BUILD/payload.tar.gz" .
PAYLOAD_SHA=$(sha256sum "$BUILD/payload.tar.gz" | awk '{print $1}')

cat > "$INSTALLER" <<'INSTALLER_HEAD'
#!/usr/bin/env bash
set -euo pipefail
TARGET=${1:?Usage: installer.run TARGET}
MARKER=__P2K_V2111_PAYLOAD_BELOW__
EXPECTED=__PAYLOAD_SHA__
TMP=$(mktemp -d)
BACKUP=$(mktemp -d)
installed=0
cleanup(){ rm -rf "$TMP" "$BACKUP"; }
rollback(){
  if ((installed)); then
    install -m 0644 "$BACKUP/OAuthSession.php" "$TARGET/server/team-points/src/OAuthSession.php"
    rm -f "$TARGET/V2111_SOURCE_HEAD.txt" "$TARGET/MANIFEST.sha256"
  fi
}
trap 'code=$?; rollback; cleanup; exit $code' ERR INT TERM
trap cleanup EXIT
test -f "$TARGET/VERSION"
test "$(tr -d '\r\n[:space:]' < "$TARGET/VERSION")" = 2.11.0
line=$(awk -v marker="$MARKER" '$0==marker {print NR+1; exit}' "$0")
test -n "$line"
tail -n +"$line" "$0" | base64 -d > "$TMP/payload.tar.gz"
test "$(sha256sum "$TMP/payload.tar.gz" | awk '{print $1}')" = "$EXPECTED"
mkdir -p "$TMP/payload"
tar -C "$TMP/payload" -xzf "$TMP/payload.tar.gz"
(cd "$TMP/payload" && sha256sum -c MANIFEST.sha256)
install -m 0644 "$TARGET/server/team-points/src/OAuthSession.php" "$BACKUP/OAuthSession.php"
install -m 0644 "$TMP/payload/server/team-points/src/OAuthSession.php" "$TARGET/server/team-points/src/OAuthSession.php"
installed=1
test "${P2K_FORCE_INSTALL_FAILURE:-0}" != 1
install -m 0644 "$TMP/payload/V2111_SOURCE_HEAD.txt" "$TARGET/V2111_SOURCE_HEAD.txt"
install -m 0644 "$TMP/payload/MANIFEST.sha256" "$TARGET/MANIFEST.sha256"
installed=0
echo 'Promote to King v2.11.1 authentication/session hardening installed.'
exit 0
__P2K_V2111_PAYLOAD_BELOW__
INSTALLER_HEAD
sed -i "s/__PAYLOAD_SHA__/$PAYLOAD_SHA/" "$INSTALLER"
base64 -w 76 "$BUILD/payload.tar.gz" >> "$INSTALLER"
chmod +x "$INSTALLER"

git -C "$ROOT" archive --format=zip --output="$BUILD/PromoteToKing-v2.11.1-source.zip" HEAD
(cd "$BUILD" && sha256sum PromoteToKing_v2.11.1_AUTH_SESSION_HARDENING.run PromoteToKing-v2.11.1-source.zip > SHA256SUMS_v2.11.1.txt)
(cd "$BUILD" && sha256sum -c SHA256SUMS_v2.11.1.txt)
echo "Built v2.11.1 package at $BUILD"
