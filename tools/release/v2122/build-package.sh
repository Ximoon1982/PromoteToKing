#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
OUTPUT=${1:-"$ROOT/build-v2122"}
PACKAGE_NAME="PromoteToKing_v2.12.2_INCREMENTAL_FROM_2.12.x"
PACKAGE="$OUTPUT/$PACKAGE_NAME"
INSTALLER="$ROOT/tools/release/v2122/install-promote-to-king-v2.12.2.sh"
STAMPER="$ROOT/tools/release/v2122/stamp_asset_cache_key.py"
SELECTOR="$ROOT/tools/release/v2122/production_paths.py"
BASE=c52fd68dbcd8fdb7cca8a1ab63ee6dd6da1e0303
BUILD_ID=v2.12.2-cumulative-2.12x-qualified-release-1
HEAD=$(git -C "$ROOT" rev-parse HEAD)
DIGEST=$(python3 - "$HEAD" "$BUILD_ID" <<'PY'
import hashlib,sys
print(hashlib.sha256((sys.argv[1]+'\0'+sys.argv[2]).encode()).hexdigest()[:16])
PY
)
ASSET_KEY="p2k-2.12.2-${HEAD:0:12}-$DIGEST"

rm -rf -- "$OUTPUT"
mkdir -p "$PACKAGE/payload"
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT

# Build a cumulative immutable-production overlay from the qualified v2.12.0
# baseline. The resulting package is valid for existing v2.12.0 or v2.12.1
# installations because every managed file changed anywhere in the 2.12.x line
# is replaced with the qualified v2.12.2 payload.
git -C "$ROOT" diff --name-only --diff-filter=ACMRT "$BASE" "$HEAD" | sort -u >"$work/changed.all"
python3 "$SELECTOR" "$HEAD" >"$work/production.all"
comm -12 "$work/changed.all" "$work/production.all" >"$PACKAGE/FILES.list"
[[ -s "$PACKAGE/FILES.list" ]] || { echo 'v2.12.2 cumulative production scope is empty' >&2; exit 1; }
if git -C "$ROOT" diff --name-status "$BASE" "$HEAD" | grep -Eq '^D'; then
  echo 'v2.12.2 cumulative scope does not permit production deletions' >&2
  exit 1
fi

: >"$PACKAGE/MODES.list"
while IFS= read -r path; do
  git -C "$ROOT" cat-file -e "$HEAD:$path"
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
source_base_2_12_0=$BASE
source_head=$HEAD
build_id=$BUILD_ID
asset_cache_key=$ASSET_KEY
supported_source_versions=2.12.0,2.12.1
payload_scope=$(wc -l <"$PACKAGE/FILES.list") cumulative immutable production files changed since qualified v2.12.0
EOF

(cd "$PACKAGE" && find . -type f ! -name PACKAGE-MANIFEST.sha256 -print0 | sort -z | xargs -0 sha256sum | sed 's#  \./#  #') >"$PACKAGE/PACKAGE-MANIFEST.sha256"
(cd "$OUTPUT" && zip -qr "$PACKAGE_NAME.zip" "$PACKAGE_NAME")
(cd "$OUTPUT" && sha256sum "$PACKAGE_NAME.zip" "$PACKAGE_NAME/install-promote-to-king-v2.12.2.sh" >SHA256SUMS.txt)
printf 'Built %s\nBase: %s\nPayload files: %s\nAsset key: %s\n' "$PACKAGE" "$BASE" "$(wc -l <"$PACKAGE/FILES.list")" "$ASSET_KEY"
