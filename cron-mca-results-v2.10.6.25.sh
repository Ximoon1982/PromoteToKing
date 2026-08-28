#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

choose_php() {
  local candidate resolved
  for candidate in "${P2K_PHP_CLI:-}" \
    /usr/bin/php8.5-cli /usr/bin/php8.5 \
    /usr/bin/php8.4-cli /usr/bin/php8.4 \
    /usr/bin/php8.3-cli /usr/bin/php8.3 \
    /usr/bin/php8.2-cli /usr/bin/php8.2 \
    /usr/bin/php8.1-cli /usr/bin/php8.1 \
    php8.5-cli php8.5 php8.4-cli php8.4 php8.3-cli php8.3 php8.2-cli php8.2 php8.1-cli php8.1 php; do
    [[ -n "$candidate" ]] || continue
    resolved="$candidate"
    [[ "$candidate" == */* ]] || resolved="$(command -v "$candidate" 2>/dev/null || true)"
    [[ -n "$resolved" && -x "$resolved" ]] || continue
    if "$resolved" -r 'exit(PHP_SAPI === "cli" && PHP_VERSION_ID >= 80100 ? 0 : 1);' >/dev/null 2>&1; then
      printf '%s\n' "$resolved"
      return 0
    fi
  done
  return 1
}

PHP_BIN="$(choose_php || true)"
[[ -n "$PHP_BIN" ]] || {
  echo "No compatible PHP CLI found (PHP 8.1+ required). On IONOS use /usr/bin/php8.5-cli or /usr/bin/php8.5." >&2
  exit 1
}
exec "$PHP_BIN" "$ROOT/server/team-points/bin/mca-results-sync.php" "${1:-120}"
