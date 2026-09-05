#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
shift || true
chmod +x "$HERE/PromoteToKing_TrophyGallery_POC_2.11x.run"
exec "$HERE/PromoteToKing_TrophyGallery_POC_2.11x.run" "$ROOT" "$@"
