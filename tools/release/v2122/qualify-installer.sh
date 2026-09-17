#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
BUILD=${1:?build directory}
PACKAGE_NAME="PromoteToKing_v2.12.2_INCREMENTAL_FROM_2.12.x"
PACKAGE="$BUILD/$PACKAGE_NAME"
INSTALLER="$PACKAGE/install-promote-to-king-v2.12.2.sh"
BASE_2120=c52fd68dbcd8fdb7cca8a1ab63ee6dd6da1e0303
BASE_2121=3568e8c36d900fab341c3ded43b97adee3fb6263
WORK=$(mktemp -d)
trap 'rm -rf -- "$WORK"' EXIT
mkdir "$WORK/bin"
cat >"$WORK/bin/crontab" <<'CRON'
#!/usr/bin/env bash
[[ ${1:-} == -l ]] && cat "$P2K_QUALIFICATION_CRONTAB"
CRON
chmod +x "$WORK/bin/crontab"
printf '17 3 * * * /srv/p2k/cron.sh --unchanged\n' >"$WORK/cron"
CRON_HASH=$(sha256sum "$WORK/cron")
run(){ PATH="$WORK/bin:$PATH" P2K_QUALIFICATION_CRONTAB="$WORK/cron" "$@"; }
mutable(){
  local t=$1
  mkdir -p "$t/config/local" "$t/data/q" "$t/storage/runtime" "$t/uploads"
  printf secret >"$t/config/local/secret.env"
  printf db >"$t/data/q/application.sqlite"
  printf runtime >"$t/storage/runtime/state"
  printf upload >"$t/uploads/user-file"
  printf '{"schemaVersion":4,"revision":17,"items":[],"arenas":[]}' >"$t/data/priority-matches.json"
  printf '{"schemaVersion":4,"revision":23,"items":[],"arenas":[]}' >"$t/data/events-showcase.json"
}
mutable_hash(){ (cd "$1" && sha256sum config/local/secret.env data/q/application.sqlite data/priority-matches.json data/events-showcase.json storage/runtime/state uploads/user-file); }
full_snapshot(){ (cd "$1" && find . -type f -print0 | sort -z | xargs -0 sha256sum); }
verify_unrelated(){
  python3 - "$1" "$2" "$PACKAGE/FILES.list" <<'PY'
from pathlib import Path
import hashlib,sys
before=Path(sys.argv[1]).read_text().splitlines();root=Path(sys.argv[2]);scope=set(Path(sys.argv[3]).read_text().splitlines())
for line in before:
    h,path=line.split('  ',1);path=path.removeprefix('./')
    if path in scope: continue
    p=root/path
    if not p.is_file(): raise SystemExit(f'unrelated file disappeared: {path}')
    if hashlib.sha256(p.read_bytes()).hexdigest()!=h: raise SystemExit(f'unrelated file changed: {path}')
print('unrelated immutable/application files unchanged')
PY
}
qualify_source(){
  local ref=$1
  local label=$2
  local degrade=${3:-0}
  local t="$WORK/$label"
  mkdir "$t"
  git -C "$ROOT" archive "$ref" | tar -x -C "$t"
  mutable "$t"
  if [[ "$degrade" == 1 ]]; then : >"$t/assets/js/admin/tool-registry.js"; fi
  mutable_hash "$t" >"$WORK/$label.mutable.before"
  full_snapshot "$t" >"$WORK/$label.full.before"
  run "$INSTALLER" "$t" install
  "$INSTALLER" "$t" verify
  "$INSTALLER" "$t" check
  [[ $(tr -d '\r\n[:space:]' <"$t/VERSION") == 2.12.2 ]]
  mutable_hash "$t" >"$WORK/$label.mutable.after"
  diff -u "$WORK/$label.mutable.before" "$WORK/$label.mutable.after"
  verify_unrelated "$WORK/$label.full.before" "$t"
  [[ "$CRON_HASH" == "$(sha256sum "$WORK/cron")" ]]
  full_snapshot "$t" >"$WORK/$label.installed.once"
  run "$INSTALLER" "$t" install
  full_snapshot "$t" >"$WORK/$label.installed.twice"
  diff -u "$WORK/$label.installed.once" "$WORK/$label.installed.twice"
}

(cd "$BUILD" && sha256sum -c SHA256SUMS.txt)
[[ -s "$PACKAGE/FILES.list" ]]
[[ $(wc -l <"$PACKAGE/FILES.list") -gt 11 ]]
[[ -f "$PACKAGE/payload/server/events-showcase/public/embed-card.php" ]]
[[ -f "$PACKAGE/payload/assets/js/pages/recruit-match-v2121-bootstrap.js" ]]
[[ -f "$PACKAGE/payload/assets/js/pages/recruit-match.js" ]]

# Both supported 2.12.x baselines must converge to the identical v2.12.2 payload.
# The 2.12.0 case deliberately reproduces the production failure condition where
# a scoped loader file is empty; cumulative overlay installation must repair it.
qualify_source "$BASE_2120" from-2120 1
qualify_source "$BASE_2121" from-2121 0

# Forced failure restores an exact 2.12.0 pre-install tree plus mutable state.
r="$WORK/rollback"
mkdir "$r"
git -C "$ROOT" archive "$BASE_2120" | tar -x -C "$r"
mutable "$r"
: >"$r/assets/js/admin/tool-registry.js"
full_snapshot "$r" >"$WORK/rollback.before"
if run env P2K_FORCE_INSTALL_FAILURE_AFTER=5 "$INSTALLER" "$r" install; then
  echo 'forced activation failure unexpectedly succeeded' >&2; exit 1
fi
full_snapshot "$r" >"$WORK/rollback.after"
diff -u "$WORK/rollback.before" "$WORK/rollback.after"
[[ "$CRON_HASH" == "$(sha256sum "$WORK/cron")" ]]

# Non-2.12.x semantic versions are rejected before transaction activation.
o="$WORK/unsupported"
mkdir "$o"
git -C "$ROOT" archive "$BASE_2120" | tar -x -C "$o"
printf '2.11.9\n' >"$o/VERSION"
full_snapshot "$o" >"$WORK/unsupported.before"
if run "$INSTALLER" "$o" install; then echo 'cumulative installer unexpectedly accepted non-2.12.x target' >&2; exit 1; fi
full_snapshot "$o" >"$WORK/unsupported.after"
diff -u "$WORK/unsupported.before" "$WORK/unsupported.after"

echo "v2.12.2 cumulative incremental installer qualification passed: 2.12.0 + 2.12.1, degraded scoped-file repair, state/CRON preservation, unrelated-file preservation, idempotency, rollback, non-2.12.x rejection ($(wc -l <"$PACKAGE/FILES.list") payload files)"
