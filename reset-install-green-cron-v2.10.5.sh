#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="${1:-$(pwd)}"
BASE_URL="${2:-${P2K_BASE_URL:-https://www.promotetoking.org}}"
CONFIG="$ROOT/server/team-points-green/config/green.local.php"
[[ -f "$CONFIG" ]] || { echo "NOTICE: Green configuration not found; Green CRON unchanged."; exit 0; }
PHP_BIN=""
for cand in /usr/bin/php8.5-cli /usr/bin/php8.5 /usr/bin/php8.4-cli /usr/bin/php8.4 /usr/bin/php8.3-cli /usr/bin/php8.3 /usr/bin/php8.2-cli /usr/bin/php8.2 /usr/bin/php8.1-cli /usr/bin/php8.1 /usr/bin/php8.0-cli /usr/bin/php8.0; do
  [[ -x "$cand" ]] || continue
  ver="$($cand -r 'echo PHP_VERSION;' 2>/dev/null || true)"; major="${ver%%.*}"
  [[ "$major" =~ ^[0-9]+$ ]] && (( major >= 8 )) && { PHP_BIN="$cand"; break; }
done
[[ -n "$PHP_BIN" ]] || { echo "ERROR: PHP 8 CLI not found." >&2; exit 2; }
TOKEN="$($PHP_BIN -r '$c=require $argv[1]; echo (string)($c["app"]["cron_token"]??"");' "$CONFIG")"
[[ -n "$TOKEN" ]] || { echo "ERROR: Green CRON token missing." >&2; exit 2; }
if ! command -v crontab >/dev/null 2>&1; then
  echo "NOTICE: crontab command unavailable; no scheduler change made."
  echo "Use the IONOS Cron Job Manager with server/team-points-green/public/cron.php."
  exit 0
fi
CURL_BIN="$(command -v curl || true)"; [[ -n "$CURL_BIN" ]] || CURL_BIN=/usr/bin/curl
URL="${BASE_URL%/}/server/team-points-green/public/cron.php?token=${TOKEN}"
TMP="$(mktemp)"; OUT="$(mktemp)"; trap 'rm -f "$TMP" "$OUT"' EXIT
crontab -l > "$TMP" 2>/dev/null || true
awk '
  $0=="# >>> P2K v2.10.0 GREEN TEAM POINTS CRON >>>"{old=1;next}
  old && $0=="# <<< P2K v2.10.0 GREEN TEAM POINTS CRON <<<"{old=0;next}
  $0=="# P2K_GSCF_BEGIN"{g=1;next}
  g && $0=="# P2K_GSCF_END"{g=0;next}
  !old && !g && $0 !~ /server\/team-points-green\/public\/cron\.php/ {print}
' "$TMP" > "$OUT"
while [[ -s "$OUT" && -z "$(tail -n1 "$OUT")" ]]; do sed -i '$d' "$OUT"; done
[[ ! -s "$OUT" ]] || printf '\n' >> "$OUT"
cat >> "$OUT" <<EOF
# P2K_GSCF_BEGIN
# Green Staggered CRON Feeder v2.10.5 · feeder=gscf-main · soft=50s · hard=55s · lease=p2k_green_worker
# Five staggered schedules; aggregate cadence = one Green invocation every minute.
0-59/5 * * * * $CURL_BIN --fail --silent --show-error --max-time 58 '$URL' >/dev/null 2>&1
1-59/5 * * * * $CURL_BIN --fail --silent --show-error --max-time 58 '$URL' >/dev/null 2>&1
2-59/5 * * * * $CURL_BIN --fail --silent --show-error --max-time 58 '$URL' >/dev/null 2>&1
3-59/5 * * * * $CURL_BIN --fail --silent --show-error --max-time 58 '$URL' >/dev/null 2>&1
4-59/5 * * * * $CURL_BIN --fail --silent --show-error --max-time 58 '$URL' >/dev/null 2>&1
# P2K_GSCF_END
EOF
crontab "$OUT"
COUNT="$(crontab -l | awk '$0=="# P2K_GSCF_BEGIN"{g=1;next}$0=="# P2K_GSCF_END"{g=0}g&&$0!~/^[[:space:]]*#/&&$0!~/^[[:space:]]*$/{n++}END{print n+0}')"
[[ "$COUNT" == "5" ]] || { echo "ERROR: expected five Green CRON entries; found $COUNT" >&2; exit 3; }
MAX58="$(crontab -l | grep -c -- '--max-time 58 .*team-points-green/public/cron.php' || true)"
[[ "$MAX58" == "5" ]] || { echo "ERROR: expected five Green CRON entries with --max-time 58; found $MAX58" >&2; exit 3; }
echo "Green scheduler installed: five staggered entries, aggregate one invocation/minute, curl max-time 58."
echo "Blue and unrelated CRON entries were preserved."
