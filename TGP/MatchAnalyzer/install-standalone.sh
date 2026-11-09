#!/usr/bin/env bash
set -euo pipefail
# Standalone sibling-project installer. Does not modify P2K Release Control,
# public slots, Apache routing, OAuth config, or any other TGP application.
ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST="$ROOT/TGP/MatchAnalyzer"
[[ -f "$ROOT/server/team-points/src/OAuthSession.php" ]] || { echo "P2K OAuth not present at $ROOT" >&2; exit 1; }
[[ -f "$ROOT/api/_common.php" ]] || { echo "P2K match registry not present at $ROOT" >&2; exit 1; }
[[ -d "$ROOT/TGP" ]] || { echo "Expected existing TGP directory at $ROOT/TGP" >&2; exit 1; }
for f in index.php auth.php MatchPolicy.php api/index.php template.html assets/tgp-tracking.js assets/analyze-match.js assets/match-history-ui.js compat/PreviewIsolation.php; do
  [[ -f "$HERE/$f" ]] || { echo "Missing installer input $f" >&2; exit 1; }
done
PHP_CLI="/usr/bin/php8.5-cli"
[[ -x "$PHP_CLI" ]] || { echo "Required PHP 8.5 CLI not found" >&2; exit 1; }
for f in index.php auth.php MatchPolicy.php api/index.php compat/PreviewIsolation.php; do "$PHP_CLI" -l "$HERE/$f" || exit 1; done
if [[ -e "$DEST" && ! -d "$DEST" ]]; then echo "Target path is not a directory" >&2; exit 1; fi
BACKUP="$ROOT/storage/tgp-match-analyzer-backups/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$BACKUP" "$DEST"
for f in index.php auth.php MatchPolicy.php api/index.php template.html assets/tgp-tracking.js assets/analyze-match.js assets/match-history-ui.js compat/PreviewIsolation.php; do
  mkdir -p "$DEST/$(dirname "$f")"
  if [[ -f "$DEST/$f" ]]; then
    mkdir -p "$BACKUP/$(dirname "$f")"
    cp -p "$DEST/$f" "$BACKUP/$f"
  fi
  cp -p "$HERE/$f" "$DEST/$f"
  chmod 644 "$DEST/$f"
done
find "$DEST" -type d -exec chmod 755 {} +
echo "Standalone TGP analyzer installed in $DEST"
echo "Recovery copy of existing files (if any): $BACKUP"
echo "The public P2K release and global .htaccess were NOT modified."
