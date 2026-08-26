#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP="${P2K_PHP_CLI:-}"
if [[ -z "$PHP" ]]; then
  for candidate in /usr/bin/php8.5-cli /usr/bin/php8.4-cli /usr/bin/php8.3-cli /usr/bin/php8.2-cli /usr/bin/php8.1-cli /usr/bin/php8.0-cli /usr/bin/php php; do
    if [[ "$candidate" == "php" ]]; then command -v php >/dev/null 2>&1 && PHP="$(command -v php)" && break
    elif [[ -x "$candidate" ]]; then PHP="$candidate"; break
    fi
  done
fi
[[ -n "$PHP" ]] || { echo "No PHP 8 CLI found." >&2; exit 1; }
exec "$PHP" "$ROOT/server/team-points/bin/mca-results-sync.php" "${1:-120}"
