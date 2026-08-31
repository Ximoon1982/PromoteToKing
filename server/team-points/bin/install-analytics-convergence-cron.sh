#!/usr/bin/env bash
set -euo pipefail
ROOT="${1:-}"
if [[ -z "$ROOT" ]]; then ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"; else ROOT="$(cd "$ROOT" && pwd)"; fi
resolve_php_cli(){
  local candidate sapi
  if [[ -n "${P2K_PHP_BIN:-}" ]]; then candidate="$P2K_PHP_BIN"; [[ -x "$candidate" ]] || return 1; sapi="$($candidate -r 'echo PHP_SAPI;' 2>/dev/null | tr -d '\r\n' || true)"; [[ "$sapi" == cli ]] && { printf '%s\n' "$candidate"; return 0; }; return 1; fi
  for candidate in /usr/bin/php8.5-cli /usr/bin/php8.5 /usr/bin/php8.4-cli /usr/bin/php8.4 /usr/bin/php8.3-cli /usr/bin/php8.3 /usr/bin/php8.2-cli /usr/bin/php8.2; do
    [[ -x "$candidate" ]] || continue; sapi="$($candidate -r 'echo PHP_SAPI;' 2>/dev/null | tr -d '\r\n' || true)"; [[ "$sapi" == cli ]] && { printf '%s\n' "$candidate"; return 0; }
  done
  echo "ERROR: No supported PHP CLI executable found." >&2; return 1
}
PHP_BIN="$(resolve_php_cli)"; command -v crontab >/dev/null 2>&1 || { echo "ERROR: crontab unavailable." >&2; exit 1; }
BEGIN="# BEGIN P2K ANALYTICS CONVERGENCE v2.10.9.7"; END="# END P2K ANALYTICS CONVERGENCE v2.10.9.7"
CUR="$(mktemp)"; NEXT="$(mktemp)"; trap 'rm -f "$CUR" "$NEXT"' EXIT
crontab -l >"$CUR" 2>/dev/null || :
awk -v b="$BEGIN" -v e="$END" '$0==b{skip=1;next}$0==e{skip=0;next}!skip{print}' "$CUR" >"$NEXT"
quote_sq(){ printf "'%s'" "${1//\'/\'\"\'\"\'}"; }
ROOT_Q="$(quote_sq "$ROOT")"; PHP_Q="$(quote_sq "$PHP_BIN")"; SCRIPT_Q="$(quote_sq 'server/team-points/bin/analytics-convergence.php')"
[[ ! -s "$NEXT" ]] || printf '\n' >>"$NEXT"
printf '%s\n' "$BEGIN" >>"$NEXT"
printf '* * * * * cd %s && %s %s 35 >/dev/null 2>&1\n' "$ROOT_Q" "$PHP_Q" "$SCRIPT_Q" >>"$NEXT"
printf '%s\n' "$END" >>"$NEXT"
crontab "$NEXT"
echo "Installed Promote to King v2.10.9.7 Analytics convergence CRON (every minute) using $PHP_BIN."
