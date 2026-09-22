#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
OUTPUT=${1:-"$ROOT/build-v2130"}
PACKAGE_NAME="PromoteToKing_v2.13.0_INCREMENTAL_FROM_2.12.x"
PACKAGE="$OUTPUT/$PACKAGE_NAME"
INSTALLER="$ROOT/tools/release/v2130/install-promote-to-king-v2.13.0.sh"
STAMPER="$ROOT/tools/release/v2130/stamp_asset_cache_key.py"
SELECTOR="$ROOT/tools/release/v2122/production_paths.py"
BASE_2120=c52fd68dbcd8fdb7cca8a1ab63ee6dd6da1e0303
BASE_2121=3568e8c36d900fab341c3ded43b97adee3fb6263
BASE_2122=006680cbf3bd58bcc014dd1cd1e7e7b0cde0646e
SOURCE_HEAD=9c2f08e3f59945ae983f30d6b214f10140dbd345
BUILD_ID=v2.13.0-cumulative-2.12x-qualified-release-1
PACKAGING_HEAD=$(git -C "$ROOT" rev-parse HEAD)
git -C "$ROOT" cat-file -e "$SOURCE_HEAD^{commit}"
DIGEST=$(python3 - "$SOURCE_HEAD" "$BUILD_ID" <<'PY'
import hashlib,sys
print(hashlib.sha256((sys.argv[1]+'\0'+sys.argv[2]).encode()).hexdigest()[:16])
PY
)
ASSET_KEY="p2k-2.13.0-${SOURCE_HEAD:0:12}-$DIGEST"
BUILT_AT=$(git -C "$ROOT" show -s --format=%cI "$SOURCE_HEAD" | sed 's/+00:00$/Z/')

rm -rf -- "$OUTPUT"
mkdir -p "$PACKAGE/payload"
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT

git -C "$ROOT" diff --name-only --diff-filter=ACMRT "$BASE_2120" "$SOURCE_HEAD" | sort -u >"$work/changed.all"
python3 "$SELECTOR" "$SOURCE_HEAD" >"$work/production.all"
comm -12 "$work/changed.all" "$work/production.all" >"$PACKAGE/FILES.list"
[[ -s "$PACKAGE/FILES.list" ]] || { echo 'v2.13.0 cumulative production scope is empty' >&2; exit 1; }
if git -C "$ROOT" diff --name-status "$BASE_2120" "$SOURCE_HEAD" | grep -Eq '^D'; then
  echo 'v2.13.0 cumulative scope does not permit production deletions' >&2
  exit 1
fi

: >"$PACKAGE/MODES.list"
while IFS= read -r path; do
  git -C "$ROOT" cat-file -e "$SOURCE_HEAD:$path"
  mkdir -p "$PACKAGE/payload/$(dirname "$path")"
  git -C "$ROOT" show "$SOURCE_HEAD:$path" >"$PACKAGE/payload/$path"
  mode=$(git -C "$ROOT" ls-tree "$SOURCE_HEAD" -- "$path" | awk '{print substr($1,4)}')
  printf '%s\t%s\n' "$path" "${mode:-644}" >>"$PACKAGE/MODES.list"
done <"$PACKAGE/FILES.list"

python3 "$STAMPER" "$PACKAGE/payload" "$ASSET_KEY"
python3 - "$PACKAGE/payload" "$BUILT_AT" <<'PY'
from pathlib import Path
import json,re,sys
root=Path(sys.argv[1]); built_at=sys.argv[2]
cfg=root/'assets/js/site-config.js'
text=cfg.read_text()
text,n1=re.subn(r'version:\s*"[^"]+"','version: "2.13.0"',text,count=1)
text,n2=re.subn(r'builtAt:\s*"[^"]+"',f'builtAt: "{built_at}"',text,count=1)
if n1!=1 or n2!=1:
    raise SystemExit('Unable to stamp runtime semantic identity')
cfg.write_text(text)
manifest=root/'site-manifest.json'
data=json.loads(manifest.read_text())
data['version']='2.13.0'
data['builtAt']=built_at
data['baseline']='Promote to King qualified v2.13.0 cumulative production overlay from any supported v2.12.x installation.'
data['release']={
  'corrective': False,
  'scope': [
    'Insights average boards for finished and started matches plus Green-native League/Friendly classification and single-slice pie rendering',
    'Club Points projection terminology corrected to low, medium and high points projections',
    'Opponent Intelligence uses native match rating caps with Open/U1800/U1600/U1400/U1200/U1000 buckets and complete aggregate player coverage',
    'MCA scraping-primary synchronization health, exhaustive index refresh, historical W/D/L backfill and consolidated canonical arena inventory',
    'Public dashboard database-status pills removed'
  ],
  'sourceBaseline':'v2.12.x (2.12.0, 2.12.1 or 2.12.2)',
  'databaseSchemaChange': True,
  'runtimeDataReset': False,
  'productionDatabase':'green',
  'blueDatabaseMode':'recovery-only'
}
data['preservedPriorManifest']={'path':'site-manifest.json@v2.12.2','version':'2.12.2'}
manifest.write_text(json.dumps(data,indent=2,ensure_ascii=False)+'\n')
PY

install -m755 "$INSTALLER" "$PACKAGE/"
cat >"$PACKAGE/README.md" <<EOF
Promote to King v2.13.0 cumulative incremental installer

Qualified source: $SOURCE_HEAD

This scope-limited package upgrades an existing P2K 2.12.0, 2.12.1 or 2.12.2 installation to 2.13.0.
Re-running it on 2.13.0 is supported for idempotency/self-heal.

Only paths in FILES.list are replaced. Mutable application state, uploads, local configuration
and system CRON are outside the transaction. Installation is transactional and restores the exact
pre-install scoped files if activation or verification fails.

The payload is derived from every immutable production path changed since the qualified 2.12.0
baseline and is stamped with immutable asset key $ASSET_KEY.
EOF
cat >"$PACKAGE/RELEASE-IDENTITY.txt" <<EOF
release=2.13.0
source_base_2_12_0=$BASE_2120
source_base_2_12_1=$BASE_2121
source_base_2_12_2=$BASE_2122
source_head=$SOURCE_HEAD
packaging_head=$PACKAGING_HEAD
build_id=$BUILD_ID
asset_cache_key=$ASSET_KEY
supported_source_versions=2.12.0,2.12.1,2.12.2,2.13.0-self-heal
payload_scope=$(wc -l <"$PACKAGE/FILES.list") cumulative immutable production files changed since qualified v2.12.0
EOF

(cd "$PACKAGE" && find . -type f ! -name PACKAGE-MANIFEST.sha256 -print0 | sort -z | xargs -0 sha256sum | sed 's#  \./#  #') >"$PACKAGE/PACKAGE-MANIFEST.sha256"
(cd "$OUTPUT" && zip -qr "$PACKAGE_NAME.zip" "$PACKAGE_NAME")
(cd "$OUTPUT" && sha256sum "$PACKAGE_NAME.zip" "$PACKAGE_NAME/install-promote-to-king-v2.13.0.sh" >SHA256SUMS.txt)
printf 'Built %s\nSource: %s\nPayload files: %s\nAsset key: %s\n' "$PACKAGE" "$SOURCE_HEAD" "$(wc -l <"$PACKAGE/FILES.list")" "$ASSET_KEY"
