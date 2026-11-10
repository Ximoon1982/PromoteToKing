#!/usr/bin/env bash
set -euo pipefail
# Incremental English UI restoration for Team France. Keeps the blue-white-red theme.
ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
ROOT="$(cd "$ROOT" && pwd -P)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PHP_CLI="${PHP_CLI:-/usr/bin/php8.5-cli}"
if [[ ! -x "$PHP_CLI" ]]; then PHP_CLI="$(command -v php || true)"; fi
[[ -n "$PHP_CLI" && -x "$PHP_CLI" ]] || { echo 'PHP CLI is not available.' >&2; exit 1; }
[[ "$HERE" != "$ROOT/TeamFrance" ]] || { echo 'Run the installer from an extracted package outside the live TeamFrance folder.' >&2; exit 1; }
[[ -f "$ROOT/server/team-points/src/OAuthSession.php" && -d "$ROOT/TeamFrance/Widget" && -d "$ROOT/TeamFrance/MatchAnalyzer" && -d "$ROOT/TeamFrance/MatchRecruitment" ]] || { echo 'Existing Team France installation not found.' >&2; exit 1; }
files=(
  index.php home.html README.md api/overview.php
  assets/home.js assets/upcoming-selector.js
  Widget/index.php Widget/admin/index.php Widget/assets/admin.js Widget/assets/widget.js
  MatchAnalyzer/index.php MatchAnalyzer/template.html
  MatchRecruitment/index.php MatchRecruitment/api/index.php
  MatchRecruitment/template.html MatchRecruitment/assets/js/recruitment.js
  MatchRecruitment/README.md install-admin-hub.sh
)
for f in "${files[@]}"; do
  [[ -f "$HERE/$f" ]] || { echo "Missing package file: $f" >&2; exit 1; }
  case "$f" in *.php) "$PHP_CLI" -l "$HERE/$f" >/dev/null;; esac
  case "$f" in *.js) if command -v node >/dev/null 2>&1; then node --check "$HERE/$f"; fi;; esac
done
bash -n "$HERE/install-english-ui.sh"
bash -n "$HERE/install-admin-hub.sh"
[[ -f "$ROOT/TeamFrance/assets/theme.css" && -f "$ROOT/TeamFrance/assets/widget-theme.css" && -f "$ROOT/TeamFrance/assets/upcoming-selector.css" ]] || { echo 'The Team France tricolour theme is missing. Install the prior Team France hub package before this incremental update.' >&2; exit 1; }
BACKUP="$ROOT/storage/team-france-admin-hub-backups/$(date -u +%Y%m%dT%H%M%SZ)-english-$$"
mkdir -p "$BACKUP"
for f in "${files[@]}"; do
  if [[ -f "$ROOT/TeamFrance/$f" ]]; then
    mkdir -p "$BACKUP/$(dirname "$f")"
    cp -p "$ROOT/TeamFrance/$f" "$BACKUP/$f"
  fi
done
# Remove only the translation observer introduced in the earlier Team France hub.
translation="$ROOT/TeamFrance/assets/localize.js"
if [[ -f "$translation" ]] && grep -q 'Traduction de présentation isolée' "$translation"; then
  mkdir -p "$BACKUP/assets"
  cp -p "$translation" "$BACKUP/assets/localize.js"
  rm -- "$translation"
fi
for f in "${files[@]}"; do
  mkdir -p "$ROOT/TeamFrance/$(dirname "$f")"
  cp "$HERE/$f" "$ROOT/TeamFrance/$f"
done
printf 'English Team France administration UI installed at %s/TeamFrance/\n' "$ROOT"
printf 'Existing tricolour CSS, authentication, Widget data, analyzer history, recruitment rosters and TGP files preserved.\n'
printf 'Source backup: %s\n' "$BACKUP"
