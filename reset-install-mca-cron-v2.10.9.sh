#!/usr/bin/env bash
# Promote to King v2.10.9 — preserve all unrelated CRON; replace only the MCA block.
set -Eeuo pipefail
ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)}";ROOT="$(cd "$ROOT" && pwd -P)"
RUNNER="$ROOT/cron-mca-arena-v2.10.9.sh";[[ -f "$RUNNER" ]]||{ echo "ERROR: MCA runner missing: $RUNNER" >&2;exit 2; };chmod 755 "$RUNNER" 2>/dev/null||true
BASH_BIN="$(command -v bash||true)";[[ -n "$BASH_BIN" ]]||{ echo 'ERROR: bash unavailable.' >&2;exit 2; }
PHP_BIN="";for c in "${P2K_PHP_CLI:-}" /usr/bin/php8.5-cli /usr/bin/php8.5 /usr/bin/php8.4-cli /usr/bin/php8.4 /usr/bin/php8.3-cli /usr/bin/php8.3 /usr/bin/php8.2-cli /usr/bin/php8.2 /usr/bin/php8.1-cli /usr/bin/php8.1 php8.5-cli php8.5 php8.4-cli php8.4 php8.3-cli php8.3 php8.2-cli php8.2 php8.1-cli php8.1 php;do [[ -n "$c" ]]||continue;r="$c";[[ "$c" == */* ]]||r="$(command -v "$c" 2>/dev/null||true)";[[ -n "$r"&&-x "$r" ]]||continue;if "$r" -r 'exit(PHP_SAPI === "cli" && PHP_VERSION_ID >= 80100 ? 0 : 1);' >/dev/null 2>&1;then PHP_BIN="$r";break;fi;done
[[ -n "$PHP_BIN" ]]||{ echo 'ERROR: Compatible PHP CLI not found.' >&2;exit 2; }
if ! command -v crontab >/dev/null 2>&1;then echo 'NOTICE: crontab unavailable; no scheduler changed.';echo "Run every minute: P2K_SITE_ROOT=$ROOT P2K_PHP_CLI=$PHP_BIN $BASH_BIN $RUNNER 55";exit 0;fi
OLD="$(crontab -l 2>/dev/null||true)";TMP="$(mktemp)";trap 'rm -f "$TMP"' EXIT
printf '%s\n' "$OLD" | awk '
 $0=="# P2K_MCA_RESULTS_SYNC_BEGIN"{skip=1;next}
 $0=="# P2K_MCA_RESULTS_SYNC_END"{skip=0;next}
 $0=="# P2K_MCA_ARENA_SYNC_BEGIN"{skip=1;next}
 $0=="# P2K_MCA_ARENA_SYNC_END"{skip=0;next}
 !skip && $0 !~ /cron-mca-results-v2\.10\.6(\.25)?\.sh/ && $0 !~ /cron-mca-arena-v2\.10\.9\.sh/ {print}
' > "$TMP"
while [[ -s "$TMP"&&-z "$(tail -n1 "$TMP")" ]];do sed -i '$d' "$TMP";done;[[ ! -s "$TMP" ]]||printf '\n' >> "$TMP"
cat >> "$TMP" <<EOF
# P2K_MCA_ARENA_SYNC_BEGIN
# MCA arena acquisition v2.10.9 — every minute, <=55s slice; discovery due-gated to 12h; all Chess.com requests serialized server-side at >=1 second spacing.
* * * * * P2K_SITE_ROOT=$ROOT P2K_PHP_CLI=$PHP_BIN $BASH_BIN $RUNNER 55 >/dev/null 2>&1
# P2K_MCA_ARENA_SYNC_END
EOF
crontab "$TMP"
COUNT="$(crontab -l 2>/dev/null|grep -c 'cron-mca-arena-v2.10.9.sh' || true)";if [[ "$COUNT" != 1 ]];then echo 'ERROR: MCA CRON verification failed; restoring previous crontab.' >&2;if [[ -n "$OLD" ]];then printf '%s\n' "$OLD"|crontab -;else crontab -r 2>/dev/null||true;fi;exit 3;fi
echo 'MCA arena acquisition scheduler installed: every minute, 55-second worker slice.'
echo 'Discovery remains internally due-gated to every 12 hours. Unrelated CRON entries were preserved.'
