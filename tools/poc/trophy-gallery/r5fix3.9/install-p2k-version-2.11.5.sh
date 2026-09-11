#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
MODE="${2:-install}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/p2k-version-2115.XXXXXX")"
BACKUP="$ROOT/.p2k-version-2.11.5-backup-$STAMP"

cleanup(){ rm -rf "$STAGE"; }
trap cleanup EXIT

fail(){ echo "ERROR: $*" >&2; exit 1; }
need(){ [[ -f "$1" ]] || fail "required file missing: $1"; }
log(){ printf '\n[%s] %s\n' "$1" "$2"; }

verify_package(){
    python3 - "$HERE/MANIFEST.sha256" "$HERE" <<'PY'
from pathlib import Path
import hashlib,sys
manifest=Path(sys.argv[1]); root=Path(sys.argv[2])
for raw in manifest.read_text(encoding="utf-8").splitlines():
    if not raw.strip(): continue
    expected,name=raw.split(None,1)
    p=root/name.strip()
    if not p.is_file(): raise SystemExit("package payload missing: "+name.strip())
    got=hashlib.sha256(p.read_bytes()).hexdigest()
    if got != expected: raise SystemExit("package checksum mismatch: "+name.strip())
print("Package manifest OK.")
PY
}

preflight(){
    need "$ROOT/VERSION"
    need "$ROOT/assets/js/site-config.js"
    need "$ROOT/ui-v2.html"
    need "$ROOT/trophies/index.html"
    need "$ROOT/assets/js/admin/trophy-gallery-r5fix3.8.js"
    need "$ROOT/assets/trophy-gallery/trophy-gallery-r5fix3.8.css"
    grep -Fq '<!-- P2K_TROPHY_R5FIX38_BEGIN -->' "$ROOT/ui-v2.html" \
      || fail "r5fix3.8 loader marker missing from ui-v2.html"
    grep -Fq '<!-- P2K_TROPHY_R5FIX38_BEGIN -->' "$ROOT/trophies/index.html" \
      || fail "r5fix3.8 loader marker missing from trophies/index.html"
}

command -v python3 >/dev/null 2>&1 || fail "python3 is required"
need "$HERE/version-align.py"
need "$HERE/MANIFEST.sha256"

if [[ "$MODE" == "verify" ]]; then
    log VERIFY "Checking canonical 2.11.5 version surfaces and exact installed hashes"
    preflight
    python3 "$HERE/version-align.py" verify --root "$ROOT"
    exit 0
fi

[[ "$MODE" == "install" ]] || fail "usage: $0 [P2K_ROOT] [install|verify]"

log PREFLIGHT "Confirming r5fix3.8 baseline and required P2K version surfaces"
preflight

log PACKAGE "Verifying immutable package payload"
verify_package

log RUNTIME "Shell PHP is deliberately not used"
echo "This increment changes version metadata/cache identities only."
echo "Trophy r5fix3.8 behavior files are not modified."

log PLAN "Auditing active runtime version surfaces and staging all changes"
python3 "$HERE/version-align.py" plan --root "$ROOT" --stage "$STAGE"

log COMMIT "Applying the staged version alignment transactionally"
python3 "$HERE/version-align.py" apply --root "$ROOT" --stage "$STAGE" --backup "$BACKUP"

log FINAL-VERIFY "Re-auditing active current-version surfaces"
python3 "$HERE/version-align.py" verify --root "$ROOT"

echo
echo "============================================================"
echo " Promote to King version alignment complete: 2.11.5"
echo "============================================================"
echo "Backup: $BACKUP"
echo "Trophy Gallery r5fix3.8 behavior remains unchanged."
echo "Historical comments, migration identifiers and versioned module filenames are intentionally not rewritten."
echo "Numeric runtime asset cache keys were normalized to a unique 2.11.5 build key."
