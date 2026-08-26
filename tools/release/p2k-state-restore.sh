#!/usr/bin/env bash
# v2.9.14: restore a compact state archive produced by p2k-state-backup.sh.
set -euo pipefail
ARCHIVE=${1:-}
SITE_ROOT=${2:-$(pwd)}
[[ -n "$ARCHIVE" && -f "$ARCHIVE" ]] || { echo "Usage: $0 <backup.tar.gz> [site-root]" >&2; exit 2; }
[[ -f "$ARCHIVE.sha256" ]] && (cd "$(dirname "$ARCHIVE")" && sha256sum -c "$(basename "$ARCHIVE").sha256")
tar -tzf "$ARCHIVE" >/dev/null
mkdir -p "$SITE_ROOT"
tar -C "$SITE_ROOT" -xzf "$ARCHIVE"
echo "Restored state archive: $ARCHIVE"
