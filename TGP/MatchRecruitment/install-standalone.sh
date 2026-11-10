#!/usr/bin/env bash
set -euo pipefail
ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
ROOT="$(cd "$ROOT" && pwd -P)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
DEST="$ROOT/TGP/MatchRecruitment"
PHP_CLI="${PHP_CLI:-/usr/bin/php8.5-cli}"
if [[ ! -x "$PHP_CLI" ]]; then PHP_CLI="$(command -v php || true)"; fi
[[ -n "$PHP_CLI" && -x "$PHP_CLI" ]] || { echo 'PHP CLI not available.' >&2; exit 1; }
[[ -d "$ROOT/server/team-points/src" && -d "$ROOT/TGP" ]] || { echo "Invalid PromoteToKing deployment root: $ROOT" >&2; exit 1; }
FILES=(index.php auth.php MatchPolicy.php api/index.php template.html compat/PreviewIsolation.php assets/js/recruitment-core.js assets/js/recruitment.js assets/css/analyze-match.css assets/css/recruit-match.css data/.htaccess data/.gitignore data/.gitkeep README.md)
for f in "${FILES[@]}"; do [[ -f "$HERE/$f" ]] || { echo "Missing source: $f" >&2; exit 1; }; done
for f in index.php auth.php MatchPolicy.php api/index.php compat/PreviewIsolation.php; do "$PHP_CLI" -l "$HERE/$f" || exit 1; done
if command -v node >/dev/null 2>&1; then node --check "$HERE/assets/js/recruitment.js"; node --check "$HERE/assets/js/recruitment-core.js"; fi
BACKUP="$ROOT/storage/tgp-match-recruitment-backups/$(date -u +%Y%m%dT%H%M%SZ)-$$"
if [[ -d "$DEST" ]]; then
 mkdir -p "$BACKUP"
 for f in "${FILES[@]}"; do
  if [[ -f "$DEST/$f" ]]; then mkdir -p "$BACKUP/$(dirname "$f")"; cp -p "$DEST/$f" "$BACKUP/$f"; fi
 done
 echo "Source backup: $BACKUP"
fi
for f in "${FILES[@]}"; do mkdir -p "$DEST/$(dirname "$f")"; cp "$HERE/$f" "$DEST/$f"; done
mkdir -p "$DEST/data"
chmod 0770 "$DEST/data" 2>/dev/null || true
echo "Installed TGP recruitment in $DEST"
echo "Preserved local membership snapshots under $DEST/data."
echo "URL: https://www.promotetoking.org/TGP/MatchRecruitment/"
