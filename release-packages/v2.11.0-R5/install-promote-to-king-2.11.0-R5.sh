#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="${1:-.}"
chmod +x "$HERE/PromoteToKing_v2.11.0_R5_ADMIN_GREEN_INSTALLER.run"
exec "$HERE/PromoteToKing_v2.11.0_R5_ADMIN_GREEN_INSTALLER.run" "$TARGET"
