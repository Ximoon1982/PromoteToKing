#!/usr/bin/env bash
set -Eeuo pipefail

ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
BUILD=${1:?build directory}
PACKAGE="$BUILD/PromoteToKing_v2.12.0_INCREMENTAL"
INSTALLER="$PACKAGE/install-promote-to-king-v2.12.0.sh"
SELECTOR="$PACKAGE/production_paths.py"

BASELINES=(
  "2.11.0:2ca1fc191aeef444b4886b53e25a54a83820c25c"
  "2.11.1:b8bf26c7c41ca1914323717766bca995139291aa"
  "2.11.2:4ececcc230ca07099b346cb47396ad00bedd5c21"
  "2.11.3:dcd71c8e76c07defacf6270aff4224b10484968b"
  "2.11.4:6706e619d310e2c74fe2734cfcef8dd2f83d70d1"
  "2.11.5:c534b2dbb0346eac0fa6de869621d6b7d785ead8"
)

WORK=$(mktemp -d)
trap 'rm -rf -- "$WORK"' EXIT
mkdir "$WORK/bin"
cat >"$WORK/bin/crontab" <<'SH'
#!/usr/bin/env bash
[[ ${1:-} == -l ]] && cat "$P2K_QUALIFICATION_CRONTAB"
SH
chmod +x "$WORK/bin/crontab"
printf '17 3 * * * /srv/p2k/cron.sh --unchanged\n' >"$WORK/cron"
CRON_HASH=$(sha256sum "$WORK/cron")

add_mutable_fixture() {
  local target=$1
  mkdir -p \
    "$target/config/local" "$target/data/q" "$target/storage/runtime" \
    "$target/server/team-points/storage" "$target/cache" "$target/uploads" \
    "$target/logs" "$target/auth/sessions" "$target/backups"
  printf 'secret\n' >"$target/config/local/secret.env"
  printf 'environment\n' >"$target/.env"
  printf 'oauth-state\n' >"$target/auth/sessions/oauth.state"
  printf 'database\n' >"$target/data/q/application.sqlite"
  printf 'storage\n' >"$target/server/team-points/storage/state"
  printf 'runtime\n' >"$target/storage/runtime/state"
  printf 'cache\n' >"$target/cache/cache-entry"
  printf 'upload\n' >"$target/uploads/user-file"
  printf 'log\n' >"$target/logs/application.log"
  printf 'backup\n' >"$target/backups/pre-existing"
}

mutable_hashes() {
  local target=$1
  (
    cd "$target"
    sha256sum \
      .env auth/sessions/oauth.state backups/pre-existing cache/cache-entry \
      config/local/secret.env data/q/application.sqlite logs/application.log \
      server/team-points/storage/state storage/runtime/state uploads/user-file
  )
}

managed_snapshot() {
  local target=$1 output=$2
  python3 "$SELECTOR" --root "$target" >"$WORK/managed.files"
  (
    cd "$target"
    while IFS= read -r path; do sha256sum "$path"; done <"$WORK/managed.files"
  ) >"$output"
}

complete_file_snapshot() {
  local target=$1 output=$2
  (cd "$target" && find . -type f -print0 | sort -z | xargs -0 sha256sum) >"$output"
}

run_installer() {
  PATH="$WORK/bin:$PATH" P2K_QUALIFICATION_CRONTAB="$WORK/cron" "$@"
}

# Confirm that tampering with either control metadata or payload bytes is caught
# before the target is changed.
tamper_target="$WORK/tamper-target"
mkdir "$tamper_target"
git -C "$ROOT" archive c534b2dbb0346eac0fa6de869621d6b7d785ead8 | tar -x -C "$tamper_target"
complete_file_snapshot "$tamper_target" "$WORK/tamper.before"

tampered="$WORK/tampered-package"
cp -al "$PACKAGE" "$tampered"
cp "$tampered/FILES.list" "$WORK/FILES.list.changed"
printf 'assets/js/not-in-payload.js\n' >>"$WORK/FILES.list.changed"
mv "$WORK/FILES.list.changed" "$tampered/FILES.list"
if "$tampered/install-promote-to-king-v2.12.0.sh" "$tamper_target"; then
  echo "metadata tampering was not rejected" >&2; exit 1
fi
complete_file_snapshot "$tamper_target" "$WORK/tamper.after"
diff -u "$WORK/tamper.before" "$WORK/tamper.after"
rm -rf -- "$tampered"

cp -al "$PACKAGE" "$tampered"
payload_sample=$(head -n 1 "$tampered/FILES.list")
cp "$tampered/payload/$payload_sample" "$WORK/payload.changed"
printf '\nTAMPERED\n' >>"$WORK/payload.changed"
mv "$WORK/payload.changed" "$tampered/payload/$payload_sample"
if "$tampered/install-promote-to-king-v2.12.0.sh" "$tamper_target"; then
  echo "payload tampering was not rejected" >&2; exit 1
fi
complete_file_snapshot "$tamper_target" "$WORK/tamper.after"
diff -u "$WORK/tamper.before" "$WORK/tamper.after"
rm -rf -- "$tampered"
echo "package control-metadata and payload tamper rejection passed"

