#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
BUILD=${1:?build directory}
PACKAGE_NAME="PromoteToKing_v2.13.0_INCREMENTAL_FROM_2.12.x"
PACKAGE="$BUILD/$PACKAGE_NAME"
INSTALLER="$PACKAGE/install-promote-to-king-v2.13.0.sh"
BASE_2120=c52fd68dbcd8fdb7cca8a1ab63ee6dd6da1e0303
BASE_2121=3568e8c36d900fab341c3ded43b97adee3fb6263
BASE_2122=006680cbf3bd58bcc014dd1cd1e7e7b0cde0646e
SOURCE_HEAD=9c2f08e3f59945ae983f30d6b214f10140dbd345
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
mutable(){ local t=$1; mkdir -p "$t/config/local" "$t/data/q" "$t/storage/runtime" "$t/uploads"; printf secret >"$t/config/local/secret.env"; printf db >"$t/data/q/application.sqlite"; printf runtime >"$t/storage/runtime/state"; printf upload >"$t/uploads/user-file"; printf '{"schemaVersion":4,"revision":17,"items":[],"arenas":[]}' >"$t/data/priority-matches.json"; printf '{"schemaVersion":4,"revision":23,"items":[],"arenas":[]}' >"$t/data/events-showcase.json"; }
mutable_hash(){ (cd "$1" && sha256sum config/local/secret.env data/q/application.sqlite data/priority-matches.json data/events-showcase.json storage/runtime/state uploads/user-file); }
full_snapshot(){ (cd "$1" && find . -type f -print0 | sort -z | xargs -0 sha256sum); }
verify_unrelated(){ python3 - "$1" "$2" "$PACKAGE/FILES.list" <<'PY'
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
qualify_source(){ local ref=$1 label=$2 degrade=${3:-0}; local t="$WORK/$label"; mkdir "$t"; git -C "$ROOT" archive "$ref" | tar -x -C "$t"; mutable "$t"; if [[ "$degrade" == 1 ]]; then : >"$t/assets/js/admin/tool-registry.js"; fi; mutable_hash "$t" >"$WORK/$label.mutable.before"; full_snapshot "$t" >"$WORK/$label.full.before"; run "$INSTALLER" "$t" install; "$INSTALLER" "$t" verify; "$INSTALLER" "$t" check; [[ $(tr -d '\r\n[:space:]' <"$t/VERSION") == 2.13.0 ]]; mutable_hash "$t" >"$WORK/$label.mutable.after"; diff -u "$WORK/$label.mutable.before" "$WORK/$label.mutable.after"; verify_unrelated "$WORK/$label.full.before" "$t"; [[ "$CRON_HASH" == "$(sha256sum "$WORK/cron")" ]]; full_snapshot "$t" >"$WORK/$label.installed.once"; run "$INSTALLER" "$t" install; full_snapshot "$t" >"$WORK/$label.installed.twice"; diff -u "$WORK/$label.installed.once" "$WORK/$label.installed.twice"; }

(cd "$BUILD" && sha256sum -c SHA256SUMS.txt)
[[ -s "$PACKAGE/FILES.list" ]]
[[ $(wc -l <"$PACKAGE/FILES.list") -gt 40 ]]
for path in VERSION ui-v2.html TeamInsights.html TeamPointsAdmin.html assets/js/site-config.js assets/js/pages/dashboard-insights.js assets/js/pages/dashboard-insights-charts.js assets/js/pages/team-points-features.js assets/js/admin/admin-session-controller.js server/team-points/src/Repository.php server/team-points/src/LiveRanksService.php server/team-points/src/McaResultsCronService.php server/team-points/public/live-ranks-admin.php server/team-points/sql/analytics-schema.sql server/team-points-green/sql/core-schema.sql server/team-points-green/src/GreenRepository.php server/team-points-green/src/GreenCompatibility.php; do [[ -f "$PACKAGE/payload/$path" ]] || { echo "missing required payload path: $path" >&2; exit 1; }; done
grep -Fq 'version: "2.13.0"' "$PACKAGE/payload/assets/js/site-config.js"
grep -Fq '"version": "2.13.0"' "$PACKAGE/payload/site-manifest.json"

qualify_source "$BASE_2120" from-2120 1
qualify_source "$BASE_2121" from-2121 0
qualify_source "$BASE_2122" from-2122 0
qualify_source "$SOURCE_HEAD" from-2130-qualified-source 0

s="$WORK/self-heal"; mkdir "$s"; git -C "$ROOT" archive "$BASE_2122" | tar -x -C "$s"; mutable "$s"; run "$INSTALLER" "$s" install; printf 'stale partial overlay\n' >"$s/TeamPointsAdmin.html"; run "$INSTALLER" "$s" install; "$INSTALLER" "$s" verify

r="$WORK/rollback"; mkdir "$r"; git -C "$ROOT" archive "$BASE_2122" | tar -x -C "$r"; mutable "$r"; full_snapshot "$r" >"$WORK/rollback.before"; if run env P2K_FORCE_INSTALL_FAILURE_AFTER=7 "$INSTALLER" "$r" install; then echo 'forced activation failure unexpectedly succeeded' >&2; exit 1; fi; full_snapshot "$r" >"$WORK/rollback.after"; diff -u "$WORK/rollback.before" "$WORK/rollback.after"; [[ "$CRON_HASH" == "$(sha256sum "$WORK/cron")" ]]

o="$WORK/unsupported"; mkdir "$o"; git -C "$ROOT" archive "$BASE_2120" | tar -x -C "$o"; printf '2.11.9\n' >"$o/VERSION"; full_snapshot "$o" >"$WORK/unsupported.before"; if run "$INSTALLER" "$o" install; then echo 'cumulative installer unexpectedly accepted unsupported target' >&2; exit 1; fi; full_snapshot "$o" >"$WORK/unsupported.after"; diff -u "$WORK/unsupported.before" "$WORK/unsupported.after"

echo "v2.13.0 cumulative incremental installer qualification passed ($(wc -l <"$PACKAGE/FILES.list") payload files)"
