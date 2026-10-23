#!/usr/bin/env bash
set -euo pipefail
ROOT="${1:-}"
if [[ -z "$ROOT" ]]; then ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"; else ROOT="$(cd "$ROOT" && pwd)"; fi
resolve_php_cli(){
  local candidate sapi
  if [[ -n "${P2K_PHP_BIN:-}" ]]; then candidate="$P2K_PHP_BIN"; [[ -x "$candidate" ]] || return 1; sapi="$("$candidate" -r 'echo PHP_SAPI;' 2>/dev/null | tr -d '\r\n' || true)"; [[ "$sapi" == cli ]] && { printf '%s\n' "$candidate"; return 0; }; return 1; fi
  for candidate in /usr/bin/php8.5-cli /usr/bin/php8.5 /usr/bin/php8.4-cli /usr/bin/php8.4 /usr/bin/php8.3-cli /usr/bin/php8.3 /usr/bin/php8.2-cli /usr/bin/php8.2; do
    [[ -x "$candidate" ]] || continue; sapi="$("$candidate" -r 'echo PHP_SAPI;' 2>/dev/null | tr -d '\r\n' || true)"; [[ "$sapi" == cli ]] && { printf '%s\n' "$candidate"; return 0; }
  done
  echo "ERROR: No supported PHP CLI executable found." >&2; return 1
}
PHP_BIN="$(resolve_php_cli)"; command -v crontab >/dev/null 2>&1 || { echo "ERROR: crontab unavailable." >&2; exit 1; }
CONFIG="$ROOT/server/team-points/config/config.local.php"
[[ -f "$CONFIG" ]] || { echo "ERROR: Missing protected Team Points config at $CONFIG" >&2; exit 1; }

HELPER_DIR="$HOME/.p2k"; HELPER="$HELPER_DIR/team-points-analytics-active.sh"
mkdir -p "$HELPER_DIR"; chmod 700 "$HELPER_DIR"
cat >"$HELPER" <<EOF
#!/usr/bin/env bash
set -euo pipefail
ROOT=$(printf '%q' "$ROOT")
PHP_BIN=$(printf '%q' "$PHP_BIN")
CONFIG="\$ROOT/server/team-points/config/config.local.php"
RUNTIME="\$("\$PHP_BIN" -r '\$c=require \$argv[1]; \$r=rtrim((string)((\$c["storage"]["runtime_dir"]??"")),"/\\\\"); echo \$r!==""?\$r:rtrim((string)\$argv[2],"/\\\\")."/data/runtime-v280";' "\$CONFIG" "\$ROOT")"
STATE="\$RUNTIME/release-control/state.json"; MODE="direct-root"; RELEASE=""
if [[ -f "\$STATE" ]]; then
  read -r MODE RELEASE < <("\$PHP_BIN" -r '\$s=json_decode((string)file_get_contents(\$argv[1]),true); \$m=(string)(\$s["mode"]??"direct-root"); \$r=(string)(\$s["public_release"]??""); if(\$m==="slots"&&!preg_match("/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}\$/D",\$r)) exit(3); echo \$m," ",\$r;' "\$STATE")
fi
if [[ "\$MODE" == "slots" ]]; then APP="\$RUNTIME/release-control/runtime-trees/\$RELEASE/app"; else APP="\$ROOT"; fi
SCRIPT="\$APP/server/team-points/bin/analytics-convergence.php"
[[ -f "\$SCRIPT" ]] || { echo "ERROR: Active Analytics convergence worker unavailable: \$SCRIPT" >&2; exit 4; }
exec env P2K_TP_CONFIG="\$CONFIG" "\$PHP_BIN" -d display_errors=0 -d 'error_reporting=E_ALL & ~E_DEPRECATED' "\$SCRIPT" 35
EOF
chmod 700 "$HELPER"

BEGIN="# BEGIN P2K ANALYTICS CONVERGENCE v2.15.1"; END="# END P2K ANALYTICS CONVERGENCE v2.15.1"
CUR="$(mktemp)"; NEXT="$(mktemp)"; trap 'rm -f "$CUR" "$NEXT"' EXIT
crontab -l >"$CUR" 2>/dev/null || :
awk '
  BEGIN{skip=0}
  /^# BEGIN P2K ANALYTICS CONVERGENCE v2\./{skip=1;next}
  /^# END P2K ANALYTICS CONVERGENCE v2\./{skip=0;next}
  skip{next}
  /server\/team-points\/bin\/analytics-convergence\.php/{next}
  /team-points-analytics-active\.sh/{next}
  {print}
' "$CUR" >"$NEXT"
quote_sq(){ printf "'%s'" "${1//\'/\'"\'"\'}"; }
HELPER_Q="$(quote_sq "$HELPER")"
[[ ! -s "$NEXT" ]] || printf '\n' >>"$NEXT"
printf '%s\n' "$BEGIN" >>"$NEXT"
printf '* * * * * %s >/dev/null 2>&1\n' "$HELPER_Q" >>"$NEXT"
printf '%s\n' "$END" >>"$NEXT"
crontab "$NEXT"
INSTALLED="$(crontab -l 2>/dev/null || true)"
COUNT="$(printf '%s\n' "$INSTALLED" | grep -F -c "$HELPER" || true)"
[[ "$COUNT" -eq 1 ]] || { echo "ERROR: Analytics convergence CRON verification failed." >&2; exit 2; }
echo "Installed Promote to King v2.15.1 Green production Analytics convergence CRON (every minute) using active-slot resolver $HELPER."
