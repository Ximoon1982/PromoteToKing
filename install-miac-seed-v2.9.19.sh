#!/usr/bin/env bash
# Promote to King v2.9.19 — install immutable MIAC initialization seed.
set -euo pipefail
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SITE_ROOT=${P2K_SITE_ROOT:-$SCRIPT_DIR}
SRC="$SITE_ROOT/resources/miac"
DEST="$SITE_ROOT/data/miac/seed"
EXPECTED_ARCHIVE_SHA='fbb0ad58132a65836e2a2c8c10f9f952dc727f41a668671cba69dc822f1b4310'
for f in "$SRC/seed.zip" "$SRC/miac-seed.json"; do [[ -f "$f" ]] || { echo "ERROR: missing MIAC resource: $f" >&2; exit 3; }; done
GOT=$(sha256sum "$SRC/seed.zip" | awk '{print $1}')
[[ "$GOT" == "$EXPECTED_ARCHIVE_SHA" ]] || { echo "ERROR: MIAC seed archive hash mismatch." >&2; exit 4; }
mkdir -p "$DEST"
chmod 700 "$SITE_ROOT/data/miac" "$DEST" 2>/dev/null || true
printf '%s\n' '<IfModule mod_authz_core.c>' 'Require all denied' '</IfModule>' '<IfModule !mod_authz_core.c>' 'Deny from all' '</IfModule>' > "$SITE_ROOT/data/miac/.htaccess"
chmod 600 "$SITE_ROOT/data/miac/.htaccess" 2>/dev/null || true
install_one(){
  local source=$1 target=$2 tmp expected current
  expected=$(sha256sum "$source" | awk '{print $1}')
  if [[ -f "$target" ]]; then
    current=$(sha256sum "$target" | awk '{print $1}')
    [[ "$current" == "$expected" ]] && { echo "MIAC seed already current: ${target#$SITE_ROOT/}"; return 0; }
  fi
  tmp="$target.tmp.$$"
  cp -p "$source" "$tmp"
  chmod 600 "$tmp" 2>/dev/null || true
  [[ "$(sha256sum "$tmp" | awk '{print $1}')" == "$expected" ]] || { rm -f "$tmp"; echo "ERROR: staged MIAC seed hash mismatch." >&2; exit 5; }
  mv -f "$tmp" "$target"
  echo "Installed MIAC seed: ${target#$SITE_ROOT/}"
}
install_one "$SRC/seed.zip" "$DEST/seed.zip"
install_one "$SRC/miac-seed.json" "$DEST/miac-seed.json"
# Seed metadata is immutable provenance. Runtime confirmations/rejections live outside seed/.
cat > "$DEST/installed.json.tmp.$$" <<EOF
{"format":"p2k-miac-installed-v1","source_archive_sha256":"$EXPECTED_ARCHIVE_SHA","installed_at":"$(date -u +'%Y-%m-%dT%H:%M:%SZ')"}
EOF
chmod 600 "$DEST/installed.json.tmp.$$" 2>/dev/null || true
mv -f "$DEST/installed.json.tmp.$$" "$DEST/installed.json"
echo "MIAC initialization data is available under data/miac/seed; runtime identity state was not modified."
