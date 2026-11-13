#!/usr/bin/env bash
# One-time, reversible physical-root bootstrap. Not a Release Control overlay.
set -euo pipefail
ROOT="${1:-}"
MODE="${2:---check}"
if [[ -z "$ROOT" || ! -d "$ROOT" ]]; then echo 'Usage: install-mca-cron-dispatcher-v21510.sh <P2K physical root> [--check|--apply]' >&2;exit 2;fi
[[ "$MODE" == '--check' || "$MODE" == '--apply' ]] || { echo 'Expected --check or --apply' >&2;exit 2; }
ROOT="$(cd "$ROOT" && pwd -P)"
SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)/cron-mca-arena-v2.10.9.sh"
DEST="$ROOT/cron-mca-arena-v2.10.9.sh"
[[ -f "$SOURCE" && -f "$DEST" ]] || { echo 'Source launcher or existing physical CRON launcher missing. Nothing changed.' >&2;exit 2; }
[[ -f "$ROOT/server/release-control/src/ReleaseStateStore.php" ]] || { echo 'Missing physical Release Control state reader. Nothing changed.' >&2;exit 2; }
bash -n "$SOURCE"
echo 'Checking currently selected MCA runtime (no database writes)...'
P2K_MCA_PHYSICAL_ROOT="$ROOT" bash "$SOURCE" --print-target
if cmp -s "$SOURCE" "$DEST"; then echo 'Already installed: no change needed.';exit 0;fi
if [[ "$MODE" == '--check' ]]; then echo 'CHECK ONLY: Physical launcher differs; use --apply to atomically activate with backup.';exit 0;fi
# Never change the cron schedule, database, runtime trees, or public release state.
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="$DEST.before-v21510-$STAMP"
[[ ! -e "$BACKUP" ]] || { echo "Backup already exists: $BACKUP" >&2;exit 2; }
cp -p "$DEST" "$BACKUP"
TMP="$DEST.tmp-v21510-$$"
trap 'rm -f "$TMP"' EXIT
cp "$SOURCE" "$TMP"
chmod 0755 "$TMP"
mv -f "$TMP" "$DEST"
trap - EXIT
printf 'Installed release-aware MCA CRON launcher.\nBackup: %s\n' "$BACKUP"
bash "$DEST" --print-target
