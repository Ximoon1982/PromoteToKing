#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
ACTOR="${2:-ximoon}"
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PAYLOAD="$SELF_DIR/payload"
PHP_BIN="${P2K_PHP_CLI:-}"

fail(){ printf 'ERROR: %s\n' "$*" >&2; exit 1; }

if [[ -z "$PHP_BIN" ]]; then
  for c in /usr/bin/php8.5-cli /usr/bin/php8.5 /usr/bin/php8.4-cli /usr/bin/php8.4 /usr/bin/php8.3-cli /usr/bin/php8.3 /usr/bin/php; do
    if [[ -x "$c" ]]; then PHP_BIN="$c"; break; fi
  done
fi
[[ -n "$PHP_BIN" && -x "$PHP_BIN" ]] || fail "PHP CLI not found"

[[ -d "$ROOT" ]] || fail "P2K root not found: $ROOT"
[[ -f "$ROOT/VERSION" ]] || fail "Installed VERSION is missing"
[[ "$(tr -d '\r\n' < "$ROOT/VERSION")" == "2.14.2" ]] || fail "Candidate-preview bootstrap requires public v2.14.2"
grep -Fq 'p2k-2.14.2-9257577544d8-' "$ROOT/ui-v2.html" || fail "Public v2.14.2 build identity is not the qualified 9257577544d8 baseline"

[[ -f "$SELF_DIR/CANDIDATE_RELEASE.json" ]] || fail "Candidate metadata is missing"
[[ -f "$SELF_DIR/CANDIDATE_PAYLOAD.sha256" ]] || fail "Candidate manifest is missing"
grep -Fq '"version": "2.14.3"' "$SELF_DIR/CANDIDATE_RELEASE.json" || fail "Candidate package is not v2.14.3"
grep -Fq '"version": "2.14.2"' "$SELF_DIR/CANDIDATE_RELEASE.json" || fail "Candidate package does not accept public v2.14.2"

(
  cd "$PAYLOAD"
  sha256sum -c ../PAYLOAD.sha256 >/dev/null
) || fail "Package payload verification failed"

INFRA_FILES=(
  .htaccess
  ReleaseControl.php
  PreviewRouter.php
  server/release-control/config/.htaccess
  server/release-control/config/config.example.php
  server/release-control/src/bootstrap.php
  server/release-control/src/ReleaseControlAuth.php
  server/release-control/src/ReleaseControlState.php
  server/release-control/src/ReleaseSlotPolicy.php
  server/release-control/src/ReleaseSlotFilesystemProbe.php
  server/release-control/src/ReleaseSlotStore.php
  server/release-control/src/ReleaseSlotMaterializer.php
  server/release-control/src/ReleaseCandidatePackage.php
  server/release-control/src/ReleaseStateStore.php
  server/release-control/src/ReleaseCandidateInstaller.php
  server/release-control/src/ReleasePreviewTree.php
  server/release-control/src/ReleasePreviewSession.php
  server/release-control/tools/materialize-current-slot.php
  server/release-control/tools/install-candidate.php
  server/release-control/tools/prepare-preview.php
  server/release-control/tools/verify-slot.php
  server/release-control/tools/release-slot-paths.py
  server/release-control/README.md
)

PHP_INFRA=(
  ReleaseControl.php
  PreviewRouter.php
  server/release-control/config/config.example.php
  server/release-control/src/bootstrap.php
  server/release-control/src/ReleaseControlAuth.php
  server/release-control/src/ReleaseControlState.php
  server/release-control/src/ReleaseSlotPolicy.php
  server/release-control/src/ReleaseSlotFilesystemProbe.php
  server/release-control/src/ReleaseSlotStore.php
  server/release-control/src/ReleaseSlotMaterializer.php
  server/release-control/src/ReleaseCandidatePackage.php
  server/release-control/src/ReleaseStateStore.php
  server/release-control/src/ReleaseCandidateInstaller.php
  server/release-control/src/ReleasePreviewTree.php
  server/release-control/src/ReleasePreviewSession.php
  server/release-control/tools/materialize-current-slot.php
  server/release-control/tools/install-candidate.php
  server/release-control/tools/prepare-preview.php
  server/release-control/tools/verify-slot.php
)

