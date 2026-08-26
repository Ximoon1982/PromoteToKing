#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage:
  ./reset-install-cron-v2.8.5.sh TEAM_POINTS_CRON_TOKEN SHARED_CRON_TOKEN [BASE_URL]

Example:
  ./reset-install-cron-v2.8.5.sh 'team-token-here' 'shared-token-here' 'https://www.promotetoking.org'

WARNING: this script deliberately deletes the ENTIRE crontab of the current SSH user,
then installs only the Promote to King v2.8.5 entries below.
USAGE
}

if [[ $# -lt 2 || $# -gt 3 ]]; then
  usage >&2
  exit 2
fi

TEAM_POINTS_TOKEN=$1
SHARED_TOKEN=$2
BASE_URL=${3:-https://www.promotetoking.org}
BASE_URL=${BASE_URL%/}

if [[ -z "$TEAM_POINTS_TOKEN" || -z "$SHARED_TOKEN" ]]; then
  echo "Tokens must not be empty." >&2
  exit 2
fi
if [[ ! "$BASE_URL" =~ ^https?:// ]]; then
  echo "BASE_URL must begin with http:// or https://" >&2
  exit 2
fi

CURL_BIN=$(command -v curl || true)
CRONTAB_BIN=$(command -v crontab || true)
if [[ -z "$CURL_BIN" || -z "$CRONTAB_BIN" ]]; then
  echo "curl and crontab must both be available in PATH." >&2
  exit 1
fi

TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT

cat > "$TMP" <<EOF_CRON
# BEGIN PROMOTE TO KING v2.8.5
# Club Points: high-priority match discovery + urgent boards every 5 minutes.
*/5 * * * * $CURL_BIN --fail --silent --show-error --max-time 55 '$BASE_URL/server/team-points/public/cron-club.php?token=$TEAM_POINTS_TOKEN'
# Tournament maintenance: every 10 minutes, offset from Club/Player work.
2-59/10 * * * * $CURL_BIN --fail --silent --show-error --max-time 55 '$BASE_URL/server/tournaments/public/cron.php?token=$SHARED_TOKEN'
# Player Points: historical/member work every 30 minutes, staggered from tournaments.
7,37 * * * * $CURL_BIN --fail --silent --show-error --max-time 55 '$BASE_URL/server/team-points/public/cron-player.php?token=$TEAM_POINTS_TOKEN'
# League match monitoring: hourly, staggered from the other gateway users.
17 * * * * $CURL_BIN --fail --silent --show-error --max-time 55 '$BASE_URL/api/track-upcoming-league-matches/?token=$SHARED_TOKEN'
# END PROMOTE TO KING v2.8.5
EOF_CRON

# Requested destructive replacement: remove every existing entry for this SSH user.
$CRONTAB_BIN -r 2>/dev/null || true
$CRONTAB_BIN "$TMP"

echo "Installed Promote to King v2.8.5 crontab for user: $(id -un)"
echo
echo "Current crontab:"
$CRONTAB_BIN -l
