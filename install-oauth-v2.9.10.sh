#!/usr/bin/env bash
# Promote to King v2.9.10 OAuth protected-configuration installer.
set -u
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SITE_ROOT=${P2K_SITE_ROOT:-$SCRIPT_DIR}
TARGET="$SITE_ROOT/server/team-points/config/oauth.local.php"
EXAMPLE="$SITE_ROOT/server/team-points/config/oauth.local.example.php"
find_php_cli() {
  local c resolved
  for c in "${P2K_PHP_CLI:-}" /usr/bin/php8.5-cli /usr/bin/php8.4-cli /usr/bin/php8.3-cli /usr/bin/php8.2-cli /usr/bin/php8.1-cli /usr/bin/php8.0-cli php8.5-cli php8.4-cli php8.3-cli php8.2-cli php8.1-cli php8.0-cli php; do
    [[ -n "$c" ]] || continue; resolved="$c"; [[ "$c" != */* ]] && resolved=$(command -v "$c" 2>/dev/null || true)
    [[ -n "$resolved" && -x "$resolved" ]] || continue
    if "$resolved" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1; then printf '%s\n' "$resolved"; return 0; fi
  done
  return 1
}
PHP_BIN=$(find_php_cli || true)
[[ -n "$PHP_BIN" ]] || { echo "ERROR: no working PHP CLI found." >&2; exit 5; }
mkdir -p "$(dirname "$TARGET")" || exit 1
if [[ -f "$TARGET" ]]; then
  echo "Protected OAuth configuration already exists: $TARGET"
  echo "It has not been overwritten. Edit it manually if the Chess.com registration changes."
  chmod 600 "$TARGET" 2>/dev/null || true
  exit 0
fi
[[ -f "$EXAMPLE" ]] || { echo "ERROR: missing $EXAMPLE" >&2; exit 2; }
printf 'Chess.com approved application name: '; IFS= read -r APP_NAME
printf 'Chess.com client ID: '; IFS= read -r CLIENT_ID
DEFAULT_REDIRECT="${P2K_BASE_URL:-https://www.promotetoking.org}/auth/callback"
printf 'Exact approved redirect URL [%s]: ' "$DEFAULT_REDIRECT"; IFS= read -r REDIRECT_URL
REDIRECT_URL=${REDIRECT_URL:-$DEFAULT_REDIRECT}
if [[ -z "$APP_NAME" || -z "$CLIENT_ID" || -z "$REDIRECT_URL" ]]; then echo "ERROR: name, client ID and redirect URL are required." >&2; exit 3; fi
TMP="$TARGET.tmp.$$"
umask 077
cat > "$TMP" <<PHP
<?php
declare(strict_types=1);
return [
    'name' => $(printf '%s' "$APP_NAME" | "$PHP_BIN" -r '$v=stream_get_contents(STDIN);echo var_export($v,true);'),
    'client_id' => $(printf '%s' "$CLIENT_ID" | "$PHP_BIN" -r '$v=stream_get_contents(STDIN);echo var_export($v,true);'),
    'redirect_url' => $(printf '%s' "$REDIRECT_URL" | "$PHP_BIN" -r '$v=stream_get_contents(STDIN);echo var_export($v,true);'),
    'authorize_url' => 'https://oauth.chess.com/authorize',
    'token_url' => 'https://oauth.chess.com/token',
    'scope' => 'openid profile',
];
PHP
mv "$TMP" "$TARGET" || exit 4
chmod 600 "$TARGET" 2>/dev/null || true
echo "Installed protected OAuth configuration: $TARGET"
echo "Future Promote to King release payloads do not contain oauth.local.php."
echo "Real OAuth URL: ${P2K_BASE_URL:-https://www.promotetoking.org}/ui-v2.html?oauth=2"
