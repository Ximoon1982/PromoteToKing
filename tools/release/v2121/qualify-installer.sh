#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
BUILD=${1:?build directory}; PACKAGE="$BUILD/PromoteToKing_v2.12.1_INCREMENTAL"; INSTALLER="$PACKAGE/install-promote-to-king-v2.12.1.sh"; SELECTOR="$PACKAGE/production_paths.py"
BASELINES=("2.11.5:c534b2dbb0346eac0fa6de869621d6b7d785ead8" "2.12.0-c52:c52fd68dbcd8fdb7cca8a1ab63ee6dd6da1e0303" "2.12.0-0869:0869b3cd76df87ab20f51643c2d664f75e5f103f")
WORK=$(mktemp -d); trap 'rm -rf -- "$WORK"' EXIT; mkdir "$WORK/bin"
cat >"$WORK/bin/crontab" <<'SH'
#!/usr/bin/env bash
[[ ${1:-} == -l ]] && cat "$P2K_QUALIFICATION_CRONTAB"
SH
chmod +x "$WORK/bin/crontab"; printf '17 3 * * * /srv/p2k/cron.sh --unchanged\n' >"$WORK/cron"; CRON_HASH=$(sha256sum "$WORK/cron")
run(){ PATH="$WORK/bin:$PATH" P2K_QUALIFICATION_CRONTAB="$WORK/cron" "$@"; }
mutable(){ local t=$1; mkdir -p "$t/config/local" "$t/data/q" "$t/storage/runtime" "$t/uploads"; printf secret >"$t/config/local/secret.env"; printf db >"$t/data/q/application.sqlite"; printf runtime >"$t/storage/runtime/state"; printf upload >"$t/uploads/user-file"; }
mutable_hash(){ (cd "$1" && sha256sum config/local/secret.env data/q/application.sqlite storage/runtime/state uploads/user-file); }
snapshot(){ local t=$1 o=$2; python3 "$SELECTOR" --root "$t" >"$WORK/files"; (cd "$t"; while IFS= read -r p; do sha256sum "$p"; done <"$WORK/files") >"$o"; }
(cd "$BUILD" && sha256sum -c SHA256SUMS.txt)
REF=""
for e in "${BASELINES[@]}"; do
 label=${e%%:*}; rev=${e#*:}; t="$WORK/$label"; mkdir "$t"; git -C "$ROOT" archive "$rev" | tar -x -C "$t"; mutable "$t"; mutable_hash "$t" >"$WORK/$label.before"
 run "$INSTALLER" "$t"; "$INSTALLER" "$t" verify; "$INSTALLER" "$t" check; [[ $(tr -d '\r\n[:space:]' <"$t/VERSION") == 2.12.1 ]]; mutable_hash "$t" >"$WORK/$label.after"; diff -u "$WORK/$label.before" "$WORK/$label.after"; [[ "$CRON_HASH" == "$(sha256sum "$WORK/cron")" ]]
 snapshot "$t" "$WORK/$label.managed"; cmp -s "$PACKAGE/FILES.list" "$WORK/files"; if [[ -z "$REF" ]]; then REF="$WORK/$label.managed"; else diff -u "$REF" "$WORK/$label.managed"; fi
 run "$INSTALLER" "$t"; snapshot "$t" "$WORK/$label.reinstalled"; diff -u "$WORK/$label.managed" "$WORK/$label.reinstalled"; mutable_hash "$t" >"$WORK/$label.reafter"; diff -u "$WORK/$label.before" "$WORK/$label.reafter"
 echo "qualified $label -> 2.12.1: exact convergence, mutable state, CRON, verify, check, idempotency"
done
# Forced activation rollback from exact 2.11.5 baseline.
t="$WORK/rollback"; mkdir "$t"; git -C "$ROOT" archive c534b2dbb0346eac0fa6de869621d6b7d785ead8 | tar -x -C "$t"; mutable "$t"; (cd "$t" && find . -type f -print0 | sort -z | xargs -0 sha256sum) >"$WORK/r.before"
if run env P2K_FORCE_INSTALL_FAILURE_AFTER=5 "$INSTALLER" "$t"; then echo 'forced activation failure unexpectedly succeeded' >&2; exit 1; fi
(cd "$t" && find . -type f -print0 | sort -z | xargs -0 sha256sum) >"$WORK/r.after"; diff -u "$WORK/r.before" "$WORK/r.after"; [[ "$CRON_HASH" == "$(sha256sum "$WORK/cron")" ]]; echo '2.11.5 rollback restored exact pre-install tree'
echo 'v2.12.1 universal installer qualification passed'
