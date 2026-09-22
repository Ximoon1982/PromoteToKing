#!/usr/bin/env bash
set -Eeuo pipefail
BUILD=${1:?build directory}
SOURCE_HEAD=9c2f08e3f59945ae983f30d6b214f10140dbd345
PACKAGE_NAME=PromoteToKing_v2.13.0_INCREMENTAL_FROM_2.12.x
ZIP="$BUILD/$PACKAGE_NAME.zip"
RUN="$BUILD/PromoteToKing_v2.13.0_INCREMENTAL_FROM_2.12.x_${SOURCE_HEAD:0:8}.run"
[[ -f "$ZIP" ]]
ZIP_SHA=$(sha256sum "$ZIP" | awk '{print $1}')
ZIP_SIZE=$(wc -c <"$ZIP" | tr -d '[:space:]')
cat >"$RUN" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
readonly PAYLOAD_SIZE=$ZIP_SIZE
readonly PAYLOAD_SHA256="$ZIP_SHA"
readonly PACKAGE_NAME="$PACKAGE_NAME"
tmp=""
cleanup(){ [[ -n "\$tmp" && -d "\$tmp" ]] && rm -rf -- "\$tmp" || true; }
trap cleanup EXIT
command -v tail >/dev/null 2>&1 || { echo "ERROR: tail is required" >&2; exit 2; }
command -v sha256sum >/dev/null 2>&1 || { echo "ERROR: sha256sum is required" >&2; exit 2; }
tmp=\$(mktemp -d)
zip="\$tmp/package.zip"
tail -c "\$PAYLOAD_SIZE" "\$0" >"\$zip"
echo "\$PAYLOAD_SHA256  \$zip" | sha256sum -c - >/dev/null || { echo "ERROR: embedded installer payload checksum failed" >&2; exit 2; }
mkdir "\$tmp/unpacked"
if command -v unzip >/dev/null 2>&1; then
  unzip -q "\$zip" -d "\$tmp/unpacked"
elif command -v python3 >/dev/null 2>&1; then
  python3 - "\$zip" "\$tmp/unpacked" <<'PY'
import sys,zipfile
with zipfile.ZipFile(sys.argv[1]) as z:
    z.extractall(sys.argv[2])
PY
else
  echo "ERROR: unzip or python3 is required" >&2
  exit 2
fi
installer="\$tmp/unpacked/\$PACKAGE_NAME/install-promote-to-king-v2.13.0.sh"
[[ -f "\$installer" ]] || { echo "ERROR: embedded installer is incomplete" >&2; exit 2; }
bash "\$installer" "\$@"
status=\$?
exit "\$status"
EOF
cat "$ZIP" >>"$RUN"
chmod 755 "$RUN"
RUN_SHA=$(sha256sum "$RUN" | awk '{print $1}')
printf '%s  %s\n' "$RUN_SHA" "$(basename "$RUN")" >"$BUILD/STANDALONE-SHA256SUM.txt"
cat >"$BUILD/INSTALL-v2.13.0.txt" <<EOF
Promote to King v2.13.0 cumulative incremental installer
Qualified source: $SOURCE_HEAD

Supported installed versions: 2.12.0, 2.12.1, 2.12.2.
Re-running on 2.13.0 is supported for verification/self-heal.

Upload $(basename "$RUN") to:
/kunden/homepages/43/d141198007/htdocs/PromoteToKing/

Then run:

cd /kunden/homepages/43/d141198007/htdocs/PromoteToKing
echo "$RUN_SHA  $(basename "$RUN")" | sha256sum -c -
bash ./$(basename "$RUN") "$PWD"

Post-install verification:

bash ./$(basename "$RUN") "$PWD" verify

The installer is transactional and preserves mutable state, unrelated files and system CRON.
EOF
printf 'Built standalone %s\nSHA-256: %s\nEmbedded ZIP SHA-256: %s\n' "$RUN" "$RUN_SHA" "$ZIP_SHA"
