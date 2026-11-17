#!/usr/bin/env bash
# Offline SSH installer for TGP and Team France Match Showcase v0.3.4.
# Update only standalone code files; NEVER overwrite either club's data state.
set -Eeuo pipefail
umask 077

ROOT_ARG="\${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
MODE="\${2:---install}"
[[ "$MODE" == "--install" || "$MODE" == "--check" ]] ||
  { echo "Usage: bash install.sh [PromoteToKing physical root] [--check]" >&2; exit 2; }

HERE="$(cd "$(dirname "\${BASH_SOURCE[0]}")" && pwd -P)"
[[ -d "$ROOT_ARG" ]] || { echo "PromoteToKing root does not exist: $ROOT_ARG" >&2; exit 2; }
ROOT="$(cd "$ROOT_ARG" && pwd -P)"
[[ "$HERE" != "$ROOT" && "$HERE" != "$ROOT/"* ]] ||
  { echo "Extract the SSH installer outside of the live PromoteToKing tree." >&2; exit 2; }
[[ -f "$HERE/FILE_SHA256SUMS" && -d "$HERE/payload" ]] ||
  { echo "Installer payload or checksum manifest is missing." >&2; exit 2; }
command -v sha256sum >/dev/null || { echo "sha256sum is required." >&2; exit 2; }
(cd "$HERE" && sha256sum --check --status FILE_SHA256SUMS) ||
  { echo "Installer payload checksum verification FAILED." >&2; exit 2; }

PHP_CLI="\${PHP_CLI:-/usr/bin/php8.5-cli}"
if [[ "$PHP_CLI" == */* ]]; then
  [[ -x "$PHP_CLI" ]] || { echo "PHP CLI not executable: $PHP_CLI" >&2; exit 2; }
else
  command -v "$PHP_CLI" >/dev/null || { echo "PHP CLI unavailable: $PHP_CLI" >&2; exit 2; }
fi

widget_files=(
  Widget/README.txt
  Widget/admin/index.php
  Widget/api.php
  Widget/assets/admin.js
  Widget/assets/arena-discovery.js
  Widget/assets/arena-discovery.css
  Widget/config.php
  Widget/src/Store.php
  Widget/src/StandaloneArenaDiscoveryService.php
  Widget/src/ArenaDiscoveryAdminService.php
)
files=()
for club in TGP TeamFrance; do
  target="$ROOT/$club/Widget"
  [[ -d "$target" && ! -L "$target" ]] ||
    { echo "Expected existing standalone widget directory: $target" >&2; exit 2; }
  [[ -f "$target/config.php" && ! -L "$target/config.php" ]] ||
    { echo "Widget config is missing or symlinked: $target/config.php" >&2; exit 2; }
  [[ -f "$target/api.php" && -f "$target/src/Store.php" ]] ||
    { echo "Missing existing widget source in $target" >&2; exit 2; }
  [[ -d "$target/data" && ! -L "$target/data" ]] ||
    { echo "Existing writable widget data directory missing: $target/data" >&2; exit 2; }
  current="$("$PHP_CLI" -r '$v = require $argv[1]; echo (string)($v["version"] ?? "");' "$target/config.php")"
  [[ "$current" == "0.3.3" || "$current" == "0.3.4" ]] ||
    { echo "Unexpected $club widget version '$current': expected 0.3.3 or 0.3.4; no changes made." >&2; exit 2; }
  for suffix in "\${widget_files[@]}"; do
    rel="$club/$suffix"
    src="$HERE/payload/$rel"
    dst="$ROOT/$rel"
    [[ -f "$src" && ! -L "$src" ]] ||
      { echo "Missing or symlinked installer source: $rel" >&2; exit 2; }
    [[ ! -L "$dst" ]] ||
      { echo "Refusing to overwrite symlink: $rel" >&2; exit 2; }
    [[ ! -e "$dst" || -f "$dst" ]] ||
      { echo "Existing target is not a regular file: $rel" >&2; exit 2; }
    case "$rel" in
      *.php) "$PHP_CLI" -l "$src" >/dev/null ||
                { echo "PHP syntax invalid in $rel" >&2; exit 2; } ;;
      *.js) if command -v node >/dev/null; then node --check "$src" >/dev/null; fi ;;
    esac
    files+=("$rel")
  done
  echo "$club widget $current -> 0.3.4: validated."
done

[[ "\${#files[@]}" -eq 20 ]] || { echo "Unexpected installer file count." >&2; exit 2; }
if [[ "$MODE" == "--check" ]]; then
  echo "CHECK OK: 20 files verified; both showcase.json files will remain untouched."
  exit 0
fi

backup_base="$ROOT/storage/standalone-widget-backups"
mkdir -p "$backup_base"
backup="$(mktemp -d "$backup_base/tgp-teamfrance-v034-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX")"
mkdir -p "$backup/original" "$backup/new"
for rel in "\${files[@]}"; do
  dst="$ROOT/$rel"
  if [[ -f "$dst" ]]; then
    mkdir -p "$backup/original/$(dirname "$rel")"
    cp -p "$dst" "$backup/original/$rel"
  else
    mkdir -p "$backup/new/$(dirname "$rel")"
    : > "$backup/new/$rel"
  fi
done
echo "Backups prepared: $backup"

rollback() {
  code=$?
  trap - ERR INT TERM
  set +e
  echo "Installation failed: restoring original standalone widget files from $backup" >&2
  for rel in "\${files[@]}"; do
    dst="$ROOT/$rel"
    if [[ -f "$backup/original/$rel" ]]; then
      cp -p "$backup/original/$rel" "$dst"
    elif [[ -f "$backup/new/$rel" ]]; then
      rm -f "$dst"
    fi
  done
  echo "Rollback complete. Persistent widget data was not touched." >&2
  exit "$code"
}
trap rollback ERR INT TERM

for rel in "\${files[@]}"; do
  dst="$ROOT/$rel"
  mkdir -p "$(dirname "$dst")"
  tmp="$(mktemp "$dst.v034.XXXXXX")"
  cp "$HERE/payload/$rel" "$tmp"
  chmod 0644 "$tmp"
  mv -f "$tmp" "$dst"
  expected="$(sha256sum "$HERE/payload/$rel")"
  expected="\${expected%% *}"
  actual="$(sha256sum "$dst")"
  actual="\${actual%% *}"
  [[ "$actual" == "$expected" ]] || { echo "Installed file hash mismatch: $rel" >&2; false; }
done
trap - ERR INT TERM
echo "SUCCESS: TGP and Team France Match Showcase 0.3.4 installed."
echo "Installed 20 code/documentation files; each club's data/showcase.json and cache are preserved."
echo "Backups: $backup"
echo "Admin: https://www.promotetoking.org/TGP/Widget/admin/"
echo "Admin: https://www.promotetoking.org/TeamFrance/Widget/admin/"
echo "P2K Release Control, routing, public slots, OAuth and CRON were NOT changed."
