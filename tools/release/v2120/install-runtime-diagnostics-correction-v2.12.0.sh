#!/usr/bin/env bash
set -Eeuo pipefail

readonly DEFAULT_ROOT="/kunden/homepages/43/d141198007/htdocs/PromoteToKing"
readonly TARGET=${1:-"$DEFAULT_ROOT"}
readonly RUNTIME="$TARGET/assets/js/pages/runtime-diagnostics.js"
readonly PAGE="$TARGET/InsightsHealth.html"
readonly OLD_RUNTIME_HASH="583dce403ee5709c4c6d4e2915685a012780704b424146fdff867a141bbde4ec"
readonly NEW_RUNTIME_HASH="60ea06d510249b89924f963cdc735d632bc7c88fceac7567e16386f718e98d1a"
readonly OLD_PAGE_HASH="429b510daec253ab7cdab76d9191454873ab7a3eb7c8b345ba8ab37fbcbd823e"
readonly NEW_PAGE_HASH="907642514a87dbeba117a5f4e0bfa5b35e2c419e3732ce111d16f0a5445ab4f8"
readonly NEW_CACHE_KEY="p2k-2.12.0-f193ec484796-365aac4e1e4ef57f"

BACKUP_DIR=""
TEMP_RUNTIME=""
TEMP_PAGE=""
COMMITTED=0

cleanup() {
  [[ -z "$TEMP_RUNTIME" || ! -e "$TEMP_RUNTIME" ]] || rm -f -- "$TEMP_RUNTIME"
  [[ -z "$TEMP_PAGE" || ! -e "$TEMP_PAGE" ]] || rm -f -- "$TEMP_PAGE"
  [[ -z "$BACKUP_DIR" || ! -d "$BACKUP_DIR" || $COMMITTED -eq 0 ]] || rm -rf -- "$BACKUP_DIR"
}

rollback() {
  local status=$?
  trap - ERR INT TERM
  if [[ $COMMITTED -eq 0 && -n "$BACKUP_DIR" && -d "$BACKUP_DIR" ]]; then
    [[ ! -f "$BACKUP_DIR/runtime-diagnostics.js" ]] || cp -p -- "$BACKUP_DIR/runtime-diagnostics.js" "$RUNTIME" || true
    [[ ! -f "$BACKUP_DIR/InsightsHealth.html" ]] || cp -p -- "$BACKUP_DIR/InsightsHealth.html" "$PAGE" || true
  fi
  [[ -z "$BACKUP_DIR" || ! -d "$BACKUP_DIR" ]] || rm -rf -- "$BACKUP_DIR"
  cleanup
  exit "$status"
}
trap rollback ERR INT TERM
trap cleanup EXIT

[[ -d "$TARGET" ]] || { echo "ERROR: target directory does not exist: $TARGET" >&2; exit 2; }
[[ -f "$TARGET/VERSION" ]] || { echo "ERROR: target VERSION is missing" >&2; exit 2; }
[[ $(tr -d '\r\n[:space:]' <"$TARGET/VERSION") == "2.12.0" ]] \
  || { echo "ERROR: this correction applies only to P2K 2.12.0" >&2; exit 2; }
[[ -f "$RUNTIME" && -f "$PAGE" ]] || { echo "ERROR: runtime diagnostics files are missing" >&2; exit 2; }
grep -Fq 'version: "2.12.0"' "$TARGET/assets/js/site-config.js" \
  || { echo "ERROR: site-config is not v2.12.0" >&2; exit 2; }

runtime_hash=$(sha256sum "$RUNTIME" | awk '{print $1}')
page_hash=$(sha256sum "$PAGE" | awk '{print $1}')
if [[ "$runtime_hash" == "$NEW_RUNTIME_HASH" && "$page_hash" == "$NEW_PAGE_HASH" ]]; then
  echo "v2.12.0 runtime-diagnostics correction is already installed; no change required."
  exit 0
fi
[[ "$runtime_hash" == "$OLD_RUNTIME_HASH" ]] \
  || { echo "ERROR: runtime-diagnostics.js is not the exact qualified pre-correction file" >&2; exit 2; }
[[ "$page_hash" == "$OLD_PAGE_HASH" ]] \
  || { echo "ERROR: InsightsHealth.html is not the exact qualified pre-correction file" >&2; exit 2; }

