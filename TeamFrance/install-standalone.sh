#!/usr/bin/env bash
set -euo pipefail
# Deploy Team France Widget and MatchAnalyzer only. No shared P2K/TGP edits.
ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST="$ROOT/TeamFrance"
PHP_CLI="${PHP_CLI:-/usr/bin/php8.5-cli}"
[[ -x "$PHP_CLI" ]] || { echo "Required PHP CLI unavailable: $PHP_CLI" >&2; exit 1; }
[[ -f "$ROOT/server/team-points/src/OAuthSession.php" ]] || { echo "P2K OAuth source missing" >&2; exit 1; }
[[ -f "$ROOT/api/_common.php" ]] || { echo "P2K match monitoring helpers missing" >&2; exit 1; }
[[ -d "$ROOT" ]] || { echo "P2K root missing" >&2; exit 1; }
[[ "$(cd "$ROOT" && pwd -P)/TeamFrance" != "$HERE" ]] || { echo "Install from a separate extracted source directory, not the destination" >&2; exit 1; }
files=(
 Widget/index.php Widget/admin/index.php Widget/api.php Widget/config.php
 Widget/src/Auth.php Widget/src/ChessApi.php Widget/src/Store.php
 Widget/assets/admin.css Widget/assets/admin.js Widget/assets/widget.css Widget/assets/widget.js
 Widget/README.txt Widget/data/.htaccess Widget/data/.gitignore
 Widget/data/showcase.example.json Widget/data/cache/.gitkeep
 MatchAnalyzer/index.php MatchAnalyzer/auth.php MatchAnalyzer/MatchPolicy.php
 MatchAnalyzer/api/index.php MatchAnalyzer/template.html MatchAnalyzer/README.txt
 MatchAnalyzer/assets/analyze-match.js MatchAnalyzer/assets/match-history-ui.js
 MatchAnalyzer/assets/team-france-tracking.js MatchAnalyzer/compat/PreviewIsolation.php
)
for file in "${files[@]}"; do
  [[ -f "$HERE/$file" ]] || { echo "Missing package file: $file" >&2; exit 1; }
  [[ "$file" != *showcase.json ]] || { echo "Runtime state must not ship in installer" >&2; exit 1; }
done
for file in "${files[@]}"; do
  if [[ "$file" == *.php ]]; then "$PHP_CLI" -l "$HERE/$file" >/dev/null || exit 1; fi
done
if command -v node >/dev/null 2>&1; then
  for file in "$HERE"/Widget/assets/*.js "$HERE"/MatchAnalyzer/assets/*.js; do
    node --check "$file" || exit 1
  done
fi
[[ ! -e "$DEST" || -d "$DEST" ]] || { echo "Invalid TeamFrance installation target" >&2; exit 1; }
backup="$ROOT/storage/team-france-backups/$(date -u +%Y%m%dT%H%M%SZ)-$$"
mkdir -p "$backup" "$DEST"
# Preserve pre-existing runtime showcase.json, showcase.lock, and cache contents.
# Before any write, copy ALL existing source files to the recovery directory.
for file in "${files[@]}"; do
  if [[ -f "$DEST/$file" ]]; then
    mkdir -p "$backup/$(dirname "$file")"
    cp -p "$DEST/$file" "$backup/$file"
  fi
done
for file in "${files[@]}"; do
  mkdir -p "$DEST/$(dirname "$file")"
  cp -p "$HERE/$file" "$DEST/$file"
  chmod 644 "$DEST/$file"
done
find "$DEST" -type d -exec chmod 755 {} +
echo "Team France Widget and MatchAnalyzer installed at $DEST"
echo "Source backups: $backup"
echo "Persistent state, shared P2K source, .htaccess routing and TGP were not modified."
