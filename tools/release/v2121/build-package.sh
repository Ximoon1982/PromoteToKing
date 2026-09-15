#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
OUTPUT=${1:-"$ROOT/build-v2121"}
PACKAGE="$OUTPUT/PromoteToKing_v2.12.1_INCREMENTAL"
SELECTOR="$ROOT/tools/release/v2121/production_paths.py"
INSTALLER="$ROOT/tools/release/v2121/install-promote-to-king-v2.12.1.sh"
STAMPER="$ROOT/tools/release/v2121/stamp_asset_cache_key.py"
BUILD_ID=v2.12.1-qualified-release-1
HEAD=$(git -C "$ROOT" rev-parse HEAD)
DIGEST=$(python3 - "$HEAD" "$BUILD_ID" <<'PY2'
import hashlib,sys
print(hashlib.sha256((sys.argv[1]+'\0'+sys.argv[2]).encode()).hexdigest()[:16])
PY2
)
ASSET_KEY="p2k-2.12.1-${HEAD:0:12}-$DIGEST"
BASELINES=(
 "2.11.0:2ca1fc191aeef444b4886b53e25a54a83820c25c"
 "2.11.1:b8bf26c7c41ca1914323717766bca995139291aa"
 "2.11.2:4ececcc230ca07099b346cb47396ad00bedd5c21"
 "2.11.3:dcd71c8e76c07defacf6270aff4224b10484968b"
 "2.11.4:6706e619d310e2c74fe2734cfcef8dd2f83d70d1"
 "2.11.5:c534b2dbb0346eac0fa6de869621d6b7d785ead8"
 "2.12.0-c52:c52fd68dbcd8fdb7cca8a1ab63ee6dd6da1e0303"
 "2.12.0-0869:0869b3cd76df87ab20f51643c2d664f75e5f103f"
)
rm -rf -- "$OUTPUT"; mkdir -p "$PACKAGE/payload"
python3 "$SELECTOR" HEAD >"$PACKAGE/FILES.list"
while IFS= read -r path; do
 mkdir -p "$PACKAGE/payload/$(dirname "$path")"
 git -C "$ROOT" show "HEAD:$path" >"$PACKAGE/payload/$path"
 mode=$(git -C "$ROOT" ls-tree HEAD -- "$path" | awk '{print substr($1,4)}')
 printf '%s\t%s\n' "$path" "${mode:-644}" >>"$PACKAGE/MODES.list"
done <"$PACKAGE/FILES.list"
python3 "$STAMPER" "$PACKAGE/payload" "$ASSET_KEY"
work=$(mktemp -d); trap 'rm -rf -- "$work"' EXIT
: >"$PACKAGE/SUPPORTED_BASELINES.sha256"; : >"$PACKAGE/SUPPORTED_TREES.sha256"; : >"$work/all"
for entry in "${BASELINES[@]}"; do
 label=${entry%%:*}; rev=${entry#*:}; tree="$work/tree-$label"; inv="$work/$label.files"; hashes="$work/$label.hashes"
 mkdir -p "$tree"; git -C "$ROOT" archive "$rev" | tar -x -C "$tree"
 python3 "$SELECTOR" "$rev" >"$inv"; cat "$inv" >>"$work/all"; : >"$hashes"
 while IFS= read -r p; do h=$(sha256sum "$tree/$p"|awk '{print $1}'); printf '%s  %s\n' "$h" "$p" >>"$hashes"; printf '%s\t%s\t%s\t%s\n' "$p" "$h" "$label" "$rev" >>"$PACKAGE/SUPPORTED_BASELINES.sha256"; done <"$inv"
 th=$(sha256sum "$hashes"|awk '{print $1}'); printf '%s\t%s\t%s\n' "$th" "$label" "$rev" >>"$PACKAGE/SUPPORTED_TREES.sha256"
done
# Exact captured lived-production 2.11.5 tree retained from v2.12.0 qualification.
printf '%s\t%s\t%s\n' '538ee589d58360c411d6769fbe752f64867c053d167f50a3669f355e6cf2f57d' '2.11.5-lived-production-20260913' 'production-capture-20260913-plus-site-manifest' >>"$PACKAGE/SUPPORTED_TREES.sha256"
# Exact c52 production shape after the already-applied site-manifest correction.
printf '%s\t%s\t%s\n' '7a8f754088b8b4dc07aa3f4419bd180da490e81bf19dff68926857bec648bdf9' '2.12.0-lived-production' 'c52-plus-site-manifest-correction' >>"$PACKAGE/SUPPORTED_TREES.sha256"
sort -u "$work/all" >"$work/all.sorted"
comm -23 "$work/all.sorted" "$PACKAGE/FILES.list" >"$work/removals"
cat "$work/removals" "$ROOT/tools/release/v2120/lived-production-removals.txt" | sort -u >"$PACKAGE/REMOVALS.list"
comm -12 "$PACKAGE/FILES.list" "$PACKAGE/REMOVALS.list" >"$work/overlap"; [[ ! -s "$work/overlap" ]]
sort -o "$PACKAGE/SUPPORTED_BASELINES.sha256" "$PACKAGE/SUPPORTED_BASELINES.sha256"
install -m755 "$INSTALLER" "$PACKAGE/"; install -m755 "$SELECTOR" "$PACKAGE/production_paths.py"; install -m644 "$ROOT/tools/release/v2121/README.md" "$PACKAGE/README.md"
cat >"$PACKAGE/RELEASE-IDENTITY.txt" <<EOF
release=2.12.1
source_head=$HEAD
build_id=$BUILD_ID
asset_cache_key=$ASSET_KEY
EOF
(cd "$PACKAGE" && find . -type f ! -name PACKAGE-MANIFEST.sha256 -print0 | sort -z | xargs -0 sha256sum | sed 's#  \./#  #') >"$PACKAGE/PACKAGE-MANIFEST.sha256"
(cd "$OUTPUT" && zip -qr PromoteToKing_v2.12.1_INCREMENTAL.zip PromoteToKing_v2.12.1_INCREMENTAL)
(cd "$OUTPUT" && sha256sum PromoteToKing_v2.12.1_INCREMENTAL.zip PromoteToKing_v2.12.1_INCREMENTAL/install-promote-to-king-v2.12.1.sh >SHA256SUMS.txt)
printf 'Built %s\nAsset key: %s\n' "$PACKAGE" "$ASSET_KEY"