runtime_mode=$(stat -c '%a' "$RUNTIME")
page_mode=$(stat -c '%a' "$PAGE")
BACKUP_DIR=$(mktemp -d "$TARGET/.p2k-v2120-runtime-diagnostics.backup.XXXXXX")
TEMP_RUNTIME=$(mktemp "$TARGET/.p2k-v2120-runtime-diagnostics.new.XXXXXX")
TEMP_PAGE=$(mktemp "$TARGET/.p2k-v2120-insights-health.new.XXXXXX")
cp -p -- "$RUNTIME" "$BACKUP_DIR/runtime-diagnostics.js"
cp -p -- "$PAGE" "$BACKUP_DIR/InsightsHealth.html"

python3 - "$RUNTIME" "$TEMP_RUNTIME" <<'PY'
from pathlib import Path
import sys
source = Path(sys.argv[1]).read_text(encoding="utf-8")
helper_anchor = "async function collect(){"
helper = 'function siteConfigCacheMarkerMatchesVersion(marker,version){const m=String(marker||""),v=String(version||"");if(!m||!v)return false;if(m===v)return true;const prefix=`p2k-${v}-`;if(!m.startsWith(prefix))return false;const parts=m.slice(prefix.length).split("-");return parts.length===2&&/^[0-9a-f]{12}$/.test(parts[0])&&/^[0-9a-f]{16}$/.test(parts[1])}\n'
old = 'if(cfg.version&&siteConfigAssetVersions.some(v=>v!==cfg.version))warnings.push(`Loaded site-config cache markers include ${siteConfigAssetVersions.join(", ")} while site-config is ${cfg.version}.`);'
new = 'if(cfg.version&&siteConfigAssetVersions.some(v=>!siteConfigCacheMarkerMatchesVersion(v,cfg.version)))warnings.push(`Loaded site-config cache markers include ${siteConfigAssetVersions.join(", ")} while site-config is ${cfg.version}.`);'
if source.count(helper_anchor) != 1 or source.count(old) != 1:
    raise SystemExit("runtime-diagnostics source shape mismatch")
source = source.replace(helper_anchor, helper + helper_anchor, 1).replace(old, new, 1)
Path(sys.argv[2]).write_text(source, encoding="utf-8")
PY

python3 - "$PAGE" "$TEMP_PAGE" "$NEW_CACHE_KEY" <<'PY'
from pathlib import Path
import sys
source = Path(sys.argv[1]).read_text(encoding="utf-8")
old = "assets/js/pages/runtime-diagnostics.js?v=2.11.5-bf6a828490f57"
new = f"assets/js/pages/runtime-diagnostics.js?v={sys.argv[3]}"
if source.count(old) != 1:
    raise SystemExit("InsightsHealth runtime-diagnostics reference shape mismatch")
Path(sys.argv[2]).write_text(source.replace(old, new, 1), encoding="utf-8")
PY

chmod "$runtime_mode" "$TEMP_RUNTIME"
chmod "$page_mode" "$TEMP_PAGE"
[[ $(sha256sum "$TEMP_RUNTIME" | awk '{print $1}') == "$NEW_RUNTIME_HASH" ]] \
  || { echo "ERROR: generated runtime-diagnostics.js hash mismatch" >&2; exit 2; }
[[ $(sha256sum "$TEMP_PAGE" | awk '{print $1}') == "$NEW_PAGE_HASH" ]] \
  || { echo "ERROR: generated InsightsHealth.html hash mismatch" >&2; exit 2; }

mv -f -- "$TEMP_RUNTIME" "$RUNTIME"
TEMP_RUNTIME=""
mv -f -- "$TEMP_PAGE" "$PAGE"
TEMP_PAGE=""
[[ $(sha256sum "$RUNTIME" | awk '{print $1}') == "$NEW_RUNTIME_HASH" ]] \
  || { echo "ERROR: installed runtime-diagnostics.js hash mismatch" >&2; exit 2; }
[[ $(sha256sum "$PAGE" | awk '{print $1}') == "$NEW_PAGE_HASH" ]] \
  || { echo "ERROR: installed InsightsHealth.html hash mismatch" >&2; exit 2; }
COMMITTED=1
rm -rf -- "$BACKUP_DIR"
BACKUP_DIR=""
echo "v2.12.0 runtime-diagnostics cache-marker correction installed transactionally."
