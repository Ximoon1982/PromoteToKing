#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
OUTPUT=${1:-"$ROOT/build-v2122"}
PACKAGE_NAME="PromoteToKing_v2.12.2_INCREMENTAL_FROM_2.12.1"
PACKAGE="$OUTPUT/$PACKAGE_NAME"
INSTALLER="$ROOT/tools/release/v2122/install-promote-to-king-v2.12.2.sh"
STAMPER="$ROOT/tools/release/v2122/stamp_asset_cache_key.py"
SELECTOR="$ROOT/tools/release/v2122/production_paths.py"
BASE=3568e8c36d900fab341c3ded43b97adee3fb6263
BUILD_ID=v2.12.2-incremental-qualified-release-1
HEAD=$(git -C "$ROOT" rev-parse HEAD)
DIGEST=$(python3 - "$HEAD" "$BUILD_ID" <<'PY'
import hashlib,sys
print(hashlib.sha256((sys.argv[1]+'\0'+sys.argv[2]).encode()).hexdigest()[:16])
PY
)
ASSET_KEY="p2k-2.12.2-${HEAD:0:12}-$DIGEST"

# Scope is intentionally explicit. This is an incremental overlay over the exact
# qualified v2.12.1 release, not a full immutable-tree convergence package.
PAYLOAD_PATHS=(
  VERSION
  site-manifest.json
  assets/js/site-config.js
  assets/js/admin/tool-registry.js
  assets/js/admin/events-showcase-v2122.js
  assets/js/admin/trophy-card-presentation-v2122.js
  assets/js/shared/events-showcase-core.js
  assets/js/shared/recruitment-lineup-core.js
  server/events-showcase/public/api.php
  server/events-showcase/public/embed.php
  server/events-showcase/src/EventsShowcaseStore.php
)
OVERWRITE_PATHS=(
  VERSION
  site-manifest.json
  assets/js/site-config.js
  assets/js/admin/tool-registry.js
)
NEW_PATHS=(
  assets/js/admin/events-showcase-v2122.js
  assets/js/admin/trophy-card-presentation-v2122.js
  assets/js/shared/events-showcase-core.js
  assets/js/shared/recruitment-lineup-core.js
  server/events-showcase/public/api.php
  server/events-showcase/public/embed.php
  server/events-showcase/src/EventsShowcaseStore.php
)

rm -rf -- "$OUTPUT"
mkdir -p "$PACKAGE/payload"
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT

printf '%s\n' "${PAYLOAD_PATHS[@]}" | sort >"$PACKAGE/FILES.list"
printf '%s\n' "${NEW_PATHS[@]}" | sort >"$PACKAGE/ABSENT-FILES.list"
: >"$PACKAGE/MODES.list"
: >"$PACKAGE/BASELINE-FILES.sha256"

# Guard the release scope. If another production-managed file changes, package
# construction fails until it is explicitly reviewed and added to the overlay.
git -C "$ROOT" diff --name-only --diff-filter=ACMRT "$BASE" "$HEAD" | sort -u >"$work/changed.all"
python3 "$SELECTOR" "$HEAD" >"$work/production.all"
comm -12 "$work/changed.all" "$work/production.all" >"$work/changed.production"
diff -u "$PACKAGE/FILES.list" "$work/changed.production"
if git -C "$ROOT" diff --name-status "$BASE" "$HEAD" | grep -Eq '^D'; then
  echo 'v2.12.2 incremental scope does not permit production deletions' >&2
  exit 1
fi

for path in "${OVERWRITE_PATHS[@]}"; do
  git -C "$ROOT" cat-file -e "$BASE:$path"
  hash=$(git -C "$ROOT" show "$BASE:$path" | sha256sum | awk '{print $1}')
  printf '%s  %s\n' "$hash" "$path" >>"$PACKAGE/BASELINE-FILES.sha256"
done
sort -o "$PACKAGE/BASELINE-FILES.sha256" "$PACKAGE/BASELINE-FILES.sha256"

for path in "${NEW_PATHS[@]}"; do
  if git -C "$ROOT" cat-file -e "$BASE:$path" 2>/dev/null; then
    echo "expected new v2.12.2 path already exists in v2.12.1 baseline: $path" >&2
    exit 1
  fi
done

while IFS= read -r path; do
  mkdir -p "$PACKAGE/payload/$(dirname "$path")"
  git -C "$ROOT" show "$HEAD:$path" >"$PACKAGE/payload/$path"
  mode=$(git -C "$ROOT" ls-tree "$HEAD" -- "$path" | awk '{print substr($1,4)}')
  printf '%s\t%s\n' "$path" "${mode:-644}" >>"$PACKAGE/MODES.list"
done <"$PACKAGE/FILES.list"

python3 "$STAMPER" "$PACKAGE/payload" "$ASSET_KEY"
install -m755 "$INSTALLER" "$PACKAGE/"
install -m644 "$ROOT/tools/release/v2122/README.md" "$PACKAGE/README.md"
cat >"$PACKAGE/RELEASE-IDENTITY.txt" <<EOF
release=2.12.2
source_base=$BASE
source_head=$HEAD
build_id=$BUILD_ID
asset_cache_key=$ASSET_KEY
payload_scope=11 production files changed/added by v2.12.2 only
EOF

(cd "$PACKAGE" && find . -type f ! -name PACKAGE-MANIFEST.sha256 -print0 | sort -z | xargs -0 sha256sum | sed 's#  \./#  #') >"$PACKAGE/PACKAGE-MANIFEST.sha256"
(cd "$OUTPUT" && zip -qr "$PACKAGE_NAME.zip" "$PACKAGE_NAME")
(cd "$OUTPUT" && sha256sum "$PACKAGE_NAME.zip" "$PACKAGE_NAME/install-promote-to-king-v2.12.2.sh" >SHA256SUMS.txt)
printf 'Built %s\nBase: %s\nPayload files: %s\nAsset key: %s\n' "$PACKAGE" "$BASE" "$(wc -l <"$PACKAGE/FILES.list")" "$ASSET_KEY"
