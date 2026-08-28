#!/usr/bin/env bash
set -Eeuo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
TARGET="${1:-${P2K_SITE_ROOT:-$PWD}}"
exec bash "$HERE/PromoteToKing_v2.10.7_INCREMENTAL_INSTALLER_CLEAN.run" "$TARGET"
