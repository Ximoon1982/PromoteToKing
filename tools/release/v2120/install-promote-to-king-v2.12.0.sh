#!/usr/bin/env bash
set -Eeuo pipefail

# Universal, network-independent P2K 2.11.x -> 2.12.0 installer.
# Mutable/local state and system CRON are outside the managed inventory.

readonly DEFAULT_ROOT="/kunden/homepages/43/d141198007/htdocs/PromoteToKing"
readonly TARGET=${1:-"$DEFAULT_ROOT"}
readonly MODE=${2:-install}
readonly PACKAGE_ROOT=$(cd "$(dirname "$0")" && pwd)
readonly PAYLOAD="$PACKAGE_ROOT/payload"
readonly FILES="$PACKAGE_ROOT/FILES.list"
readonly REMOVALS="$PACKAGE_ROOT/REMOVALS.list"
readonly MODES="$PACKAGE_ROOT/MODES.list"
readonly BASELINE_HASHES="$PACKAGE_ROOT/SUPPORTED_BASELINES.sha256"
readonly BASELINE_TREES="$PACKAGE_ROOT/SUPPORTED_TREES.sha256"
readonly PACKAGE_MANIFEST="$PACKAGE_ROOT/PACKAGE-MANIFEST.sha256"
readonly PATH_SELECTOR="$PACKAGE_ROOT/production_paths.py"
readonly RECRUITMENT_KEY="p2k-2.12.0-d025d8c46103-8e4b12768d33959c"
readonly SITE_CONFIG_KEY="p2k-2.12.0-d025d8c46103-ae73ae796f09667d"

WORK=""; STAGE=""; BACKUP=""; TRANSACTION_ACTIVE=0
ROLLBACK_SUCCEEDED=0
PRESERVE_BACKUP=0

log() { printf '%s\n' "$*"; }

fatal() {
  printf 'ERROR: %s\n' "$*" >&2
  if ((TRANSACTION_ACTIVE)) && ! attempt_automatic_rollback; then
    exit 3
  fi
  exit 2
}

safe_remove_temp_tree() {
  local path=${1:-}
  [[ -n "$path" && -e "$path" ]] || return 0
  case "$path" in
    "$TARGET"/.p2k-v2120-*) rm -rf -- "$path" ;;
    *) return 1 ;;
  esac
}

cleanup() {
  safe_remove_temp_tree "$WORK" || true
  safe_remove_temp_tree "$STAGE" || true
  # A failed automatic rollback must never destroy the only recovery backup.
  if ((PRESERVE_BACKUP == 0 && (TRANSACTION_ACTIVE == 0 || ROLLBACK_SUCCEEDED == 1))); then
    safe_remove_temp_tree "$BACKUP" || true
  fi
}

rollback() {
  ((TRANSACTION_ACTIVE)) || return 0
  log "Installation failed; restoring the complete pre-install immutable state." >&2
  if [[ -f "$BACKUP/absent-targets.list" ]]; then
    while IFS= read -r path; do
      [[ -z "$path" ]] || rm -f -- "$TARGET/$path" || return 1
    done <"$BACKUP/absent-targets.list"
  fi
  # Qualification-only hook: simulate restoration failure after rollback began.
  [[ ${P2K_FORCE_ROLLBACK_FAILURE:-0} != 1 ]] || return 1
  if [[ -s "$BACKUP/existing.tar" ]]; then
    tar -C "$TARGET" -xf "$BACKUP/existing.tar" || return 1
  fi
  ROLLBACK_SUCCEEDED=1
  return 0
}

attempt_automatic_rollback() {
  if rollback; then
    TRANSACTION_ACTIVE=0
    return 0
  fi

  PRESERVE_BACKUP=1
  printf '\nFATAL: AUTOMATIC ROLLBACK FAILED. MANUAL RECOVERY IS REQUIRED.\n' >&2
  printf 'Recovery backup preserved at: %s\n' "$BACKUP" >&2
  printf 'Do not delete this directory. It contains existing.tar and the original-state records.\n' >&2
  printf 'Inspect and restore existing.tar from the target root before retrying the installer.\n\n' >&2
  return 1
}

