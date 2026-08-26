#!/usr/bin/env bash
set -euo pipefail
if [[ $# -ne 0 ]]; then echo "This v2.8.10 helper takes no arguments." >&2; exit 2; fi
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SITE_ROOT=${P2K_SITE_ROOT:-$SCRIPT_DIR}
BASE_URL=${P2K_BASE_URL:-https://www.promotetoking.org}; BASE_URL=${BASE_URL%/}
TP_CONFIG="$SITE_ROOT/server/team-points/config/config.local.php"
SHARED_CONFIG="$SITE_ROOT/data/server-config.json"
DISPATCHER="$SITE_ROOT/cron-dispatch-v2.8.10.sh"
CRONTAB_BIN=$(command -v crontab || true); PHP_BIN=$(command -v php || true); BASH_BIN=$(command -v bash || true); CURL_BIN=$(command -v curl || true)
if [[ -z "$CRONTAB_BIN" || -z "$PHP_BIN" || -z "$BASH_BIN" || -z "$CURL_BIN" ]]; then echo "bash, curl, crontab and php must be available in PATH." >&2; exit 1; fi
[[ -f "$TP_CONFIG" ]] || { echo "Missing production Team Points config: $TP_CONFIG" >&2; exit 1; }
[[ -x "$DISPATCHER" ]] || chmod +x "$DISPATCHER"
OLD_CRON=$($CRONTAB_BIN -l 2>/dev/null || true)
TEAM_POINTS_TOKEN=$($PHP_BIN -r '$c=require $argv[1];echo (string)($c["app"]["cron_token"]??"");' "$TP_CONFIG")
if [[ -z "$TEAM_POINTS_TOKEN" || "$TEAM_POINTS_TOKEN" == CHANGE_* ]]; then echo "Team Points cron_token is missing or still a placeholder." >&2; exit 1; fi
SHARED_TOKEN=$($PHP_BIN -r '$c=is_file($argv[1])?json_decode(file_get_contents($argv[1]),true):[];echo is_array($c)?(string)($c["cronToken"]??""):"";' "$SHARED_CONFIG")
if [[ -z "$SHARED_TOKEN" || "$SHARED_TOKEN" == CHANGE_* ]]; then
  RECOVERED=$(printf '%s\n' "$OLD_CRON" | sed -nE "s#.*(server/tournaments/public/cron\.php|api/track-upcoming-league-matches/)[?]token=([^'\"[:space:]]+).*#\2#p" | head -1)
  if [[ -n "$RECOVERED" ]]; then
    SHARED_TOKEN=$($PHP_BIN -r 'echo rawurldecode($argv[1]);' "$RECOVERED")
    mkdir -p "$(dirname "$SHARED_CONFIG")"
    $PHP_BIN -r '$p=$argv[1];$t=$argv[2];$c=is_file($p)?json_decode(file_get_contents($p),true):[];if(!is_array($c))$c=[];$c["schemaVersion"]=(int)($c["schemaVersion"]??1);$c["cronToken"]=$t;file_put_contents($p,json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",LOCK_EX);' "$SHARED_CONFIG" "$SHARED_TOKEN"
    echo "Recovered the shared CRON token from the existing crontab and restored data/server-config.json."
  else
    SHARED_TOKEN=$($PHP_BIN -r 'echo bin2hex(random_bytes(32));')
    mkdir -p "$(dirname "$SHARED_CONFIG")"
    $PHP_BIN -r '$p=$argv[1];$t=$argv[2];$c=is_file($p)?json_decode(file_get_contents($p),true):[];if(!is_array($c))$c=[];$c["schemaVersion"]=(int)($c["schemaVersion"]??1);$c["cronToken"]=$t;file_put_contents($p,json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",LOCK_EX);chmod($p,0600);' "$SHARED_CONFIG" "$SHARED_TOKEN"
    echo "Generated a new protected shared CRON token because no production token could be recovered."
  fi
fi
chmod 600 "$SHARED_CONFIG" 2>/dev/null || true
[[ "$BASE_URL" =~ ^https?:// ]] || { echo "P2K_BASE_URL must begin with http:// or https://." >&2; exit 1; }
RUNTIME_DIR=$($PHP_BIN -r '$c=require $argv[1];$root=$argv[2];$d=is_array($c["storage"]??null)?trim((string)($c["storage"]["runtime_dir"]??"")):"";echo $d!==""?rtrim($d,"/\\"):$root."/data/runtime-v280";' "$TP_CONFIG" "$SITE_ROOT")
mkdir -p "$RUNTIME_DIR/cron-shell"; chmod 700 "$RUNTIME_DIR/cron-shell" 2>/dev/null || true
BACKUP="$RUNTIME_DIR/cron-shell/crontab-before-v2.8.10-$(date -u +%Y%m%dT%H%M%SZ).txt"
printf '%s\n' "$OLD_CRON" > "$BACKUP"
TMP=$(mktemp); trap 'rm -f "$TMP"' EXIT
cat > "$TMP" <<EOF_CRON
# BEGIN PROMOTE TO KING v2.8.10
*/5 * * * * $BASH_BIN '$DISPATCHER' club '$BASE_URL'
2-59/10 * * * * $BASH_BIN '$DISPATCHER' tournaments '$BASE_URL'
7,37 * * * * $BASH_BIN '$DISPATCHER' player '$BASE_URL'
17 * * * * $BASH_BIN '$DISPATCHER' match-tracking '$BASE_URL'
# END PROMOTE TO KING v2.8.10
EOF_CRON
# crontab FILE already performs a complete replacement atomically; do not delete first.
if ! $CRONTAB_BIN "$TMP"; then
  echo "CRON installation failed; restoring the previous crontab." >&2
  if [[ -n "$OLD_CRON" ]]; then printf '%s\n' "$OLD_CRON" | $CRONTAB_BIN -; else $CRONTAB_BIN -r 2>/dev/null || true; fi
  exit 1
fi
INSTALLED=$($CRONTAB_BIN -l 2>/dev/null || true)
if [[ $(printf '%s\n' "$INSTALLED" | grep -c 'cron-dispatch-v2.8.10.sh') -ne 4 ]]; then
  echo "CRON verification failed; restoring the previous crontab." >&2
  if [[ -n "$OLD_CRON" ]]; then printf '%s\n' "$OLD_CRON" | $CRONTAB_BIN -; else $CRONTAB_BIN -r 2>/dev/null || true; fi
  exit 1
fi
echo "Installed Promote to King v2.8.10 CRON dispatcher schedule for user: $(id -un)"
echo "Previous crontab backup: $BACKUP"
echo "Tokens are read from protected configuration at each run; they are no longer embedded in crontab."
echo; printf '%s\n' "$INSTALLED"
