#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
BUILD=${1:?build directory}
SOURCE_HEAD=9c2f08e3f59945ae983f30d6b214f10140dbd345
RUN="$BUILD/PromoteToKing_v2.13.0_INCREMENTAL_FROM_2.12.x_${SOURCE_HEAD:0:8}.run"
BASE_2120=c52fd68dbcd8fdb7cca8a1ab63ee6dd6da1e0303
BASE_2122=006680cbf3bd58bcc014dd1cd1e7e7b0cde0646e
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
mutable(){ local t=$1; mkdir -p "$t/config/local" "$t/data/q" "$t/storage/runtime" "$t/uploads"; printf secret >"$t/config/local/secret.env"; printf db >"$t/data/q/application.sqlite"; printf runtime >"$t/storage/runtime/state"; printf upload >"$t/uploads/user-file"; }
for spec in "$BASE_2120:standalone-2120" "$BASE_2122:standalone-2122"; do
  ref=${spec%%:*}
  label=${spec#*:}
  t="$WORK/$label"
  mkdir "$t"
  git -C "$ROOT" archive "$ref" | tar -x -C "$t"
  mutable "$t"
  before=$(cd "$t" && sha256sum config/local/secret.env data/q/application.sqlite storage/runtime/state uploads/user-file)
  PATH="$WORK/bin:$PATH" P2K_QUALIFICATION_CRONTAB="$WORK/cron" bash "$RUN" "$t"
  bash "$RUN" "$t" verify
  [[ $(tr -d '\r\n[:space:]' <"$t/VERSION") == 2.13.0 ]]
  after=$(cd "$t" && sha256sum config/local/secret.env data/q/application.sqlite storage/runtime/state uploads/user-file)
  [[ "$before" == "$after" ]]
  [[ "$CRON_HASH" == "$(sha256sum "$WORK/cron")" ]]
done
(cd "$BUILD" && sha256sum -c STANDALONE-SHA256SUM.txt)
echo 'v2.13.0 standalone .run qualification passed from 2.12.0 and 2.12.2.'