for path in "${INFRA_FILES[@]}"; do
  [[ -f "$PAYLOAD/$path" ]] || fail "Recovery-plane payload file missing: $path"
done
for path in "${PHP_INFRA[@]}"; do
  "$PHP_BIN" -l "$PAYLOAD/$path" >/dev/null || fail "PHP lint failed: $path"
done

before_version="$(sha256sum "$ROOT/VERSION" | awk '{print $1}')"
before_ui="$(sha256sum "$ROOT/ui-v2.html" | awk '{print $1}')"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)-$$"
BACKUP="$ROOT/storage/release-backups/preview-infra-v2.14.3-$STAMP"
mkdir -p "$BACKUP"

restore(){
  local path
  set +e
  for path in "${INFRA_FILES[@]}"; do
    if [[ -f "$BACKUP/$path" || -L "$BACKUP/$path" ]]; then
      mkdir -p "$ROOT/$(dirname "$path")"
      cp -a "$BACKUP/$path" "$ROOT/$path"
    elif [[ -f "$BACKUP/.absent/$path" ]]; then
      rm -f "$ROOT/$path"
    fi
  done
}
trap 'restore' ERR INT TERM HUP

for path in "${INFRA_FILES[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$path")" "$BACKUP/.absent/$(dirname "$path")"
  if [[ -f "$ROOT/$path" || -L "$ROOT/$path" ]]; then
    cp -a "$ROOT/$path" "$BACKUP/$path"
  else
    : > "$BACKUP/.absent/$path"
  fi
done

for path in "${INFRA_FILES[@]}"; do
  dst="$ROOT/$path"
  mkdir -p "$(dirname "$dst")"
  tmp="$dst.p2k-v2143-preview-$$.tmp"
  cp -p "$PAYLOAD/$path" "$tmp"
  mv -f "$tmp" "$dst"
done

for path in "${PHP_INFRA[@]}"; do
  "$PHP_BIN" -l "$ROOT/$path" >/dev/null || fail "Activated recovery-plane PHP lint failed: $path"
done

after_version="$(sha256sum "$ROOT/VERSION" | awk '{print $1}')"
after_ui="$(sha256sum "$ROOT/ui-v2.html" | awk '{print $1}')"
[[ "$before_version" == "$after_version" ]] || fail "Preview bootstrap changed public VERSION"
[[ "$before_ui" == "$after_ui" ]] || fail "Preview bootstrap changed public ui-v2.html"
[[ "$(tr -d '\r\n' < "$ROOT/VERSION")" == "2.14.2" ]] || fail "Public VERSION changed during preview bootstrap"

"$PHP_BIN" "$ROOT/server/release-control/tools/install-candidate.php"   --root="$ROOT"   --package="$SELF_DIR"   --actor="$ACTOR"   --replace-candidate >/tmp/p2k-v2143-candidate-$$.json   || fail "Candidate installation/registration failed"

grep -Fq '"ok": true' /tmp/p2k-v2143-candidate-$$.json || fail "Candidate installer did not report success"
grep -Fq '"release_id": "2.14.3-' /tmp/p2k-v2143-candidate-$$.json || fail "Candidate release identity was not registered"
cat /tmp/p2k-v2143-candidate-$$.json
rm -f /tmp/p2k-v2143-candidate-$$.json

"$PHP_BIN" "$ROOT/server/release-control/tools/prepare-preview.php"   --root="$ROOT" >/tmp/p2k-v2143-preview-$$.json   || fail "Candidate preview-tree preparation failed"
grep -Fq '"ok": true' /tmp/p2k-v2143-preview-$$.json || fail "Preview-tree preparer did not report success"
cat /tmp/p2k-v2143-preview-$$.json
rm -f /tmp/p2k-v2143-preview-$$.json

trap - ERR INT TERM HUP
printf '\nSUCCESS: v2.14.3 preview infrastructure is active; public application remains v2.14.2.\n'
printf 'Recovery/preview infrastructure backup: %s\n' "$BACKUP"
printf 'Open: https://www.promotetoking.org/ReleaseControl.php\n'
