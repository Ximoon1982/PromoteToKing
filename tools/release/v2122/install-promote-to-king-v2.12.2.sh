#!/usr/bin/env bash
set -Eeuo pipefail

# Scope-limited, network-independent P2K 2.12.x -> 2.12.2 cumulative overlay installer.
# Only FILES.list is touched. Mutable state, unrelated application files and
# system CRON are outside the transaction and must remain byte-for-byte intact.
readonly DEFAULT_ROOT="/kunden/homepages/43/d141198007/htdocs/PromoteToKing"
readonly TARGET=${1:-"$DEFAULT_ROOT"}
readonly MODE=${2:-install}
readonly PACKAGE_ROOT=$(cd "$(dirname "$0")" && pwd)
readonly PAYLOAD="$PACKAGE_ROOT/payload"
readonly FILES="$PACKAGE_ROOT/FILES.list"
readonly MODES="$PACKAGE_ROOT/MODES.list"
readonly PACKAGE_MANIFEST="$PACKAGE_ROOT/PACKAGE-MANIFEST.sha256"
readonly RELEASE_IDENTITY="$PACKAGE_ROOT/RELEASE-IDENTITY.txt"
WORK=""; STAGE=""; BACKUP=""; TRANSACTION_ACTIVE=0; ROLLBACK_SUCCEEDED=0; PRESERVE_BACKUP=0; CRONTAB_COMMAND=""

