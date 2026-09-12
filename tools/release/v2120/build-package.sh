#!/usr/bin/env bash
set -Eeuo pipefail

ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
OUTPUT=${1:-"$ROOT/build-v2120"}
PACKAGE="$OUTPUT/PromoteToKing_v2.12.0_INCREMENTAL"
SELECTOR="$ROOT/tools/release/v2120/production_paths.py"

BASELINES=(
  "2.11.0:2ca1fc191aeef444b4886b53e25a54a83820c25c"
  "2.11.1:b8bf26c7c41ca1914323717766bca995139291aa"
  "2.11.2:4ececcc230ca07099b346cb47396ad00bedd5c21"
  "2.11.3:dcd71c8e76c07defacf6270aff4224b10484968b"
  "2.11.4:6706e619d310e2c74fe2734cfcef8dd2f83d70d1"
  "2.11.5:c534b2dbb0346eac0fa6de869621d6b7d785ead8"
)

rm -rf -- "$OUTPUT"
mkdir -p "$PACKAGE/payload"
python3 "$SELECTOR" HEAD >"$PACKAGE/FILES.list"
mapfile -t TARGET_FILES <"$PACKAGE/FILES.list"

for path in "${TARGET_FILES[@]}"; do
  mkdir -p "$PACKAGE/payload/$(dirname "$path")"
  git -C "$ROOT" show "HEAD:$path" >"$PACKAGE/payload/$path"
  mode=$(git -C "$ROOT" ls-tree HEAD -- "$path" | awk '{print substr($1,4)}')
  printf '%s\t%s\n' "$path" "${mode:-644}" >>"$PACKAGE/MODES.list"
done

work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
: >"$PACKAGE/SUPPORTED_BASELINES.sha256"
: >"$PACKAGE/SUPPORTED_TREES.sha256"
: >"$work/all-baseline-paths"

for entry in "${BASELINES[@]}"; do
  label=${entry%%:*}
  revision=${entry#*:}
  tree="$work/tree-$label"
  inventory="$work/$label.files"
  hashes="$work/$label.hashes"
  mkdir -p "$tree"
  git -C "$ROOT" archive "$revision" | tar -x -C "$tree"
  python3 "$SELECTOR" "$revision" >"$inventory"
  cat "$inventory" >>"$work/all-baseline-paths"
  : >"$hashes"
  while IFS= read -r path; do
    hash=$(sha256sum "$tree/$path" | awk '{print $1}')
    printf '%s  %s\n' "$hash" "$path" >>"$hashes"
    printf '%s\t%s\t%s\t%s\n' "$path" "$hash" "$label" "$revision" \
      >>"$PACKAGE/SUPPORTED_BASELINES.sha256"
  done <"$inventory"
  tree_hash=$(sha256sum "$hashes" | awk '{print $1}')
  printf '%s\t%s\t%s\n' "$tree_hash" "$label" "$revision" \
    >>"$PACKAGE/SUPPORTED_TREES.sha256"
done

sort -u "$work/all-baseline-paths" >"$work/all-baseline-paths.sorted"
comm -23 "$work/all-baseline-paths.sorted" "$PACKAGE/FILES.list" \
  >"$PACKAGE/REMOVALS.list"
sort -o "$PACKAGE/SUPPORTED_BASELINES.sha256" "$PACKAGE/SUPPORTED_BASELINES.sha256"

install -m 755 "$ROOT/tools/release/v2120/install-promote-to-king-v2.12.0.sh" "$PACKAGE/"
install -m 755 "$SELECTOR" "$PACKAGE/production_paths.py"
install -m 644 "$ROOT/tools/release/v2120/README.md" "$PACKAGE/README.md"

cat >"$PACKAGE/RELEASE-IDENTITY.txt" <<EOF
release=2.12.0
source_head=$(git -C "$ROOT" rev-parse HEAD)
recruitment_key=p2k-2.12.0-d025d8c46103-8e4b12768d33959c
provenance=d025d8c4610390e54058bca069785e9916af5483
build_id=match-recruitment-release-identity-2
site_config_key=p2k-2.12.0-d025d8c46103-ae73ae796f09667d
EOF

# Authenticate everything except this manifest itself. SHA256SUMS authenticates
# the finished ZIP, so no circular self-hash is needed.
(
  cd "$PACKAGE"
  find . -type f ! -name PACKAGE-MANIFEST.sha256 -print0 \
    | sort -z | xargs -0 sha256sum | sed 's#  \./#  #'
) >"$PACKAGE/PACKAGE-MANIFEST.sha256"

(cd "$OUTPUT" && zip -qr PromoteToKing_v2.12.0_INCREMENTAL.zip PromoteToKing_v2.12.0_INCREMENTAL)
(
  cd "$OUTPUT"
  sha256sum \
    PromoteToKing_v2.12.0_INCREMENTAL.zip \
    PromoteToKing_v2.12.0_INCREMENTAL/install-promote-to-king-v2.12.0.sh \
    >SHA256SUMS.txt
)
