#!/usr/bin/env bash
set -Eeuo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="${1:-/homepages/43/d141198007/htdocs/PromoteToKing}"
exec "$HERE/PromoteToKing_v2.11.0_R3_RECRUITMENT_ROUTE_INSTALLER.run" "$TARGET"
