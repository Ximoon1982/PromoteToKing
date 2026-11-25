#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
[[ $# -eq 2 ]] || { echo "Usage: install.sh ROOT --check|--apply" >&2; exit 1; }
ROOT="$1"
MODE="$2"
[[ -n "$ROOT" && -d "$ROOT/TeamFrance" && -f "$ROOT/TeamFrance/home.html" && -f "$ROOT/TeamFrance/MatchAnalyzer/auth.php" ]] || { echo "Expected existing Team France administration hub." >&2; exit 1; }
[[ "$MODE" = --check || "$MODE" = --apply ]] || { echo "Usage: install.sh ROOT --check|--apply" >&2; exit 1; }
ROOT="$(cd "$ROOT" && pwd)"
PHP_BIN=""
for candidate in /usr/bin/php8.5-cli /usr/bin/php8.5 /usr/bin/php8.4-cli /usr/bin/php8.4 php; do
 if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -r 'exit(PHP_VERSION_ID>=80100&&in_array("sqlite",PDO::getAvailableDrivers(),true)&&extension_loaded("curl")?0:1);' 2>/dev/null; then PHP_BIN="$candidate"; break; fi
done
[[ -n "$PHP_BIN" ]] || { echo "PHP >=8.1 with PDO SQLite and cURL required." >&2; exit 1; }
(cd "$HERE/payload" && sha256sum -c "$HERE/SHA256SUMS")
BASELINE="$(cat "$HERE/HUB_BASELINE_SHA256")"
NEW_HUB="$(sha256sum "$HERE/payload/TeamFrance/home.html" | cut -d' ' -f1)"
CURRENT="$(sha256sum "$ROOT/TeamFrance/home.html" | cut -d' ' -f1)"
[[ "$CURRENT" = "$BASELINE" || "$CURRENT" = "$NEW_HUB" ]] || { echo "Team France hub differs from verified baseline; refusing overwrite." >&2; exit 1; }
for d in "$ROOT/TeamFrance" "$ROOT/TeamFrance/Leaderboard" "$ROOT/TeamFrance/Leaderboard/data" "$ROOT/TeamFrance/Leaderboard/src" "$ROOT/TeamFrance/Leaderboard/assets" "$ROOT/TeamFrance/Leaderboard/admin"; do
 [[ ! -L "$d" ]] || { echo "Refusing symlink directory $d" >&2; exit 1; }
done
hash(){ sha256sum "$1" | cut -d' ' -f1; }
while IFS=$'\t' read -r rel allowed new; do
 source="$HERE/payload/$rel"
 target="$ROOT/$rel"
 [[ -f "$source" && ! -L "$target" ]] || { echo "Unsafe path: $rel" >&2; exit 1; }
 [[ "$(hash "$source")" = "$new" ]] || { echo "Payload checksum mismatch: $rel" >&2; exit 1; }
 current=MISSING
 if [[ -e "$target" ]]; then [[ -f "$target" ]] || exit 1; current="$(hash "$target")"; fi
 if [[ "$current" != "$new" && " $allowed " != *" $current "* ]]; then
  echo "Unexpected installed checksum: $rel. Refusing overwrite." >&2; exit 1
 fi
 if [[ "$source" = *.php ]]; then "$PHP_BIN" -l "$source"; fi
done < "$HERE/FILE_CHECKSUMS.tsv"
if [[ "$MODE" = --check ]]; then echo "PASS: preflight or installed state verified."; exit 0; fi
backup="$ROOT/storage/standalone-widget-backups/team-france-leaderboard-$(date -u +%Y%m%dT%H%M%S)-$$"
mkdir -p "$backup"
while IFS= read -r -d '' source; do
 rel="$(realpath --relative-to="$HERE/payload" "$source")"
 target="$ROOT/$rel"
 if [[ -f "$target" ]]; then mkdir -p "$backup/$(dirname "$rel")"; cp -p "$target" "$backup/$rel"; fi
done < <(find "$HERE/payload" -type f -print0)
# Install the application before publishing its hub card.
while IFS= read -r -d '' source; do
 rel="$(realpath --relative-to="$HERE/payload" "$source")"
 [[ "$rel" != TeamFrance/home.html ]] || continue
 target="$ROOT/$rel"
 if [[ -f "$target" ]] && cmp -s "$source" "$target"; then continue; fi
 mkdir -p "$(dirname "$target")"
 tmp="$(mktemp "$(dirname "$target")/.tf-install.XXXXXX")"
 cp "$source" "$tmp"; if [[ -f "$target" ]]; then chmod --reference="$target" "$tmp"; else chmod 0644 "$tmp"; fi; mv -f "$tmp" "$target"
done < <(find "$HERE/payload" -type f -print0)
"$PHP_BIN" -r 'require $argv[1]."/src/Core.php"; $s=new \TeamFrance\Leaderboard\Store($argv[1]."/data"); echo "SQLite storage initialized or preserved.\n";' "$ROOT/TeamFrance/Leaderboard"
tmp="$(mktemp "$ROOT/TeamFrance/.tf-hub.XXXXXX")"; cp "$HERE/payload/TeamFrance/home.html" "$tmp"; chmod 0644 "$tmp"; mv -f "$tmp" "$ROOT/TeamFrance/home.html"
echo "Installed Team France Leaderboard 0.1.0. Backup: $backup"
