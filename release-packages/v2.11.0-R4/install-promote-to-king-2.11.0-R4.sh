#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="${P2K_ROOT:-/homepages/43/d141198007/htdocs/PromoteToKing}"
exec "$(cd "$(dirname "$0")" && pwd)/PromoteToKing_v2.11.0_R4_RECRUITMENT_INTEGRATION_INSTALLER.run" "$ROOT"
