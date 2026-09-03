#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
BUILD=${1:?Usage: qualify-v2112-installer.sh BUILD_DIRECTORY}
INSTALLER="$BUILD/PromoteToKing_v2.11.2_STRUCTURAL_CONSOLIDATION.run"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

BASELINES=(
  "v2.11.0-R6:93480c852fc4c554c9a404e5d68b0ac51efed04b"
  "v2.11.1:b8bf26c7c41ca1914323717766bca995139291aa"
  "current-2.11.x:HEAD"
)

tree_hashes() {
  local target=$1 output=$2
  (cd "$target" && find . -type f -print0 | sort -z | xargs -0 sha256sum) > "$output"
}

install_protected_fixtures() {
  local target=$1
  mkdir -p "$target/config/local" "$target/data/qualification" "$target/server/team-points/storage"
  printf 'local-secret=preserve-byte-for-byte\n' > "$target/config/local/v2112-secret.conf"
  printf '{"runtime":"preserve-byte-for-byte"}\n' > "$target/data/qualification/v2112-state.json"
  printf 'persistent-state=preserve-byte-for-byte\n' > "$target/server/team-points/storage/v2112-state.txt"
}

protected_hashes() {
  local target=$1 output=$2
  (cd "$target" && sha256sum \
    config/local/v2112-secret.conf \
    data/qualification/v2112-state.json \
    server/team-points/storage/v2112-state.txt) > "$output"
}

mkdir -p "$WORK/bin"
cat > "$WORK/bin/crontab" <<'CRONTAB'
#!/usr/bin/env bash
set -euo pipefail
test "${1:-}" = -l
cat "$P2K_QUALIFICATION_CRONTAB"
CRONTAB
chmod +x "$WORK/bin/crontab"
printf '# P2K qualification CRON fixture\n17 3 * * * /srv/p2k/cron.sh --unchanged\n' > "$WORK/crontab"
CRON_BEFORE=$(sha256sum "$WORK/crontab")

test -x "$INSTALLER"
(cd "$BUILD" && sha256sum -c SHA256SUMS_v2.11.2.txt)

for entry in "${BASELINES[@]}"; do
  label=${entry%%:*}
  revision=${entry#*:}
  target="$WORK/$label"
  rollback="$WORK/$label-rollback"
  mkdir "$target" "$rollback"
  git -C "$ROOT" archive "$revision" | tar -x -C "$target"
  git -C "$ROOT" archive "$revision" | tar -x -C "$rollback"
  install_protected_fixtures "$target"
  install_protected_fixtures "$rollback"

  tree_hashes "$target" "$WORK/$label.before"
  protected_hashes "$target" "$WORK/$label.protected-before"
  PATH="$WORK/bin:$PATH" P2K_QUALIFICATION_CRONTAB="$WORK/crontab" "$INSTALLER" "$target"
  (cd "$target" && sha256sum -c MANIFEST_v2.11.2.sha256)
  protected_hashes "$target" "$WORK/$label.protected-after"
  diff -u "$WORK/$label.protected-before" "$WORK/$label.protected-after"
  test "$CRON_BEFORE" = "$(sha256sum "$WORK/crontab")"

  tree_hashes "$target" "$WORK/$label.installed"
  PATH="$WORK/bin:$PATH" P2K_QUALIFICATION_CRONTAB="$WORK/crontab" "$INSTALLER" "$target"
  tree_hashes "$target" "$WORK/$label.reinstalled"
  diff -u "$WORK/$label.installed" "$WORK/$label.reinstalled"
  test "$CRON_BEFORE" = "$(sha256sum "$WORK/crontab")"

  tree_hashes "$rollback" "$WORK/$label.rollback-before"
  if PATH="$WORK/bin:$PATH" P2K_QUALIFICATION_CRONTAB="$WORK/crontab" P2K_FORCE_INSTALL_FAILURE=1 "$INSTALLER" "$rollback"; then
    echo "ERROR: forced installer failure unexpectedly succeeded for $label" >&2
    exit 1
  fi
  tree_hashes "$rollback" "$WORK/$label.rollback-after"
  diff -u "$WORK/$label.rollback-before" "$WORK/$label.rollback-after"
  test "$CRON_BEFORE" = "$(sha256sum "$WORK/crontab")"
  echo "Qualified installer over $label ($revision)."
done

echo 'v2.11.2 installer qualification passed for every supported 2.11.x baseline class.'