log(){ printf '%s\n' "$*"; }
safe_remove(){ local p=${1:-}; [[ -n "$p" && -e "$p" ]] || return 0; case "$p" in "$TARGET"/.p2k-v2122-*) rm -rf -- "$p";; *) return 1;; esac; }
cleanup(){ safe_remove "$WORK" || true; safe_remove "$STAGE" || true; if ((PRESERVE_BACKUP==0 && (TRANSACTION_ACTIVE==0 || ROLLBACK_SUCCEEDED==1))); then safe_remove "$BACKUP" || true; fi; }
rollback(){
  ((TRANSACTION_ACTIVE)) || return 0
  log 'Installation failed; restoring the v2.12.2 overlay transaction.' >&2
  if [[ -f "$BACKUP/absent-before.list" ]]; then
    while IFS= read -r p; do [[ -z "$p" ]] || rm -f -- "$TARGET/$p" || return 1; done <"$BACKUP/absent-before.list"
  fi
  [[ ${P2K_FORCE_ROLLBACK_FAILURE:-0} != 1 ]] || return 1
  if [[ -s "$BACKUP/existing.tar" ]]; then tar -C "$TARGET" -xf "$BACKUP/existing.tar" || return 1; fi
  ROLLBACK_SUCCEEDED=1
}
attempt_rollback(){ if rollback; then TRANSACTION_ACTIVE=0; return 0; fi; PRESERVE_BACKUP=1; printf '\nFATAL: AUTOMATIC ROLLBACK FAILED. Backup preserved at %s\n' "$BACKUP" >&2; return 1; }
fail(){ printf 'ERROR: %s\n' "$*" >&2; if ((TRANSACTION_ACTIVE)) && ! attempt_rollback; then exit 3; fi; exit 2; }
handle_failure(){ local status=$?; trap - ERR INT TERM; if ((TRANSACTION_ACTIVE)) && ! attempt_rollback; then status=3; fi; cleanup; exit "$status"; }
trap handle_failure ERR INT TERM
trap cleanup EXIT
require(){ command -v "$1" >/dev/null 2>&1 || fail "required utility unavailable: $1"; }
validate_list(){ local f=$1 p; while IFS= read -r p; do [[ -n "$p" ]] || continue; [[ "$p" != /* && "$p" != . && "$p" != .. && "/$p/" != *'/../'* && "/$p/" != *'/./'* ]] || fail "unsafe path in $(basename "$f"): $p"; done <"$f"; }

verify_package(){
  local req=("$PAYLOAD" "$FILES" "$MODES" "$PACKAGE_MANIFEST" "$RELEASE_IDENTITY" "$PACKAGE_ROOT/README.md") item
  for item in "${req[@]}"; do [[ -e "$item" ]] || fail "installer package incomplete: $(basename "$item")"; done
  validate_list "$FILES"
  (cd "$PACKAGE_ROOT" && sha256sum -c --quiet PACKAGE-MANIFEST.sha256) || fail 'package integrity check failed'
  find "$PAYLOAD" -type f -print | sed "s#^$PAYLOAD/##" | sort >"$WORK/payload.actual"
  cmp -s "$FILES" "$WORK/payload.actual" || fail 'payload inventory mismatch'
  awk -F '\t' 'NF!=2 || $2!~/^[0-7][0-7][0-7]$/ {exit 1} {print $1}' "$MODES" >"$WORK/modes.actual" || fail 'MODES.list malformed'
  cmp -s "$FILES" "$WORK/modes.actual" || fail 'mode inventory mismatch'
}

preflight(){
  [[ "$MODE" == install || "$MODE" == verify || "$MODE" == check ]] || fail 'usage: installer [root] [install|verify|check]'
  [[ -d "$TARGET" && -f "$TARGET/VERSION" ]] || fail 'target or VERSION missing'
  local u; for u in awk cmp cp find grep install mkdir mktemp mv rm sed sha256sum sort tar tr wc; do require "$u"; done
  WORK=$(mktemp -d "$TARGET/.p2k-v2122-preflight.XXXXXX")
  verify_package
  OLD_VERSION=$(tr -d '\r\n[:space:]' <"$TARGET/VERSION")
  if [[ "$MODE" == install && "$OLD_VERSION" != 2.12.2 ]]; then
    [[ "$OLD_VERSION" == 2.12.0 || "$OLD_VERSION" == 2.12.1 ]] || fail "scope-limited installer requires P2K 2.12.0 or 2.12.1; found $OLD_VERSION"
  fi
}

verify_installed(){
  local version path expected actual key
  version=$(tr -d '\r\n[:space:]' <"$TARGET/VERSION")
  [[ "$version" == 2.12.2 ]] || fail 'semantic version is not 2.12.2'
  while IFS= read -r path; do
    expected=$(sha256sum "$PAYLOAD/$path" | awk '{print $1}')
    [[ -f "$TARGET/$path" && ! -L "$TARGET/$path" ]] || fail "installed overlay file missing: $path"
    actual=$(sha256sum "$TARGET/$path" | awk '{print $1}')
    [[ "$actual" == "$expected" ]] || fail "installed overlay file mismatch: $path"
  done <"$FILES"
  grep -Fq 'version: "2.12.2"' "$TARGET/assets/js/site-config.js" || fail 'runtime semantic version mismatch'
  key=$(awk -F= '$1=="asset_cache_key" {print substr($0,index($0,"=")+1)}' "$RELEASE_IDENTITY")
  [[ -n "$key" ]] || fail 'asset cache key missing'
  grep -Fq "$key" "$TARGET/assets/js/admin/tool-registry.js" || fail 'admin loader asset key missing'
  grep -Fq "$key" "$TARGET/server/events-showcase/public/embed.php" || fail 'showcase line embed asset key missing'
  grep -Fq "$key" "$TARGET/server/events-showcase/public/embed-card.php" || fail 'showcase card embed asset key missing'
  log "v2.12.2 cumulative overlay verification passed ($(wc -l <"$FILES") files)."
}

capture_cron(){ CRONTAB_COMMAND=$(command -v crontab || true); if [[ -n "$CRONTAB_COMMAND" ]]; then "$CRONTAB_COMMAND" -l >"$BACKUP/cron.before" 2>/dev/null || : >"$BACKUP/cron.before"; fi; }
stage_and_backup(){
  STAGE=$(mktemp -d "$TARGET/.p2k-v2122-stage.XXXXXX")
  BACKUP=$(mktemp -d "$TARGET/.p2k-v2122-backup.XXXXXX")
  cp -a "$PAYLOAD/." "$STAGE/"
  : >"$BACKUP/absent-before.list"
  local existing=() path
  while IFS= read -r path; do
    if [[ -e "$TARGET/$path" ]]; then existing+=("$path"); else printf '%s\n' "$path" >>"$BACKUP/absent-before.list"; fi
  done <"$FILES"
  if (( ${#existing[@]} )); then tar -C "$TARGET" -cf "$BACKUP/existing.tar" "${existing[@]}"; else : >"$BACKUP/existing.tar"; fi
  capture_cron
}
activate(){
  local path mode temporary count=0
  while IFS= read -r path; do
    mkdir -p "$TARGET/$(dirname "$path")"
    mode=$(awk -F '\t' -v wanted="$path" '$1==wanted {print $2}' "$MODES")
    temporary="$TARGET/$(dirname "$path")/.p2k-v2122.$(basename "$path").tmp"
    install -m "$mode" "$STAGE/$path" "$temporary"
    mv -f "$temporary" "$TARGET/$path"
    count=$((count+1))
    [[ ${P2K_FORCE_INSTALL_FAILURE_AFTER:-0} != "$count" ]] || false
  done <"$FILES"
}
verify_cron(){ [[ -n "$CRONTAB_COMMAND" ]] || return 0; "$CRONTAB_COMMAND" -l >"$BACKUP/cron.after" 2>/dev/null || : >"$BACKUP/cron.after"; cmp -s "$BACKUP/cron.before" "$BACKUP/cron.after" || fail 'system CRON changed'; }
main(){
  preflight
  if [[ "$MODE" != install ]]; then verify_installed; return; fi
  if [[ "$OLD_VERSION" == 2.12.2 ]]; then verify_installed; log 'v2.12.2 overlay already installed; no change required.'; return; fi
  stage_and_backup
  TRANSACTION_ACTIVE=1
  activate
  verify_installed
  verify_cron
  TRANSACTION_ACTIVE=0
  log "Upgraded P2K $OLD_VERSION to v2.12.2 using the cumulative 2.12.x incremental overlay; mutable state, unrelated files and CRON were preserved."
}
main
