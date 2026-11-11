#!/usr/bin/env bash
# Standalone layout rows + arena priority + French zero-need overlay, v2.15.3.
set -Eeuo pipefail
ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PHP_CLI="${PHP_CLI:-}"
if [[ -z "$PHP_CLI" ]]; then
  if [[ -x /usr/bin/php8.5-cli ]]; then PHP_CLI=/usr/bin/php8.5-cli
  else PHP_CLI="$(command -v php || true)"; fi
fi
[[ -n "$PHP_CLI" && -x "$PHP_CLI" ]] || { echo "PHP CLI is required; set PHP_CLI." >&2; exit 1; }
[[ -d "$ROOT" ]] || { echo "Target root does not exist: $ROOT" >&2; exit 1; }
ROOT="$(cd "$ROOT" && pwd -P)"
[[ "$HERE" != "$ROOT" ]] || { echo "Extract the package outside the site root." >&2; exit 1; }
[[ -f "$ROOT/server/team-points/src/OAuthSession.php" ]] || { echo "Expected P2K OAuth installation missing." >&2; exit 1; }
for club in TGP TeamFrance; do
  [[ -f "$ROOT/$club/Widget/src/Store.php" && -f "$ROOT/$club/Widget/config.php" ]] || {
    echo "$club Widget is not installed; refusing partial overlay." >&2; exit 1;
  }
done
FILES=()
for club in TGP TeamFrance; do
  for rel in README.txt admin/index.php api.php assets/admin.css assets/admin.js \
             assets/widget.css assets/widget.js config.php index.php src/Store.php; do
    FILES+=("$club/Widget/$rel")
  done
done
for file in "${FILES[@]}"; do
  [[ -f "$HERE/$file" && ! -L "$HERE/$file" ]] || { echo "Missing or unsafe package file: $file" >&2; exit 1; }
  [[ ! -L "$ROOT/$file" ]] || { echo "Refusing symlink target: $ROOT/$file" >&2; exit 1; }
  case "$file" in
    *.php) "$PHP_CLI" -l "$HERE/$file" >/dev/null ;;
    *.js) if command -v node >/dev/null 2>&1; then node --check "$HERE/$file" >/dev/null; fi ;;
  esac
done
BACKUP="$ROOT/storage/widget-layout-rows-backups/$(date -u +%Y%m%dT%H%M%SZ)-$$"
mkdir -p "$BACKUP"
INSTALLED=()
rollback() {
  local status=$?
  trap - ERR
  set +e
  echo "Installer failed (status $status). Restoring previously replaced files..." >&2
  for file in "${INSTALLED[@]}"; do
    if [[ -f "$BACKUP/$file" ]]; then cp -p "$BACKUP/$file" "$ROOT/$file"
    else rm -f "$ROOT/$file"; fi
  done
  echo "Recovery copies: $BACKUP" >&2
  exit "$status"
}
trap rollback ERR
for file in "${FILES[@]}"; do
  dest="$ROOT/$file"
  mkdir -p "$(dirname "$dest")" "$BACKUP/$(dirname "$file")"
  if [[ -f "$dest" ]]; then cp -p "$dest" "$BACKUP/$file"; fi
  tmp="$(mktemp "$(dirname "$dest")/.widget-update.XXXXXX")"
  cp "$HERE/$file" "$tmp"
  chmod 0644 "$tmp"
  mv -f "$tmp" "$dest"
  INSTALLED+=("$file")
done
trap - ERR
echo "Installed adjustable card rows, centered headings, arena priority, and Team France Manque labels in v2.15.3 widgets."
echo "Backups: $BACKUP"
echo "Persistent data/showcase.json, showcase.lock and cache were not touched."
echo "No deployment through P2K Release Control and no changes to other P2K tools."
