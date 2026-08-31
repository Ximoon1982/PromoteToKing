#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-}"
if [[ -z "$ROOT" ]]; then
  ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
else
  ROOT="$(cd "$ROOT" && pwd)"
fi

PHP_BIN="${P2K_PHP_BIN:-$(command -v php || true)}"
if [[ -z "$PHP_BIN" ]]; then
  echo "ERROR: PHP CLI was not found." >&2
  exit 1
fi
if ! command -v crontab >/dev/null 2>&1; then
  echo "ERROR: crontab command is unavailable." >&2
  exit 1
fi

BEGIN_MARKER="# BEGIN P2K FAIR PLAY BACKFILL v2.10.9.7"
END_MARKER="# END P2K FAIR PLAY BACKFILL v2.10.9.7"
TMP_CURRENT="$(mktemp)"
TMP_NEXT="$(mktemp)"
trap 'rm -f "$TMP_CURRENT" "$TMP_NEXT"' EXIT

crontab -l >"$TMP_CURRENT" 2>/dev/null || :
awk -v begin="$BEGIN_MARKER" -v end="$END_MARKER" '
  $0==begin {skip=1; next}
  $0==end {skip=0; next}
  !skip {print}
' "$TMP_CURRENT" >"$TMP_NEXT"

quote_sq() {
  printf "'%s'" "${1//\'/\'\"\'\"\'}"
}

ROOT_Q="$(quote_sq "$ROOT")"
PHP_Q="$(quote_sq "$PHP_BIN")"
SCRIPT_Q="$(quote_sq "server/team-points/bin/fair-play-backfill.php")"
{
  if [[ -s "$TMP_NEXT" ]]; then
    printf '\n'
  fi
  printf '%s\n' "$BEGIN_MARKER"
  printf '*/2 * * * * cd %s && %s %s 20 20 >/dev/null 2>&1\n' "$ROOT_Q" "$PHP_Q" "$SCRIPT_Q"
  printf '%s\n' "$END_MARKER"
} >>"$TMP_NEXT"

crontab "$TMP_NEXT"
echo "Installed Promote to King v2.10.9.7 Fair Play backfill CRON (every 2 minutes)."
