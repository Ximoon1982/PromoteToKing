#!/usr/bin/env bash
set -Eeuo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
INSTALLER="$HERE/PromoteToKing_v2.11.0_INCREMENTAL_INSTALLER.run"
TARGET="${1:-${P2K_TARGET:-/homepages/43/d141198007/htdocs/PromoteToKing}}"
[[ -f "$INSTALLER" ]] || { echo "Installer not found: $INSTALLER" >&2; exit 1; }
chmod +x "$INSTALLER"
exec "$INSTALLER" "$TARGET"
