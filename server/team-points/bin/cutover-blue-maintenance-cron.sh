#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-}"
if [[ -z "$ROOT" ]]; then
  ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
else
  ROOT="$(cd "$ROOT" && pwd)"
fi

resolve_php_cli(){
  local candidate sapi
  if [[ -n "${P2K_PHP_BIN:-}" ]]; then
    candidate="$P2K_PHP_BIN"
    [[ -x "$candidate" ]] || return 1
    sapi="$("$candidate" -r 'echo PHP_SAPI;' 2>/dev/null | tr -d '\r\n' || true)"
    [[ "$sapi" == cli ]] && { printf '%s\n' "$candidate"; return 0; }
    return 1
  fi
  for candidate in /usr/bin/php8.5-cli /usr/bin/php8.5 /usr/bin/php8.4-cli /usr/bin/php8.4 /usr/bin/php8.3-cli /usr/bin/php8.3 /usr/bin/php8.2-cli /usr/bin/php8.2; do
    [[ -x "$candidate" ]] || continue
    sapi="$("$candidate" -r 'echo PHP_SAPI;' 2>/dev/null | tr -d '\r\n' || true)"
    [[ "$sapi" == cli ]] && { printf '%s\n' "$candidate"; return 0; }
  done
  echo "ERROR: No supported PHP CLI executable found." >&2
  return 1
}

PHP_BIN="$(resolve_php_cli)"
command -v crontab >/dev/null 2>&1 || { echo "ERROR: crontab unavailable." >&2; exit 1; }
SCRIPT="$ROOT/server/team-points/bin/maintenance.php"
[[ -f "$SCRIPT" ]] || { echo "ERROR: Missing $SCRIPT" >&2; exit 1; }

CURRENT="$(mktemp)"
NEXT="$(mktemp)"
trap 'rm -f "$CURRENT" "$NEXT"' EXIT
crontab -l >"$CURRENT" 2>/dev/null || :

BACKUP="${HOME}/crontab-before-p2k-maintenance-$(date +%Y%m%d-%H%M%S).txt"
cp "$CURRENT" "$BACKUP"

# Remove only legacy Team Points Club/Player dispatcher lines and any prior copy
# of this maintenance block. Preserve tournaments, match tracking, backups,
# Green, DMA, MCA, PPA, Galactic Conflict and every unrelated schedule.
awk '
  BEGIN{skip=0}
  /^# BEGIN P2K TEAM POINTS MAINTENANCE v2\.15\.0$/{skip=1;next}
  /^# END P2K TEAM POINTS MAINTENANCE v2\.15\.0$/{skip=0;next}
  skip{next}
  /cron-dispatch-v2\.[0-9.]+\.sh[[:space:]]+club([[:space:]]|$)/{next}
  /cron-dispatch-v2\.[0-9.]+\.sh[[:space:]]+player([[:space:]]|$)/{next}
  {print}
' "$CURRENT" >"$NEXT"

quote_sq(){ printf "'%s'" "${1//\'/\'\"\'\"\'}"; }
ROOT_Q="$(quote_sq "$ROOT")"
PHP_Q="$(quote_sq "$PHP_BIN")"
SCRIPT_Q="$(quote_sq 'server/team-points/bin/maintenance.php')"

[[ ! -s "$NEXT" ]] || printf '\n' >>"$NEXT"
printf '%s\n' '# BEGIN P2K TEAM POINTS MAINTENANCE v2.15.0' >>"$NEXT"
printf '3-59/5 * * * * cd %s && %s %s 45 >/dev/null 2>&1\n' "$ROOT_Q" "$PHP_Q" "$SCRIPT_Q" >>"$NEXT"
printf '%s\n' '# END P2K TEAM POINTS MAINTENANCE v2.15.0' >>"$NEXT"

crontab "$NEXT"

INSTALLED="$(crontab -l 2>/dev/null || true)"
MAINT_COUNT="$(printf '%s\n' "$INSTALLED" | grep -c 'server/team-points/bin/maintenance.php' || true)"
CLUB_COUNT="$(printf '%s\n' "$INSTALLED" | grep -E -c 'cron-dispatch-v2\.[0-9.]+\.sh[[:space:]]+club([[:space:]]|$)' || true)"
PLAYER_COUNT="$(printf '%s\n' "$INSTALLED" | grep -E -c 'cron-dispatch-v2\.[0-9.]+\.sh[[:space:]]+player([[:space:]]|$)' || true)"
TOURNAMENT_COUNT="$(printf '%s\n' "$INSTALLED" | grep -E -c 'cron-dispatch-v2\.[0-9.]+\.sh[[:space:]]+tournaments([[:space:]]|$)' || true)"
TRACKING_COUNT="$(printf '%s\n' "$INSTALLED" | grep -E -c 'cron-dispatch-v2\.[0-9.]+\.sh[[:space:]]+match-tracking([[:space:]]|$)' || true)"

if [[ "$MAINT_COUNT" -ne 1 || "$CLUB_COUNT" -ne 0 || "$PLAYER_COUNT" -ne 0 || "$TOURNAMENT_COUNT" -lt 1 || "$TRACKING_COUNT" -lt 1 ]]; then
  echo "ERROR: CRON cutover verification failed; restoring previous crontab." >&2
  crontab "$CURRENT"
  exit 2
fi

echo "SUCCESS: Team Points maintenance cutover installed."
echo "Backup: $BACKUP"
echo "Removed legacy Blue acquisition schedules: club=$CLUB_COUNT player=$PLAYER_COUNT"
echo "Preserved P2K schedules: tournaments=$TOURNAMENT_COUNT match-tracking=$TRACKING_COUNT"
echo "Installed maintenance schedule: every 5 minutes at minute 3, using $PHP_BIN"
