#!/usr/bin/env bash
# Promote to King v2.10.6 — MCA Results Auto-Sync twice-daily scheduler.
set -Eeuo pipefail
ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)}"
ROOT="$(cd "$ROOT" && pwd -P)"
RUNNER="$ROOT/cron-mca-results-v2.10.6.sh"
[[ -f "$RUNNER" ]] || { echo "ERROR: MCA runner not found: $RUNNER" >&2; exit 2; }
chmod 755 "$RUNNER" 2>/dev/null || true

BASH_BIN="$(command -v bash || true)"
[[ -n "$BASH_BIN" ]] || { echo "ERROR: bash is unavailable." >&2; exit 2; }
PHP_BIN=""
for cand in "${P2K_PHP_CLI:-}" /usr/bin/php8.5-cli /usr/bin/php8.5 /usr/bin/php8.4-cli /usr/bin/php8.4 /usr/bin/php8.3-cli /usr/bin/php8.3 /usr/bin/php8.2-cli /usr/bin/php8.2 /usr/bin/php8.1-cli /usr/bin/php8.1 /usr/bin/php8.0-cli /usr/bin/php8.0 php8.5-cli php8.5 php8.4-cli php8.4 php8.3-cli php8.3 php8.2-cli php8.2 php8.1-cli php8.1 php8.0-cli php8.0 php; do
  [[ -n "$cand" ]] || continue
  resolved="$cand"; [[ "$cand" != */* ]] && resolved="$(command -v "$cand" 2>/dev/null || true)"
  [[ -n "$resolved" && -x "$resolved" ]] || continue
  if "$resolved" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1; then PHP_BIN="$resolved"; break; fi
done
[[ -n "$PHP_BIN" ]] || { echo "ERROR: PHP CLI not found." >&2; exit 2; }

if ! command -v crontab >/dev/null 2>&1; then
  echo "NOTICE: crontab is unavailable; existing scheduler was not changed."
  echo "In IONOS, run twice daily: P2K_SITE_ROOT=$ROOT P2K_PHP_CLI=$PHP_BIN $BASH_BIN $RUNNER"
  exit 0
fi

OLD="$(crontab -l 2>/dev/null || true)"
TMP="$(mktemp)"; trap 'rm -f "$TMP"' EXIT
printf '%s\n' "$OLD" | awk '
  $0=="# P2K_MCA_RESULTS_SYNC_BEGIN"{skip=1;next}
  $0=="# P2K_MCA_RESULTS_SYNC_END"{skip=0;next}
  !skip && $0 !~ /cron-mca-results-v2\.10\.6\.sh/ {print}
' > "$TMP"
while [[ -s "$TMP" && -z "$(tail -n1 "$TMP")" ]]; do sed -i '$d' "$TMP"; done
[[ ! -s "$TMP" ]] || printf '\n' >> "$TMP"
cat >> "$TMP" <<EOF
# P2K_MCA_RESULTS_SYNC_BEGIN
# MCA Results Auto-Sync & Date Backfill v2.10.6 — twice daily; source requests are serialized server-side at >=1 second spacing.
17 0,12 * * * P2K_SITE_ROOT=$ROOT P2K_PHP_CLI=$PHP_BIN $BASH_BIN $RUNNER >/dev/null 2>&1
# P2K_MCA_RESULTS_SYNC_END
EOF
crontab "$TMP"
COUNT="$(crontab -l 2>/dev/null | grep -c 'cron-mca-results-v2.10.6.sh' || true)"
if [[ "$COUNT" != "1" ]]; then
  echo "ERROR: MCA CRON verification failed; restoring previous crontab." >&2
  if [[ -n "$OLD" ]]; then printf '%s\n' "$OLD" | crontab -; else crontab -r 2>/dev/null || true; fi
  exit 3
fi
echo "MCA Results Auto-Sync scheduler installed: twice daily at minute 17 of hours 00 and 12 (server time)."
echo "Unrelated CRON entries were preserved."
