#!/usr/bin/env bash
set -Eeuo pipefail

readonly DEFAULT_ROOT="/kunden/homepages/43/d141198007/htdocs/PromoteToKing"
readonly TARGET=${1:-"$DEFAULT_ROOT"}
readonly MANIFEST="$TARGET/site-manifest.json"
readonly OLD_HASH="117b2f7026066482560c3bd862b8efb80756294f0ccaf9c0a3653b79b086f183"
readonly NEW_HASH="280334674632b569751d2d494a91100820523b1ac850001e42459fe6893be949"

BACKUP=""
TEMP=""
COMMITTED=0

cleanup() {
  [[ -z "$TEMP" || ! -e "$TEMP" ]] || rm -f -- "$TEMP"
  [[ -z "$BACKUP" || ! -e "$BACKUP" || $COMMITTED -eq 0 ]] || rm -f -- "$BACKUP"
}

rollback() {
  local status=$?
  trap - ERR INT TERM
  if [[ $COMMITTED -eq 0 && -n "$BACKUP" && -f "$BACKUP" ]]; then
    cp -p -- "$BACKUP" "$MANIFEST" || true
  fi
  cleanup
  exit "$status"
}
trap rollback ERR INT TERM
trap cleanup EXIT

[[ -d "$TARGET" ]] || { echo "ERROR: target directory does not exist: $TARGET" >&2; exit 2; }
[[ -f "$TARGET/VERSION" ]] || { echo "ERROR: target VERSION is missing" >&2; exit 2; }
[[ $(tr -d '\r\n[:space:]' <"$TARGET/VERSION") == "2.12.0" ]] \
  || { echo "ERROR: this correction applies only to P2K 2.12.0" >&2; exit 2; }
[[ -f "$MANIFEST" ]] || { echo "ERROR: site-manifest.json is missing" >&2; exit 2; }
grep -Fq 'version: "2.12.0"' "$TARGET/assets/js/site-config.js" \
  || { echo "ERROR: site-config is not v2.12.0" >&2; exit 2; }

current=$(sha256sum "$MANIFEST" | awk '{print $1}')
if [[ "$current" == "$NEW_HASH" ]]; then
  echo "v2.12.0 site-manifest correction is already installed; no change required."
  exit 0
fi
[[ "$current" == "$OLD_HASH" ]] \
  || { echo "ERROR: site-manifest.json is not the exact qualified pre-correction file" >&2; exit 2; }

mode=$(stat -c '%a' "$MANIFEST")
BACKUP=$(mktemp "$TARGET/.p2k-v2120-site-manifest.backup.XXXXXX")
TEMP=$(mktemp "$TARGET/.p2k-v2120-site-manifest.new.XXXXXX")
cp -p -- "$MANIFEST" "$BACKUP"
cat >"$TEMP" <<'JSON'
{
  "schemaVersion": 8,
  "version": "2.12.0",
  "builtAt": "2026-09-14T13:55:55Z",
  "baseline": "Promote to King cumulative v2.11.5 qualified production family.",
  "release": {
    "corrective": false,
    "scope": [
      "Match Recruitment Assistant",
      "DB-first rating and registration reduction",
      "Chess.com opponent-membership and hard eligibility verification",
      "shared OAuth/API scheduler for live player checks",
      "configurable online-window and timeout thresholds with funnel, ETA, partial failures and CSV export",
      "universal transactional upgrade from supported v2.11.x trees",
      "exact cumulative v2.11.5 lived-production baseline support",
      "site-manifest release identity convergence"
    ],
    "sourceBaseline": "v2.11.5",
    "databaseSchemaChange": false,
    "runtimeDataReset": false,
    "productionDatabase": "green",
    "blueDatabaseMode": "recovery-only"
  },
  "preservedPriorManifest": {
    "path": "site-manifest.json@v2.11.0",
    "version": "2.11.0"
  },
  "releaseNotes": [
    "RELEASE_NOTES_v2.12.0.md",
    "RELEASE_NOTES_v2.11.0.md",
    "RELEASE_NOTES_v2.10.9.8.md",
    "RELEASE_NOTES_v2.10.9.7.md",
    "RELEASE_NOTES_v2.10.9.6.md",
    "RELEASE_NOTES_v2.10.9.5.md",
    "RELEASE_NOTES_v2.10.9.4.md",
    "RELEASE_NOTES_v2.10.9.3.md",
    "RELEASE_NOTES_v2.10.9.2.md",
    "RELEASE_NOTES_v2.10.9.1.md",
    "RELEASE_NOTES_v2.10.9.md",
    "RELEASE_NOTES_v2.10.8.md"
  ]
}
JSON
chmod "$mode" "$TEMP"
[[ $(sha256sum "$TEMP" | awk '{print $1}') == "$NEW_HASH" ]] \
  || { echo "ERROR: generated corrected manifest hash mismatch" >&2; exit 2; }
mv -f -- "$TEMP" "$MANIFEST"
TEMP=""
[[ $(sha256sum "$MANIFEST" | awk '{print $1}') == "$NEW_HASH" ]] \
  || { echo "ERROR: installed corrected manifest hash mismatch" >&2; exit 2; }
COMMITTED=1
rm -f -- "$BACKUP"
BACKUP=""
echo "v2.12.0 site-manifest correction installed transactionally."
