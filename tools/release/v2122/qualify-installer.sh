#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
BUILD=${1:?build directory}
PACKAGE_NAME="PromoteToKing_v2.12.2_INCREMENTAL_FROM_2.12.1"
PACKAGE="$BUILD/$PACKAGE_NAME"
INSTALLER="$PACKAGE/install-promote-to-king-v2.12.2.sh"
BASE=3568e8c36d900fab341c3ded43b97adee3fb6263
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

(cd "$BUILD" && sha256sum -c SHA256SUMS.txt)
[[ $(wc -l <"$PACKAGE/FILES.list") -eq 11 ]]
[[ $(wc -l <"$PACKAGE/BASELINE-FILES.sha256") -eq 4 ]]
[[ $(wc -l <"$PACKAGE/ABSENT-FILES.list") -eq 7 ]]

# Exact qualified v2.12.1 -> v2.12.2 overlay qualification.
t="$WORK/target"
mkdir "$t"
git -C "$ROOT" archive "$BASE" | tar -x -C "$t"
mutable "$t"
mutable_hash "$t" >"$WORK/mutable.before"
full_snapshot "$t" >"$WORK/full.before"
run "$INSTALLER" "$t"
"$INSTALLER" "$t" verify
"$INSTALLER" "$t" check
[[ $(tr -d '\r\n[:space:]' <"$t/VERSION") == 2.12.2 ]]
mutable_hash "$t" >"$WORK/mutable.after"
diff -u "$WORK/mutable.before" "$WORK/mutable.after"
[[ "$CRON_HASH" == "$(sha256sum "$WORK/cron")" ]]

# Prove every pre-existing file outside FILES.list is byte-identical.
python3 - "$WORK/full.before" "$t" "$PACKAGE/FILES.list" <<'PY'
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

# Idempotency: second install is verify-only and changes nothing.
full_snapshot "$t" >"$WORK/installed.once"
run "$INSTALLER" "$t"
full_snapshot "$t" >"$WORK/installed.twice"
diff -u "$WORK/installed.once" "$WORK/installed.twice"

# Forced failure restores the exact entire v2.12.1 tree plus mutable state.
r="$WORK/rollback"
mkdir "$r"
git -C "$ROOT" archive "$BASE" | tar -x -C "$r"
mutable "$r"
full_snapshot "$r" >"$WORK/rollback.before"
if run env P2K_FORCE_INSTALL_FAILURE_AFTER=5 "$INSTALLER" "$r"; then
  echo 'forced activation failure unexpectedly succeeded' >&2; exit 1
fi
full_snapshot "$r" >"$WORK/rollback.after"
diff -u "$WORK/rollback.before" "$WORK/rollback.after"
[[ "$CRON_HASH" == "$(sha256sum "$WORK/cron")" ]]

# Scope guard: an older 2.12.0 tree must be rejected, not silently converged.
o="$WORK/old-2120"
mkdir "$o"
git -C "$ROOT" archive c52fd68dbcd8fdb7cca8a1ab63ee6dd6da1e0303 | tar -x -C "$o"
full_snapshot "$o" >"$WORK/old.before"
if run "$INSTALLER" "$o"; then echo 'scope-limited installer unexpectedly accepted v2.12.0' >&2; exit 1; fi
full_snapshot "$o" >"$WORK/old.after"
diff -u "$WORK/old.before" "$WORK/old.after"

echo 'v2.12.2 scope-limited incremental installer qualification passed: exact v2.12.1 baseline, 11-file overlay, state/CRON preservation, idempotency, rollback, older-baseline rejection'
