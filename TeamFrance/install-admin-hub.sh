#!/usr/bin/env bash
set -euo pipefail
# Incremental installation: Team France admin hub, English interface, match selectors and recruitment.
# No TGP code, shared P2K service, or persistent Widget state is overwritten.
ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
ROOT="$(cd "$ROOT" && pwd -P)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PHP_CLI="${PHP_CLI:-/usr/bin/php8.5-cli}"
if [[ ! -x "$PHP_CLI" ]]; then PHP_CLI="$(command -v php || true)"; fi
[[ -n "$PHP_CLI" && -x "$PHP_CLI" ]] || { echo 'PHP CLI is not available.' >&2; exit 1; }
[[ -f "$ROOT/server/team-points/src/OAuthSession.php" && -d "$ROOT/TeamFrance/Widget" && -d "$ROOT/TeamFrance/MatchAnalyzer" ]] || { echo 'Invalid PromoteToKing / TeamFrance installation.' >&2; exit 1; }
[[ "$HERE" != "$ROOT/TeamFrance" ]] || { echo 'Run from an extracted folder outside the destination.' >&2; exit 1; }
files=(
  index.php home.html README.md api/overview.php
  assets/home.js assets/theme.css assets/widget-theme.css
  assets/upcoming-selector.js assets/upcoming-selector.css
  Widget/admin/index.php Widget/index.php Widget/assets/admin.js Widget/assets/widget.js
  MatchAnalyzer/index.php MatchAnalyzer/template.html
  MatchRecruitment/index.php MatchRecruitment/auth.php MatchRecruitment/MatchPolicy.php
  MatchRecruitment/api/index.php MatchRecruitment/template.html MatchRecruitment/README.md
  MatchRecruitment/compat/PreviewIsolation.php
  MatchRecruitment/assets/js/recruitment.js MatchRecruitment/assets/js/recruitment-core.js
  MatchRecruitment/assets/css/analyze-match.css MatchRecruitment/assets/css/recruit-match.css
  MatchRecruitment/data/.htaccess MatchRecruitment/data/.gitignore MatchRecruitment/data/.gitkeep
)
for f in "${files[@]}"; do
 [[ -f "$HERE/$f" ]] || { echo "Missing package file: $f" >&2; exit 1; }
 case "$f" in *.php) "$PHP_CLI" -l "$HERE/$f" >/dev/null ;; esac
done
bash -n "$HERE/install-admin-hub.sh"
if command -v node >/dev/null 2>&1; then
 for f in "${files[@]}"; do case "$f" in *.js) node --check "$HERE/$f" ;; esac; done
fi
BACKUP="$ROOT/storage/team-france-admin-hub-backups/$(date -u +%Y%m%dT%H%M%SZ)-$$"
mkdir -p "$BACKUP"
for f in "${files[@]}"; do
 if [[ -f "$ROOT/TeamFrance/$f" ]]; then
   mkdir -p "$BACKUP/$(dirname "$f")"
   cp -p "$ROOT/TeamFrance/$f" "$BACKUP/$f"
 fi
done
for f in "${files[@]}"; do
 mkdir -p "$ROOT/TeamFrance/$(dirname "$f")"
 cp "$HERE/$f" "$ROOT/TeamFrance/$f"
done
mkdir -p "$ROOT/TeamFrance/MatchRecruitment/data"
chmod 0770 "$ROOT/TeamFrance/MatchRecruitment/data" 2>/dev/null || true
printf 'Team France admin hub installed: %s/TeamFrance/\n' "$ROOT"
printf 'Team France recruitment: %s/TeamFrance/MatchRecruitment/\n' "$ROOT"
printf 'Replaced source file backups: %s\n' "$BACKUP"
printf 'Widget data, Analyzer history, Recruitment cache, P2K services and TGP files preserved.\n'
