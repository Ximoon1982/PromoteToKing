#!/usr/bin/env bash
set -euo pipefail
ROOT_REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
FAKE="$TMP/p2k"; mkdir -p "$FAKE/server/release-control/src" "$FAKE/server/team-points/bin" "$FAKE/data/runtime-v280/release-control"
cp "$ROOT_REPO/server/release-control/src/ReleaseStateStore.php" "$FAKE/server/release-control/src/ReleaseStateStore.php"
cat > "$FAKE/server/team-points/bin/mca-results-sync.php" <<'PHP'
<?php echo 'physical';
PHP
chmod +x "$FAKE/server/team-points/bin/mca-results-sync.php"
export P2K_MCA_PHYSICAL_ROOT="$FAKE" P2K_PHP_CLI="$(command -v php)"
read_target(){ bash "$ROOT_REPO/cron-mca-arena-v2.10.9.sh" --print-target; }
phys="$FAKE/server/team-points/bin/mca-results-sync.php"
[[ "$(read_target)" == "MCA active worker: $phys" ]] || { echo 'Missing state did not fall back to physical root';exit 1; }
echo '{"mode":"direct-root"}' > "$FAKE/data/runtime-v280/release-control/state.json"
[[ "$(read_target)" == "MCA active worker: $phys" ]] || { echo 'direct-root routing mismatch';exit 1; }
mk_release(){
 local id="$1" digest="$2"
 local dir="$FAKE/data/runtime-v280/release-control/runtime-trees/$id"
 mkdir -p "$dir/meta" "$dir/app/server/team-points/bin"
 printf '{"release_id":"%s","slot_manifest_sha256":"%s"}\n' "$id" "$digest" > "$dir/meta/runtime.json"
 printf '<?php echo "runtime";\n' > "$dir/app/server/team-points/bin/mca-results-sync.php"
}
ONE="2.15.9-123456789abc"; TWO="2.15.10-abcdef012345"; HASH="$(printf 'a%.0s' {1..64})"
mk_release "$ONE" "$HASH"
printf '{"mode":"slots","public_release":"%s","public_manifest_sha256":"%s"}\n' "$ONE" "$HASH" > "$FAKE/data/runtime-v280/release-control/state.json"
[[ "$(read_target)" == *"/runtime-trees/$ONE/app/server/team-points/bin/mca-results-sync.php" ]] || { echo 'promoted runtime routing mismatch';exit 1; }
mk_release "$TWO" "$HASH"
printf '{"mode":"slots","public_release":"%s","public_manifest_sha256":"%s","candidate_release":"%s"}\n' "$TWO" "$HASH" "$ONE" > "$FAKE/data/runtime-v280/release-control/state.json"
[[ "$(read_target)" == *"/runtime-trees/$TWO/app/server/team-points/bin/mca-results-sync.php" ]] || { echo 'second promotion routing mismatch';exit 1; }
# Switching the public slot to an older one must immediately switch the CLI worker.
printf '{"mode":"slots","public_release":"%s","public_manifest_sha256":"%s","candidate_release":"%s"}\n' "$ONE" "$HASH" "$TWO" > "$FAKE/data/runtime-v280/release-control/state.json"
[[ "$(read_target)" == *"/runtime-trees/$ONE/app/server/team-points/bin/mca-results-sync.php" ]] || { echo 'rollback routing mismatch';exit 1; }
# One-time bootstrap checks and applies only in an isolated fake physical root.
printf '#!/usr/bin/env bash\necho old-cron\n' > "$FAKE/cron-mca-arena-v2.10.9.sh"
bash "$ROOT_REPO/tools/install-mca-cron-dispatcher-v21510.sh" "$FAKE" --check > "$TMP/preflight.out"
grep -q old-cron "$FAKE/cron-mca-arena-v2.10.9.sh"
bash "$ROOT_REPO/tools/install-mca-cron-dispatcher-v21510.sh" "$FAKE" --apply > "$TMP/applied.out"
[[ "$(bash "$FAKE/cron-mca-arena-v2.10.9.sh" --print-target)" == *"/runtime-trees/$ONE/app/server/team-points/bin/mca-results-sync.php" ]] || { echo 'Physical CRON activation did not route to public slot';exit 1; }
compgen -G "$FAKE/cron-mca-arena-v2.10.9.sh.before-v21510-*" > /dev/null || { echo 'Missing physical launcher backup';exit 1; }
bash "$ROOT_REPO/tools/install-mca-cron-dispatcher-v21510.sh" "$FAKE" --apply > "$TMP/idempotent.out"
grep -q 'Already installed' "$TMP/idempotent.out"
# A malformed public state must fail closed, never silently run stale physical code.
echo '{"mode":"slots","public_release":"../evil","public_manifest_sha256":"bad"}' > "$FAKE/data/runtime-v280/release-control/state.json"
if read_target > "$TMP/invalid.out" 2>&1; then echo 'Invalid release accepted';exit 1;fi
if ! grep -q 'MCA CRON dispatch blocked' "$TMP/invalid.out"; then echo 'Missing failure diagnostics';exit 1;fi
printf 'MCA v2.15.10 dispatcher promotion/rollback/direct-root/fail-closed tests: PASS\n'
