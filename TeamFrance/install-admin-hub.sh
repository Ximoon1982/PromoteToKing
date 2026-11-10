#!/usr/bin/env bash
set -euo pipefail
# Installation incrémentale : portail, traduction, sélecteurs et recrutement Team France.
# Aucun code TGP, P2K partagé ou état persistant du widget n'est remplacé.
ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
ROOT="$(cd "$ROOT" && pwd -P)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PHP_CLI="${PHP_CLI:-/usr/bin/php8.5-cli}"
if [[ ! -x "$PHP_CLI" ]]; then PHP_CLI="$(command -v php || true)"; fi
[[ -n "$PHP_CLI" && -x "$PHP_CLI" ]] || { echo 'Interpréteur PHP CLI introuvable.' >&2; exit 1; }
[[ -f "$ROOT/server/team-points/src/OAuthSession.php" && -d "$ROOT/TeamFrance/Widget" && -d "$ROOT/TeamFrance/MatchAnalyzer" ]] || { echo 'Installation PromoteToKing / TeamFrance invalide.' >&2; exit 1; }
[[ "$HERE" != "$ROOT/TeamFrance" ]] || { echo 'Installez depuis un répertoire distinct de la destination.' >&2; exit 1; }
files=(
  index.php home.html README.md api/overview.php
  assets/home.js assets/theme.css assets/widget-theme.css assets/localize.js
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
 [[ -f "$HERE/$f" ]] || { echo "Fichier manquant : $f" >&2; exit 1; }
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
printf 'Portail Team France installé : %s/TeamFrance/\n' "$ROOT"
printf 'Recrutement Team France : %s/TeamFrance/MatchRecruitment/\n' "$ROOT"
printf 'Sauvegardes des fichiers remplacés : %s\n' "$BACKUP"
printf 'Données Widget, historique Analyseur, cache Recrutement, services P2K et TGP conservés.\n'
