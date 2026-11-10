#!/usr/bin/env bash
set -euo pipefail
ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
ROOT="$(cd "$ROOT" && pwd -P)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PHP_CLI="${PHP_CLI:-/usr/bin/php8.5-cli}"
if [[ ! -x "$PHP_CLI" ]]; then PHP_CLI="$(command -v php || true)"; fi
[[ -n "$PHP_CLI" && -x "$PHP_CLI" ]] || { echo 'PHP CLI missing' >&2; exit 1; }
[[ -d "$ROOT/server/team-points/src" && -d "$ROOT/TGP/MatchAnalyzer" && -d "$ROOT/TGP/MatchRecruitment" ]] || { echo 'Invalid P2K/TGP installation root' >&2; exit 1; }
FILES=(
 TGP/index.php TGP/home.html TGP/api/overview.php
 TGP/assets/home.js TGP/assets/upcoming-selector.js TGP/assets/upcoming-selector.css
 TGP/MatchAnalyzer/template.html TGP/MatchRecruitment/template.html
)
for f in "${FILES[@]}"; do [[ -f "$(dirname "$HERE")/$f" ]] || { echo "Missing: $f" >&2; exit 1; }; done
"$PHP_CLI" -l "$(dirname "$HERE")/TGP/index.php"
"$PHP_CLI" -l "$(dirname "$HERE")/TGP/api/overview.php"
if command -v node >/dev/null 2>&1; then
 node --check "$(dirname "$HERE")/TGP/assets/home.js"
 node --check "$(dirname "$HERE")/TGP/assets/upcoming-selector.js"
fi
STAMP="$(date -u +%Y%m%dT%H%M%SZ)-$$"
BACKUP="$ROOT/storage/tgp-admin-hub-backups/$STAMP"
mkdir -p "$BACKUP"
for f in "${FILES[@]}"; do
  if [[ -f "$ROOT/$f" ]]; then mkdir -p "$BACKUP/$(dirname "$f")"; cp -p "$ROOT/$f" "$BACKUP/$f"; fi
done
for f in "${FILES[@]}"; do mkdir -p "$ROOT/$(dirname "$f")"; cp "$(dirname "$HERE")/$f" "$ROOT/$f"; done
printf 'TGP admin home installed at %s/TGP/\n' "$ROOT"
printf 'Existing analyzer and recruitment application logic and runtime data preserved.\n'
printf 'Backups: %s\n' "$BACKUP"
