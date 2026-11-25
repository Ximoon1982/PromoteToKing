#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
[[ $# -eq 2 ]] || { echo "Usage: install.sh ROOT --check|--apply" >&2; exit 1; }
ROOT="$(cd "$1" && pwd)"
MODE="$2"
[[ "$MODE" = --check || "$MODE" = --apply ]] || exit 1
PHP_BIN=""
for candidate in /usr/bin/php8.5-cli /usr/bin/php8.5 /usr/bin/php8.4-cli /usr/bin/php8.4 php; do
 if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -r 'exit(PHP_VERSION_ID>=80100?0:1);' 2>/dev/null; then PHP_BIN="$candidate"; break; fi
done
[[ -n "$PHP_BIN" ]] || { echo 'PHP >=8.1 required.' >&2; exit 1; }
for d in "$ROOT/TeamFrance" "$ROOT/TeamFrance/Leaderboard" "$ROOT/TeamFrance/Leaderboard/src" "$ROOT/TeamFrance/Leaderboard/assets" "$ROOT/TeamFrance/Leaderboard/admin"; do
 [[ -d "$d" && ! -L "$d" ]] || { echo "Unexpected directory: $d" >&2; exit 1; }
done
hash(){ sha256sum "$1" | cut -d' ' -f1; }
changed=0
while IFS=$'\t' read -r rel allowed new; do
 target="$ROOT/$rel";source="$HERE/payload/$rel"
 [[ ! -L "$target" && -f "$source" ]] || { echo "Unsafe path: $rel" >&2; exit 1; }
 [[ "$(hash "$source")" = "$new" ]] || { echo "Payload checksum mismatch: $rel" >&2; exit 1; }
 current=MISSING
 if [[ -e "$target" ]]; then [[ -f "$target" ]] || exit 1; current="$(hash "$target")"; fi
 if [[ "$current" != "$new" ]]; then
  [[ " $allowed " = *" $current "* ]] || { echo "Unexpected installed checksum: $rel" >&2; exit 1; }
  changed=$((changed+1))
 fi
 if [[ "$source" = *.php ]]; then "$PHP_BIN" -l "$source"; fi
done < "$HERE/FILE_CHECKSUMS.tsv"
if [[ "$MODE" = --check || "$changed" = 0 ]]; then echo "PASS: Leaderboard cumulative patch verified ($changed files to update)."; exit 0; fi
BACKUP="$ROOT/storage/standalone-widget-backups/team-france-leaderboard-player-info-$(date -u +%Y%m%dT%H%M%S)-$$"
mkdir -p "$BACKUP"
while IFS=$'\t' read -r rel allowed new; do
 target="$ROOT/$rel"
 if [[ -f "$target" ]]; then mkdir -p "$BACKUP/$(dirname "$rel")";cp -p "$target" "$BACKUP/$rel";fi
done < "$HERE/FILE_CHECKSUMS.tsv"
while IFS=$'\t' read -r rel allowed new; do
 target="$ROOT/$rel";source="$HERE/payload/$rel"
 if [[ -f "$target" ]] && [[ "$(hash "$target")" = "$new" ]]; then continue; fi
 tmp="$(mktemp "$(dirname "$target")/.tf-arena-fix.XXXXXX")"
 trap 'rm -f "$tmp"' EXIT
 cp "$source" "$tmp"
 if [[ -f "$target" ]]; then chmod --reference="$target" "$tmp";else chmod 0644 "$tmp";fi
 mv -f "$tmp" "$target"
 [[ "$(hash "$target")" = "$new" ]]
done < "$HERE/FILE_CHECKSUMS.tsv"
echo "PASS: Leaderboard cumulative patch installed. Backup: $BACKUP"
