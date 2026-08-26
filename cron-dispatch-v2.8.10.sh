#!/usr/bin/env bash
set -u
TASK=${1:-}
BASE_URL=${2:-${P2K_BASE_URL:-https://www.promotetoking.org}}
BASE_URL=${BASE_URL%/}
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SITE_ROOT=${P2K_SITE_ROOT:-$SCRIPT_DIR}
PHP_BIN=$(command -v php || true)
CURL_BIN=$(command -v curl || true)
if [[ -z "$PHP_BIN" || -z "$CURL_BIN" ]]; then exit 127; fi
TP_CONFIG="$SITE_ROOT/server/team-points/config/config.local.php"
SHARED_CONFIG="$SITE_ROOT/data/server-config.json"
RUNTIME_DIR=$($PHP_BIN -r '$p=$argv[1];$root=$argv[2];$c=is_file($p)?require $p:[];$d=is_array($c)&&is_array($c["storage"]??null)?trim((string)($c["storage"]["runtime_dir"]??"")):"";echo $d!==""?rtrim($d,"/\\"):$root."/data/runtime-v280";' "$TP_CONFIG" "$SITE_ROOT" 2>/dev/null || printf '%s/data/runtime-v280' "$SITE_ROOT")
LOG_DIR="$RUNTIME_DIR/cron-shell"
mkdir -p "$LOG_DIR" 2>/dev/null || exit 126
chmod 700 "$LOG_DIR" 2>/dev/null || true
if [[ ! -f "$LOG_DIR/.htaccess" ]]; then printf '%s\n' '<IfModule mod_authz_core.c>' 'Require all denied' '</IfModule>' '<IfModule !mod_authz_core.c>' 'Deny from all' '</IfModule>' > "$LOG_DIR/.htaccess"; fi

case "$TASK" in
  club)
    TASK_KEY='team-points-club'; ENDPOINT='server/team-points/public/cron-club.php';
    TOKEN=$($PHP_BIN -r '$c=require $argv[1];echo (string)($c["app"]["cron_token"]??"");' "$TP_CONFIG" 2>/dev/null || true) ;;
  player)
    TASK_KEY='team-points-player'; ENDPOINT='server/team-points/public/cron-player.php';
    TOKEN=$($PHP_BIN -r '$c=require $argv[1];echo (string)($c["app"]["cron_token"]??"");' "$TP_CONFIG" 2>/dev/null || true) ;;
  tournaments)
    TASK_KEY='tournaments'; ENDPOINT='server/tournaments/public/cron.php';
    TOKEN=$($PHP_BIN -r '$c=is_file($argv[1])?json_decode(file_get_contents($argv[1]),true):[];echo is_array($c)?(string)($c["cronToken"]??""):"";' "$SHARED_CONFIG" 2>/dev/null || true) ;;
  match-tracking)
    TASK_KEY='match-tracking'; ENDPOINT='api/track-upcoming-league-matches/';
    TOKEN=$($PHP_BIN -r '$c=is_file($argv[1])?json_decode(file_get_contents($argv[1]),true):[];echo is_array($c)?(string)($c["cronToken"]??""):"";' "$SHARED_CONFIG" 2>/dev/null || true) ;;
  *) exit 2 ;;
esac
STARTED=$(date -u +'%Y-%m-%dT%H:%M:%SZ')
LOG="$LOG_DIR/${TASK_KEY}.log"
MARKER="$LOG_DIR/last-${TASK_KEY}.json"
if [[ -z "${TOKEN:-}" || "$TOKEN" == CHANGE_* ]]; then
  printf '%s task=%s status=config_error message=missing_or_placeholder_token\n' "$STARTED" "$TASK_KEY" >> "$LOG"
  printf '{"task":"%s","last_started_at":"%s","last_completed_at":"%s","exit_code":78,"http_status":0,"status":"config_error"}\n' "$TASK_KEY" "$STARTED" "$STARTED" > "$MARKER"
  exit 78
fi
URL="$BASE_URL/$ENDPOINT"
TMP=$(mktemp)
CURL_CONFIG=$(mktemp "$LOG_DIR/.curl-config.XXXXXX")
chmod 600 "$CURL_CONFIG" 2>/dev/null || true
printf 'header = "X-P2K-Cron-Token: %s"\n' "$TOKEN" > "$CURL_CONFIG"
trap 'rm -f "$TMP" "$CURL_CONFIG"' EXIT
printf '%s task=%s status=invoked endpoint=%s\n' "$STARTED" "$TASK_KEY" "$ENDPOINT" >> "$LOG"
HTTP_STATUS=$($CURL_BIN --silent --show-error --location --connect-timeout 10 --max-time 55 --user-agent 'PromoteToKing-Cron/2.8.10' --config "$CURL_CONFIG" --output "$TMP" --write-out '%{http_code}' "$URL" 2>>"$LOG")
CURL_EXIT=$?
ENDED=$(date -u +'%Y-%m-%dT%H:%M:%SZ')
STATUS='success'
if [[ $CURL_EXIT -ne 0 ]]; then STATUS='transport_error'; elif [[ ! "$HTTP_STATUS" =~ ^2[0-9][0-9]$ ]]; then STATUS='http_error'; fi
printf '%s task=%s status=%s http=%s curl_exit=%s\n' "$ENDED" "$TASK_KEY" "$STATUS" "${HTTP_STATUS:-0}" "$CURL_EXIT" >> "$LOG"
printf '{"task":"%s","last_started_at":"%s","last_completed_at":"%s","exit_code":%d,"http_status":%d,"status":"%s"}\n' "$TASK_KEY" "$STARTED" "$ENDED" "$CURL_EXIT" "${HTTP_STATUS:-0}" "$STATUS" > "$MARKER"
# Keep logs bounded without depending on logrotate.
tail -n 400 "$LOG" > "$LOG.tmp" 2>/dev/null && mv "$LOG.tmp" "$LOG" || true
[[ "$STATUS" == 'success' ]]
