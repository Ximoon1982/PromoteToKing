#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
OUT="${1:-$ROOT/dist/v2.13.2}"
PKG="PromoteToKing_v2.13.2_INCREMENTAL_FROM_2.13.1"
DIR="$OUT/$PKG"; ZIP="$OUT/$PKG.zip"
TEMPLATE="$ROOT/tools/release/v2132/install-promote-to-king-v2.13.2.sh.in"
BASE="d2b7468f3f660dd7194d402386ce5d24b69ae349"
PREVIOUS_QUALIFIED="081090f923deb8248158aab9709ce9eb29724428"
HEAD="$(git -C "$ROOT" rev-parse HEAD)"
[[ "$(cat "$ROOT/VERSION")" == "2.13.2" ]] || { echo 'VERSION must be 2.13.2' >&2; exit 1; }
git -C "$ROOT" merge-base --is-ancestor "$BASE" "$HEAD" || { echo "HEAD must descend from qualified v2.13.1 $BASE" >&2; exit 1; }
hash_ref(){ git -C "$ROOT" show "$1:$2" | sha256sum | awk '{print $1}'; }
hash_file(){ sha256sum "$ROOT/$1" | awk '{print $1}'; }
rm -rf "$OUT"; mkdir -p "$DIR/payload"
FILES=(VERSION MaxRatingBackfill.php server/team-points-green/sql/core-schema.sql server/team-points-green/src/GreenRepository.php server/team-points-green/public/max-rating-backfill.php server/team-points-green/tools/converge-v2.13.2.php server/team-points/src/Repository.php RecruitMatch.html assets/js/pages/recruit-match-v2121-bootstrap.js assets/js/pages/recruit-match.js)
for path in "${FILES[@]}"; do mkdir -p "$DIR/payload/$(dirname "$path")"; cp -p "$ROOT/$path" "$DIR/payload/$path"; done
VERSION_BASE="$(hash_ref "$BASE" VERSION)"; VERSION_TARGET="$(hash_file VERSION)"
SCHEMA_BASE="$(hash_ref "$BASE" server/team-points-green/sql/core-schema.sql)"; SCHEMA_TARGET="$(hash_file server/team-points-green/sql/core-schema.sql)"
GREEN_BASE="$(hash_ref "$BASE" server/team-points-green/src/GreenRepository.php)"; GREEN_TARGET="$(hash_file server/team-points-green/src/GreenRepository.php)"
REPO_BASE="$(hash_ref "$BASE" server/team-points/src/Repository.php)"; REPO_TARGET="$(hash_file server/team-points/src/Repository.php)"
RECRUIT_HTML_BASE="$(hash_ref "$BASE" RecruitMatch.html)"; RECRUIT_HTML_TARGET="$(hash_file RecruitMatch.html)"
RECRUIT_BOOTSTRAP_BASE="$(hash_ref "$BASE" assets/js/pages/recruit-match-v2121-bootstrap.js)"; RECRUIT_BOOTSTRAP_TARGET="$(hash_file assets/js/pages/recruit-match-v2121-bootstrap.js)"
RECRUIT_CONTROLLER_BASE="$(hash_ref "$BASE" assets/js/pages/recruit-match.js)"; RECRUIT_CONTROLLER_TARGET="$(hash_file assets/js/pages/recruit-match.js)"
PAGE_PREVIOUS="$(hash_ref "$PREVIOUS_QUALIFIED" MaxRatingBackfill.php)"; PAGE_TARGET="$(hash_file MaxRatingBackfill.php)"; ENDPOINT_TARGET="$(hash_file server/team-points-green/public/max-rating-backfill.php)"; CONVERGE_TARGET="$(hash_file server/team-points-green/tools/converge-v2.13.2.php)"
sed -e "s/@@SOURCE_HEAD@@/$HEAD/g" -e "s/@@VERSION_BASE@@/$VERSION_BASE/g" -e "s/@@VERSION_TARGET@@/$VERSION_TARGET/g" -e "s/@@SCHEMA_BASE@@/$SCHEMA_BASE/g" -e "s/@@SCHEMA_TARGET@@/$SCHEMA_TARGET/g" -e "s/@@GREEN_BASE@@/$GREEN_BASE/g" -e "s/@@GREEN_TARGET@@/$GREEN_TARGET/g" -e "s/@@REPO_BASE@@/$REPO_BASE/g" -e "s/@@REPO_TARGET@@/$REPO_TARGET/g" -e "s/@@RECRUIT_HTML_BASE@@/$RECRUIT_HTML_BASE/g" -e "s/@@RECRUIT_HTML_TARGET@@/$RECRUIT_HTML_TARGET/g" -e "s/@@RECRUIT_BOOTSTRAP_BASE@@/$RECRUIT_BOOTSTRAP_BASE/g" -e "s/@@RECRUIT_BOOTSTRAP_TARGET@@/$RECRUIT_BOOTSTRAP_TARGET/g" -e "s/@@RECRUIT_CONTROLLER_BASE@@/$RECRUIT_CONTROLLER_BASE/g" -e "s/@@RECRUIT_CONTROLLER_TARGET@@/$RECRUIT_CONTROLLER_TARGET/g" -e "s/@@PAGE_PREVIOUS@@/$PAGE_PREVIOUS/g" -e "s/@@PAGE_TARGET@@/$PAGE_TARGET/g" -e "s/@@ENDPOINT_TARGET@@/$ENDPOINT_TARGET/g" -e "s/@@CONVERGE_TARGET@@/$CONVERGE_TARGET/g" "$TEMPLATE" > "$DIR/install-promote-to-king-v2.13.2.sh"
chmod +x "$DIR/install-promote-to-king-v2.13.2.sh"
cat > "$DIR/README_INSTALL.txt" <<TXT
Promote to King v2.13.2 incremental installer
Qualified source HEAD: $HEAD
Supported installed states: qualified v2.13.1 $BASE, original qualified v2.13.2 $PREVIOUS_QUALIFIED, the subsequent v2.13.2 backfill correction, or idempotent reinstall of this corrected v2.13.2 payload.
This package also includes the Match Recruitment private-opponent-roster fallback.

Install:
  cd ~/PromoteToKing
  unzip -q $PKG.zip
  cd $PKG
  bash install-promote-to-king-v2.13.2.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing

After install, open the standalone admin-only backfill page:
  https://www.promotetoking.org/MaxRatingBackfill.php
TXT
printf '%s\n' "$HEAD" > "$DIR/SOURCE_HEAD.txt"
( cd "$DIR/payload"; find . -type f -print0 | sort -z | xargs -0 sha256sum ) > "$DIR/PAYLOAD.sha256"
if grep -q '@@' "$DIR/install-promote-to-king-v2.13.2.sh"; then echo 'Unresolved installer template token' >&2; exit 1; fi
bash -n "$DIR/install-promote-to-king-v2.13.2.sh"
rm -f "$ZIP"; ( cd "$OUT"; zip -X -qr "$PKG.zip" "$PKG" )
unzip -t "$ZIP" >/dev/null
sha256sum "$ZIP"