handle_failure() {
  local status=$?
  trap - ERR INT TERM
  if ((TRANSACTION_ACTIVE)) && ! attempt_automatic_rollback; then
    status=3
  fi
  cleanup
  exit "$status"
}
trap handle_failure ERR INT TERM
trap cleanup EXIT

require_utility() {
  command -v "$1" >/dev/null 2>&1 || fatal "required utility is unavailable: $1"
}

validate_relative_path_list() {
  local list=$1 path
  while IFS= read -r path; do
    [[ -n "$path" ]] || continue
    [[ "$path" != /* && "$path" != "." && "$path" != ".." ]] \
      || fatal "unsafe path in $(basename "$list"): $path"
    [[ "/$path/" != *"/../"* && "/$path/" != *"/./"* ]] \
      || fatal "unsafe path in $(basename "$list"): $path"
  done <"$list"
}

verify_package() {
  local required=(
    "$PAYLOAD" "$FILES" "$REMOVALS" "$MODES" "$BASELINE_HASHES"
    "$BASELINE_TREES" "$PACKAGE_MANIFEST" "$PATH_SELECTOR"
    "$PACKAGE_ROOT/RELEASE-IDENTITY.txt" "$PACKAGE_ROOT/README.md"
  )
  local item
  for item in "${required[@]}"; do
    [[ -e "$item" ]] || fatal "installer package is incomplete: $(basename "$item")"
  done
  validate_relative_path_list "$FILES"
  validate_relative_path_list "$REMOVALS"
  (cd "$PACKAGE_ROOT" && sha256sum -c --quiet PACKAGE-MANIFEST.sha256) \
    || fatal "installer package integrity check failed"

  (cd "$PACKAGE_ROOT" && find . -type f ! -name PACKAGE-MANIFEST.sha256 -print \
    | sed 's#^\./##' | sort) >"$WORK/package-files.actual"
  sed -E 's/^[0-9a-f]{64}  //' "$PACKAGE_MANIFEST" | sort \
    >"$WORK/package-files.expected"
  cmp -s "$WORK/package-files.expected" "$WORK/package-files.actual" \
    || fatal "package file inventory does not match PACKAGE-MANIFEST.sha256"

  find "$PAYLOAD" -type f -print | sed "s#^$PAYLOAD/##" | sort \
    >"$WORK/payload-files.actual"
  cmp -s "$FILES" "$WORK/payload-files.actual" \
    || fatal "payload inventory does not match FILES.list"
  awk -F '\t' 'NF != 2 || $2 !~ /^[0-7][0-7][0-7]$/ {exit 1} {print $1}' \
    "$MODES" >"$WORK/mode-files.actual" || fatal "MODES.list is malformed"
  cmp -s "$FILES" "$WORK/mode-files.actual" \
    || fatal "mode inventory does not match FILES.list"
  comm -12 "$FILES" "$REMOVALS" >"$WORK/inventory-overlap"
  [[ ! -s "$WORK/inventory-overlap" ]] || fatal "target and removal inventories overlap"
}

enumerate_installed_tree() {
  python3 "$PATH_SELECTOR" --root "$TARGET" >"$1"
}

hash_installed_tree() {
  local inventory=$1 output=$2 path hash
  : >"$output"
  while IFS= read -r path; do
    [[ -n "$path" ]] || continue
    [[ -f "$TARGET/$path" && ! -L "$TARGET/$path" ]] \
      || fatal "managed path is not a regular file: $path"
    hash=$(sha256sum "$TARGET/$path" | awk '{print $1}')
    printf '%s  %s\n' "$hash" "$path" >>"$output"
  done <"$inventory"
}

verify_supported_baseline() {
  local inventory="$WORK/source.files" hashes="$WORK/source.hashes" tree_hash match
  enumerate_installed_tree "$inventory"
  hash_installed_tree "$inventory" "$hashes"
  tree_hash=$(sha256sum "$hashes" | awk '{print $1}')
  match=$(awk -F '\t' -v hash="$tree_hash" '$1 == hash {print $0}' "$BASELINE_TREES")
  [[ -n "$match" ]] || fatal \
    "installed immutable tree is not an exact supported $OLD_VERSION baseline"
  log "Accepted canonical baseline: $(printf '%s' "$match" | cut -f2-3)"
}

check_destination_parents() {
  local path parent
  for inventory in "$FILES" "$REMOVALS"; do
    while IFS= read -r path; do
      [[ -n "$path" ]] || continue
      parent="$TARGET/$(dirname "$path")"
      while [[ ! -e "$parent" && "$parent" != "$TARGET" ]]; do parent=$(dirname "$parent"); done
      [[ -d "$parent" && -w "$parent" ]] || fatal "destination parent is not writable: $parent"
    done <"$inventory"
  done
}

check_disk_space() {
  local payload_kb existing_kb largest_file_kb required_kb available_kb
  payload_kb=$(du -sk "$PAYLOAD" | awk '{print $1}')
  existing_kb=$(python3 - "$TARGET" "$WORK/source.files" <<'PY'
from pathlib import Path
import sys
root = Path(sys.argv[1])
total = sum((root / p).stat().st_size for p in Path(sys.argv[2]).read_text().splitlines())
print((total + 1023) // 1024)
PY
)
  largest_file_kb=$(python3 - "$PAYLOAD" <<'PY'
from pathlib import Path
import sys
largest = max((path.stat().st_size for path in Path(sys.argv[1]).rglob("*") if path.is_file()), default=0)
print((largest + 1023) // 1024)
PY
)
  # Peak estimate: staged payload + original backup + largest atomic activation
  # temporary, then a 10% safety allowance and 16 MiB fixed headroom.
  required_kb=$(( (payload_kb + existing_kb + largest_file_kb) * 110 / 100 + 16384 ))
  available_kb=$(df -Pk "$TARGET" | awk 'NR == 2 {print $4}')
  ((available_kb >= required_kb)) || fatal \
    "insufficient disk space: need ${required_kb} KiB, have ${available_kb} KiB"
}

preflight() {
  [[ "$MODE" == install || "$MODE" == verify || "$MODE" == check ]] \
    || fatal "usage: installer [root] [install|verify|check]"
  [[ -d "$TARGET" ]] || fatal "target directory does not exist: $TARGET"
  [[ -f "$TARGET/VERSION" ]] || fatal "target VERSION is missing"
  local utility
  for utility in awk cmp comm cp cut df diff du find grep install mkdir mktemp \
    mv python3 rm sed sha256sum sort tar tr wc; do require_utility "$utility"; done
  WORK=$(mktemp -d "$TARGET/.p2k-v2120-preflight.XXXXXX") \
    || fatal "cannot create temporary workspace in target"
  [[ -w "$WORK" ]] || fatal "temporary workspace is not writable"
  verify_package
  OLD_VERSION=$(tr -d '\r\n[:space:]' <"$TARGET/VERSION")
  if [[ "$MODE" == install && "$OLD_VERSION" != "2.12.0" ]]; then
    [[ "$OLD_VERSION" == 2.11.* ]] || fatal "unsupported installed version: $OLD_VERSION"
    verify_supported_baseline
    check_destination_parents
    check_disk_space
  fi
}

verify_installed() {
  local version path expected actual
  version=$(tr -d '\r\n[:space:]' <"$TARGET/VERSION")
  [[ "$version" == "2.12.0" ]] || fatal "semantic version is not 2.12.0"
  enumerate_installed_tree "$WORK/installed.files"
  cmp -s "$FILES" "$WORK/installed.files" \
    || fatal "installed immutable path inventory is not the exact v2.12.0 inventory"
  while IFS= read -r path; do
    expected=$(sha256sum "$PAYLOAD/$path" | awk '{print $1}')
    actual=$(sha256sum "$TARGET/$path" | awk '{print $1}')
    [[ "$actual" == "$expected" ]] || fatal "installed file mismatch: $path"
  done <"$FILES"
  while IFS= read -r path; do
    [[ -z "$path" || ! -e "$TARGET/$path" ]] \
      || fatal "obsolete managed immutable file remains installed: $path"
  done <"$REMOVALS"
  grep -Fq 'version: "2.12.0"' "$TARGET/assets/js/site-config.js" || fatal "runtime version mismatch"
  grep -Fq "$RECRUITMENT_KEY" "$TARGET/RecruitMatch.html" || fatal "recruitment key missing"
  grep -Fq "$SITE_CONFIG_KEY" "$TARGET/RecruitMatch.html" || fatal "site-config key missing"
  log "v2.12.0 verification passed ($(wc -l <"$FILES") immutable files; no obsolete managed files)."
}

capture_cron() {
  CRONTAB_COMMAND=$(command -v crontab || true)
  if [[ -n "$CRONTAB_COMMAND" ]]; then
    "$CRONTAB_COMMAND" -l >"$BACKUP/cron.before" 2>/dev/null || : >"$BACKUP/cron.before"
  fi
}

stage_and_backup() {
  STAGE=$(mktemp -d "$TARGET/.p2k-v2120-stage.XXXXXX")
  BACKUP=$(mktemp -d "$TARGET/.p2k-v2120-backup.XXXXXX")
  cp -a "$PAYLOAD/." "$STAGE/"
  : >"$BACKUP/absent-targets.list"
  local existing=() path
  while IFS= read -r path; do
    if [[ -e "$TARGET/$path" ]]; then existing+=("$path")
    else printf '%s\n' "$path" >>"$BACKUP/absent-targets.list"; fi
  done <"$FILES"
  while IFS= read -r path; do
    [[ -z "$path" || ! -e "$TARGET/$path" ]] || existing+=("$path")
  done <"$REMOVALS"
  if (("${#existing[@]}")); then tar -C "$TARGET" -cf "$BACKUP/existing.tar" "${existing[@]}"
  else : >"$BACKUP/existing.tar"; fi
  capture_cron
}

activate_payload() {
  local path mode temporary count=0
  while IFS= read -r path; do
    mkdir -p "$TARGET/$(dirname "$path")"
    mode=$(awk -F '\t' -v wanted="$path" '$1 == wanted {print $2}' "$MODES")
    temporary="$TARGET/$(dirname "$path")/.p2k-v2120.$(basename "$path").tmp"
    install -m "$mode" "$STAGE/$path" "$temporary"
    mv -f "$temporary" "$TARGET/$path"
    count=$((count + 1))
    [[ ${P2K_FORCE_INSTALL_FAILURE_AFTER:-0} != "$count" ]] || false
  done <"$FILES"
}

remove_obsolete_files() {
  local path removed=0
  while IFS= read -r path; do
    [[ -n "$path" ]] || continue
    if [[ -e "$TARGET/$path" ]]; then rm -f -- "$TARGET/$path"; removed=$((removed + 1)); fi
  done <"$REMOVALS"
  ((removed == 0)) || log "Removed $removed obsolete managed immutable file(s)."
  [[ ${P2K_FORCE_INSTALL_FAILURE_PHASE:-} != "after-removals" ]] || false
}

verify_cron_unchanged() {
  [[ -n "${CRONTAB_COMMAND:-}" ]] || return 0
  "$CRONTAB_COMMAND" -l >"$BACKUP/cron.after" 2>/dev/null || : >"$BACKUP/cron.after"
  cmp -s "$BACKUP/cron.before" "$BACKUP/cron.after" || fatal "system CRON changed"
}

main() {
  preflight
  if [[ "$MODE" != install ]]; then verify_installed; return; fi
  if [[ "$OLD_VERSION" == "2.12.0" ]]; then
    verify_installed
    log "v2.12.0 is already installed; no change was required."
    return
  fi
  stage_and_backup
  TRANSACTION_ACTIVE=1
  activate_payload
  remove_obsolete_files
  verify_installed
  verify_cron_unchanged
  TRANSACTION_ACTIVE=0
  log "Upgraded transactionally from $OLD_VERSION to 2.12.0; mutable state and CRON preserved."
}

main
