#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 0 ]]; then
  echo "This v2.8.8.3 helper takes no arguments." >&2
  exit 2
fi

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SITE_ROOT=${P2K_SITE_ROOT:-$SCRIPT_DIR}
BASE_URL=${P2K_BASE_URL:-https://www.promotetoking.org}
BASE_URL=${BASE_URL%/}
TP_CONFIG="$SITE_ROOT/server/team-points/config/config.local.php"
SHARED_CONFIG="$SITE_ROOT/data/server-config.json"

CURL_BIN=$(command -v curl || true)
CRONTAB_BIN=$(command -v crontab || true)
PHP_BIN=$(command -v php || true)
if [[ -z "$CURL_BIN" || -z "$CRONTAB_BIN" || -z "$PHP_BIN" ]]; then
  echo "curl, crontab and php must be available in PATH." >&2
  exit 1
fi
if [[ ! -f "$TP_CONFIG" ]]; then
  echo "Missing production Team Points config: $TP_CONFIG" >&2
  exit 1
fi
if [[ ! -f "$SHARED_CONFIG" ]]; then
  echo "Missing production shared config: $SHARED_CONFIG" >&2
  exit 1
fi

TEAM_POINTS_TOKEN=$($PHP_BIN -r '$c=require $argv[1]; echo (string)($c["app"]["cron_token"]??"");' "$TP_CONFIG")
SHARED_TOKEN=$($PHP_BIN -r '$c=json_decode(file_get_contents($argv[1]),true); echo is_array($c)?(string)($c["cronToken"]??""):"";' "$SHARED_CONFIG")

if [[ -z "$TEAM_POINTS_TOKEN" || "$TEAM_POINTS_TOKEN" == CHANGE_* ]]; then
  echo "Team Points cron_token is missing or still a placeholder." >&2
  exit 1
fi
if [[ -z "$SHARED_TOKEN" || "$SHARED_TOKEN" == CHANGE_* ]]; then
  echo "Shared cronToken is missing or still a placeholder." >&2
  exit 1
fi
if [[ ! "$BASE_URL" =~ ^https?:// ]]; then
  echo "P2K_BASE_URL must begin with http:// or https://." >&2
  exit 1
fi

TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT
cat > "$TMP" <<EOF_CRON
# BEGIN PROMOTE TO KING v2.8.8.3
*/5 * * * * $CURL_BIN --fail --silent --show-error --max-time 55 '$BASE_URL/server/team-points/public/cron-club.php?token=$TEAM_POINTS_TOKEN'
2-59/10 * * * * $CURL_BIN --fail --silent --show-error --max-time 55 '$BASE_URL/server/tournaments/public/cron.php?token=$SHARED_TOKEN'
7,37 * * * * $CURL_BIN --fail --silent --show-error --max-time 55 '$BASE_URL/server/team-points/public/cron-player.php?token=$TEAM_POINTS_TOKEN'
17 * * * * $CURL_BIN --fail --silent --show-error --max-time 55 '$BASE_URL/api/track-upcoming-league-matches/?token=$SHARED_TOKEN'
# END PROMOTE TO KING v2.8.8.3
EOF_CRON

# Deliberately remove the complete crontab for the current SSH user, as requested.
$CRONTAB_BIN -r 2>/dev/null || true
$CRONTAB_BIN "$TMP"

echo "Installed Promote to King v2.8.8.3 crontab for user: $(id -un)"
echo "Tokens were read from the existing protected production configuration files."
echo
echo "Current crontab:"
$CRONTAB_BIN -l
