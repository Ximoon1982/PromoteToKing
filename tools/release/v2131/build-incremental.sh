#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
OUT="${1:-$ROOT/dist/v2.13.1}"
PKG="PromoteToKing_v2.13.1_INCREMENTAL_FROM_2.13.0"
DIR="$OUT/$PKG"
ZIP="$OUT/$PKG.zip"
TEMPLATE="$ROOT/tools/release/v2131/install-promote-to-king-v2.13.1.sh.in"

BASE_9C2="9c2f08e3f59945ae983f30d6b214f10140dbd345"
BASE_1C17="1c17ca07fdf2b20ac872ff24fb92d87750f6938b"
BASE_385="385faccdbf18725b4487144b8a7cf3e2630ed1b1"
HEAD="$(git -C "$ROOT" rev-parse HEAD)"

[[ "$(cat "$ROOT/VERSION")" == "2.13.1" ]] || { echo "VERSION must be 2.13.1" >&2; exit 1; }
git -C "$ROOT" merge-base --is-ancestor "$BASE_385" "$HEAD" || { echo "HEAD must descend from qualified v2.13.0 $BASE_385" >&2; exit 1; }

hash_ref() {
  local ref="$1" path="$2"
  git -C "$ROOT" show "$ref:$path" | sha256sum | awk '{print $1}'
}
hash_file() { sha256sum "$ROOT/$1" | awk '{print $1}'; }

rm -rf "$OUT"
mkdir -p "$DIR/payload"

FILES=(
  "VERSION"
  "server/team-points/sql/analytics-schema.sql"
  "server/team-points-green/src/GreenRepository.php"
  "server/team-points-green/src/GreenCompatibility.php"
  "server/team-points/src/McaResultsCronService.php"
  "server/team-points-green/tools/converge-v2.13.1.php"
)
for path in "${FILES[@]}"; do
  mkdir -p "$DIR/payload/$(dirname "$path")"
  cp -p "$ROOT/$path" "$DIR/payload/$path"
done

VERSION_BASE="$(hash_ref "$BASE_385" VERSION)"
VERSION_TARGET="$(hash_file VERSION)"
ANALYTICS_9C2="$(hash_ref "$BASE_9C2" server/team-points/sql/analytics-schema.sql)"
ANALYTICS_TARGET="$(hash_file server/team-points/sql/analytics-schema.sql)"
GREEN_REPO_BASE="$(hash_ref "$BASE_385" server/team-points-green/src/GreenRepository.php)"
GREEN_REPO_TARGET="$(hash_file server/team-points-green/src/GreenRepository.php)"
GREEN_COMPAT_BASE="$(hash_ref "$BASE_385" server/team-points-green/src/GreenCompatibility.php)"
GREEN_COMPAT_TARGET="$(hash_file server/team-points-green/src/GreenCompatibility.php)"
MCA_9C2="$(hash_ref "$BASE_9C2" server/team-points/src/McaResultsCronService.php)"
MCA_1C17="$(hash_ref "$BASE_1C17" server/team-points/src/McaResultsCronService.php)"
MCA_385="$(hash_ref "$BASE_385" server/team-points/src/McaResultsCronService.php)"
MCA_TARGET="$(hash_file server/team-points/src/McaResultsCronService.php)"
CONVERGE_TARGET="$(hash_file server/team-points-green/tools/converge-v2.13.1.php)"

sed \
  -e "s/@@SOURCE_HEAD@@/$HEAD/g" \
  -e "s/@@VERSION_BASE@@/$VERSION_BASE/g" \
  -e "s/@@VERSION_TARGET@@/$VERSION_TARGET/g" \
  -e "s/@@ANALYTICS_9C2@@/$ANALYTICS_9C2/g" \
  -e "s/@@ANALYTICS_TARGET@@/$ANALYTICS_TARGET/g" \
  -e "s/@@GREEN_REPO_BASE@@/$GREEN_REPO_BASE/g" \
  -e "s/@@GREEN_REPO_TARGET@@/$GREEN_REPO_TARGET/g" \
  -e "s/@@GREEN_COMPAT_BASE@@/$GREEN_COMPAT_BASE/g" \
  -e "s/@@GREEN_COMPAT_TARGET@@/$GREEN_COMPAT_TARGET/g" \
  -e "s/@@MCA_9C2@@/$MCA_9C2/g" \
  -e "s/@@MCA_1C17@@/$MCA_1C17/g" \
  -e "s/@@MCA_385@@/$MCA_385/g" \
  -e "s/@@MCA_TARGET@@/$MCA_TARGET/g" \
  -e "s/@@CONVERGE_TARGET@@/$CONVERGE_TARGET/g" \
  "$TEMPLATE" > "$DIR/install-promote-to-king-v2.13.1.sh"
chmod +x "$DIR/install-promote-to-king-v2.13.1.sh"

cat > "$DIR/README_INSTALL.txt" <<EOF
Promote to King v2.13.1 incremental installer
Qualified source HEAD: $HEAD

Supported v2.13.0 source lineage:
- $BASE_9C2
- $BASE_1C17
- $BASE_385
- idempotent reinstall of this exact v2.13.1 payload

The installer hash-checks every replaced file, refuses unknown local modifications,
uses PHP >= 8 (including IONOS /usr/bin/php8.5-cli), backs up replaced files,
activates exact qualified source, runs Green physical schema convergence/verification,
and rolls source files back if activation or convergence fails.

Install:
  cd ~/PromoteToKing
  unzip -q $PKG.zip
  cd $PKG
  bash install-promote-to-king-v2.13.1.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing
EOF

printf '%s\n' "$HEAD" > "$DIR/SOURCE_HEAD.txt"
(
  cd "$DIR/payload"
  find . -type f -print0 | sort -z | xargs -0 sha256sum
) > "$DIR/PAYLOAD.sha256"

if grep -q '@@' "$DIR/install-promote-to-king-v2.13.1.sh"; then
  echo "Unresolved installer template token" >&2
  exit 1
fi
bash -n "$DIR/install-promote-to-king-v2.13.1.sh"
command -v zip >/dev/null
rm -f "$ZIP"
( cd "$OUT" && zip -X -qr "$PKG.zip" "$PKG" )
unzip -t "$ZIP" >/dev/null
sha256sum "$ZIP"