REFERENCE=""
for entry in "${BASELINES[@]}"; do
  label=${entry%%:*}
  revision=${entry#*:}
  target="$WORK/baseline-$label"
  mkdir "$target"
  git -C "$ROOT" archive "$revision" | tar -x -C "$target"
  add_mutable_fixture "$target"
  mutable_hashes "$target" >"$WORK/$label.mutable.before"

  run_installer "$INSTALLER" "$target"
  "$INSTALLER" "$target" verify
  [[ $(tr -d '\r\n[:space:]' <"$target/VERSION") == "2.12.0" ]]
  mutable_hashes "$target" >"$WORK/$label.mutable.after"
  diff -u "$WORK/$label.mutable.before" "$WORK/$label.mutable.after"
  [[ "$CRON_HASH" == "$(sha256sum "$WORK/cron")" ]]

  managed_snapshot "$target" "$WORK/$label.managed"
  cmp -s "$PACKAGE/FILES.list" "$WORK/managed.files"
  if [[ -z "$REFERENCE" ]]; then REFERENCE="$WORK/$label.managed"
  else diff -u "$REFERENCE" "$WORK/$label.managed"; fi

  run_installer "$INSTALLER" "$target"
  managed_snapshot "$target" "$WORK/$label.reinstalled"
  diff -u "$WORK/$label.managed" "$WORK/$label.reinstalled"
  mutable_hashes "$target" >"$WORK/$label.mutable.reinstalled"
  diff -u "$WORK/$label.mutable.before" "$WORK/$label.mutable.reinstalled"
  [[ "$CRON_HASH" == "$(sha256sum "$WORK/cron")" ]]
  echo "qualified $label: exact tree, mutable state, CRON, verify and idempotency"
done

# Ordinary forced failure after payload activation has started.
rollback_target="$WORK/rollback"
mkdir "$rollback_target"
git -C "$ROOT" archive c534b2dbb0346eac0fa6de869621d6b7d785ead8 | tar -x -C "$rollback_target"
add_mutable_fixture "$rollback_target"
complete_file_snapshot "$rollback_target" "$WORK/rollback.before"
if run_installer env P2K_FORCE_INSTALL_FAILURE_AFTER=5 "$INSTALLER" "$rollback_target"; then
  echo "forced activation failure unexpectedly succeeded" >&2; exit 1
fi
complete_file_snapshot "$rollback_target" "$WORK/rollback.after"
diff -u "$WORK/rollback.before" "$WORK/rollback.after"
[[ "$CRON_HASH" == "$(sha256sum "$WORK/cron")" ]]
echo "activation rollback restored the exact pre-install tree"

# Canonical baselines currently produce an empty REMOVALS.list. Exercise removal
# transactionality with a qualification-only supported immutable tree containing
# one known obsolete managed file.
synthetic="$WORK/removal-package"
cp -al "$PACKAGE" "$synthetic"
obsolete="assets/js/p2k-v211-obsolete-qualification.js"
printf '%s\n' "$obsolete" >"$WORK/REMOVALS.changed"
mv "$WORK/REMOVALS.changed" "$synthetic/REMOVALS.list"
removal_target="$WORK/removal-rollback"
mkdir "$removal_target"
git -C "$ROOT" archive c534b2dbb0346eac0fa6de869621d6b7d785ead8 | tar -x -C "$removal_target"
printf 'known obsolete immutable content\n' >"$removal_target/$obsolete"
add_mutable_fixture "$removal_target"
python3 "$synthetic/production_paths.py" --root "$removal_target" >"$WORK/removal.files"
(cd "$removal_target" && while IFS= read -r p; do sha256sum "$p"; done <"$WORK/removal.files") \
  >"$WORK/removal.hashes"
synthetic_hash=$(sha256sum "$WORK/removal.hashes" | awk '{print $1}')
printf '%s\t2.11.5\tqualification-only-removal-tree\n' "$synthetic_hash" \
  >>"$synthetic/SUPPORTED_TREES.sha256"
(
  cd "$synthetic"
  find . -type f ! -name PACKAGE-MANIFEST.sha256 -print0 \
    | sort -z | xargs -0 sha256sum | sed 's#  \./#  #'
) >"$WORK/PACKAGE-MANIFEST.changed"
mv "$WORK/PACKAGE-MANIFEST.changed" "$synthetic/PACKAGE-MANIFEST.sha256"
complete_file_snapshot "$removal_target" "$WORK/removal.before"
if run_installer env P2K_FORCE_INSTALL_FAILURE_PHASE=after-removals \
  "$synthetic/install-promote-to-king-v2.12.0.sh" "$removal_target"; then
  echo "forced removal failure unexpectedly succeeded" >&2; exit 1
fi
complete_file_snapshot "$removal_target" "$WORK/removal.after"
diff -u "$WORK/removal.before" "$WORK/removal.after"
[[ -f "$removal_target/$obsolete" ]]
[[ "$CRON_HASH" == "$(sha256sum "$WORK/cron")" ]]
echo "removal rollback restored removed path, replacements, mutable state and CRON"

echo "universal installer integrity, convergence, idempotency and rollback passed"
