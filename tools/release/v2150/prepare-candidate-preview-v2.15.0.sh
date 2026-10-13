#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
ACTOR="${2:-ximoon}"
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
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

[[ -f "$SELF_DIR/CANDIDATE_RELEASE.json" ]] || fail "Candidate metadata is missing"
[[ -f "$SELF_DIR/CANDIDATE_PAYLOAD.sha256" ]] || fail "Candidate manifest is missing"
grep -Fq '"version": "2.15.0"' "$SELF_DIR/CANDIDATE_RELEASE.json" || fail "Candidate package is not v2.15.0"

(
  cd "$SELF_DIR/payload"
  sha256sum -c ../CANDIDATE_PAYLOAD.sha256 >/dev/null
) || fail "Candidate payload verification failed"

"$PHP_BIN" -l "$SELF_DIR/payload/server/team-points-green/tools/audit-club-points.php" >/dev/null || fail "PHP lint failed: audit-club-points.php"

"$PHP_BIN" "$ROOT/server/release-control/tools/install-candidate.php" \
  --root="$ROOT" \
  --package="$SELF_DIR" \
  --actor="$ACTOR" \
  --replace-candidate >"/tmp/p2k-v2150-candidate-$$.json" \
  || fail "Candidate installation/registration failed"

grep -Fq '"ok": true' "/tmp/p2k-v2150-candidate-$$.json" || fail "Candidate installer did not report success"
cat "/tmp/p2k-v2150-candidate-$$.json"
rm -f "/tmp/p2k-v2150-candidate-$$.json"

"$PHP_BIN" "$ROOT/server/release-control/tools/prepare-preview.php" \
  --root="$ROOT" >"/tmp/p2k-v2150-preview-$$.json" \
  || fail "Candidate preview-tree preparation failed"
grep -Fq '"ok": true' "/tmp/p2k-v2150-preview-$$.json" || fail "Preview-tree preparer did not report success"
cat "/tmp/p2k-v2150-preview-$$.json"
rm -f "/tmp/p2k-v2150-preview-$$.json"

printf '\nSUCCESS: v2.15.0 candidate installed and preview prepared. Public release is unchanged until explicit promotion.\n'
printf 'Open: https://www.promotetoking.org/ReleaseControl.php\n'
