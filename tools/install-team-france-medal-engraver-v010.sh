#!/usr/bin/env bash
set -euo pipefail
[[ $# == 2 && ( "$2" == --check || "$2" == --apply ) ]] || { echo 'Usage: bash install.sh ROOT --check|--apply' >&2; exit 2; }
ROOT="$(cd "$1" && pwd -P)"
DIR="$(cd "$(dirname "$0")" && pwd -P)"
APP="$ROOT/TeamFrance/MedalEngraver"
HUB="$ROOT/TeamFrance/home.html"
test -f "$ROOT/TeamFrance/MatchAnalyzer/auth.php"
test -f "$HUB"
test ! -L "$ROOT/TeamFrance"
test ! -L "$HUB"
test ! -L "$APP"
(cd "$DIR/payload" && sha256sum -c "$DIR/SHA256SUMS")
if ! cmp -s "$HUB" "$DIR/hub-baseline.html" && ! cmp -s "$HUB" "$DIR/payload/TeamFrance/home.html"; then
    echo 'Unexpected Team France hub revision; refusing to overwrite it.' >&2; exit 1
fi
while IFS= read -r -d '' f; do
    rel="$(realpath --relative-to="$DIR/payload/TeamFrance/MedalEngraver" "$f")"
    p="$APP/$rel"
    test ! -L "$p"
    if [[ -e "$p" ]] && ! cmp -s "$f" "$p"; then
        prior="$(awk -v path="TeamFrance/MedalEngraver/$rel" '$2==path {print $1}' "$DIR/PRIOR_SHA256SUMS")"
        actual="$(sha256sum "$p" | cut -d ' ' -f1)"
        if [[ -z "$prior" || "$actual" != "$prior" ]]; then echo "Unexpected existing file: $p" >&2; exit 1; fi
    fi
    parent="$(dirname "$p")"
    while [[ "$parent" != "$ROOT/TeamFrance" ]]; do test ! -L "$parent"; parent="$(dirname "$parent")"; done
done < <(find "$DIR/payload/TeamFrance/MedalEngraver" -type f -print0)
if [[ "$2" == --check ]]; then echo 'PASS: installer and hub preflight'; exit 0; fi
BACKUP="$(mktemp -d "$HOME/tf-medal-engraver-backup.XXXXXX")"
cp -p "$HUB" "$BACKUP/home.html"
if [[ -d "$APP" ]]; then cp -a "$APP" "$BACKUP/MedalEngraver"; fi
while IFS= read -r -d '' f; do
    rel="$(realpath --relative-to="$DIR/payload/TeamFrance/MedalEngraver" "$f")"
    p="$APP/$rel"; mkdir -p "$(dirname "$p")"
    if ! cmp -s "$f" "$p"; then tmp="$(mktemp "$(dirname "$p")/.medal.XXXXXX")"; cp "$f" "$tmp"; chmod 0644 "$tmp"; mv -f "$tmp" "$p"; fi
done < <(find "$DIR/payload/TeamFrance/MedalEngraver" -type f -print0)
if ! cmp -s "$HUB" "$DIR/payload/TeamFrance/home.html"; then
    tmp="$(mktemp "$ROOT/TeamFrance/.hub.XXXXXX")"; cp "$DIR/payload/TeamFrance/home.html" "$tmp"; chmod --reference="$HUB" "$tmp"; mv -f "$tmp" "$HUB"
fi
echo "Installed. Backup: $BACKUP"
