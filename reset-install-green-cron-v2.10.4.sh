#!/usr/bin/env bash
set -euo pipefail
ROOT="${1:-$(pwd)}"
BASE_URL="${2:-${P2K_BASE_URL:-https://www.promotetoking.org}}"
CONFIG="$ROOT/server/team-points-green/config/green.local.php"
if [[ ! -f "$CONFIG" ]]; then
  echo "ERROR: Green configuration not found: $CONFIG" >&2
  exit 2
fi
PHP_BIN=""
for cand in /usr/bin/php8.5-cli /usr/bin/php8.5 /usr/bin/php8.4-cli /usr/bin/php8.4 /usr/bin/php8.3-cli /usr/bin/php8.3 /usr/bin/php8.2-cli /usr/bin/php8.2 /usr/bin/php8.1-cli /usr/bin/php8.1 /usr/bin/php8.0-cli /usr/bin/php8.0; do
  [[ -x "$cand" ]] || continue
  ver="$($cand -r 'echo PHP_VERSION;' 2>/dev/null || true)"; major="${ver%%.*}"
  if [[ "$major" =~ ^[0-9]+$ ]] && (( major >= 8 )); then PHP_BIN="$cand"; break; fi
done
[[ -n "$PHP_BIN" ]] || { echo "ERROR: PHP 8 CLI not found." >&2; exit 2; }
TOKEN="$($PHP_BIN -r '$c=require $argv[1]; echo (string)($c["app"]["cron_token"]??"");' "$CONFIG")"
[[ -n "$TOKEN" ]] || { echo "ERROR: Green cron token could not be read from green.local.php." >&2; exit 2; }
if ! command -v crontab >/dev/null 2>&1; then
  echo "NOTICE: crontab is unavailable; GSCF was not installed automatically."
  echo "Use the line reported by server/team-points-green/public/install.php?action=cron-info."
  exit 0
fi
CURL_BIN="$(command -v curl || true)"; [[ -n "$CURL_BIN" ]] || CURL_BIN=/usr/bin/curl
URL="${BASE_URL%/}/server/team-points-green/public/cron.php?token=${TOKEN}"
TMP="$(mktemp)"; CLEAN="$(mktemp)"; trap 'rm -f "$TMP" "$CLEAN"' EXIT
crontab -l 2>/dev/null > "$TMP" || true
# Remove the previous managed GSCF block and any older direct Green worker line only.
awk '
  $0=="# P2K_GSCF_BEGIN" {drop=1; next}
  $0=="# P2K_GSCF_END" {drop=0; next}
  drop {next}
  /P2K-GSCF-GREEN-/ {next}
  /server\/team-points-green\/public\/cron\.php/ {next}
  {print}
' "$TMP" > "$CLEAN"
{
  cat "$CLEAN"
  echo "# P2K_GSCF_BEGIN"
  echo "# Green Staggered CRON Feeder v2.10.4 · feeder=gscf-main · soft=18s · hard=24s · lease=p2k_green_worker"
  printf '%s\n' "2,32 * * * * $CURL_BIN --fail --silent --show-error --max-time 30 '$URL' >/dev/null 2>&1"
  echo "# P2K_GSCF_END"
} > "$TMP"
crontab "$TMP"
echo "GSCF installed: one Green feeder at minute 2 and 32 each hour."
echo "Worker soft target: 18 s; hard budget: 24 s; overlap prevented by Green worker lease."
echo "Blue and unrelated CRON entries were preserved."
