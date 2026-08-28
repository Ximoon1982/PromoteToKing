#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
INSTALLER="$HERE/PromoteToKing_v2.10.6.25_INCREMENTAL_INSTALLER_R3.run"
[[ -f "$INSTALLER" ]] || { echo "ERROR: Incremental installer not found: $INSTALLER" >&2; exit 2; }
exec bash "$INSTALLER" "${1:-${P2K_SITE_ROOT:-$PWD}}"
