#!/usr/bin/env bash
set -Eeuo pipefail
HERE="$(cd "$(dirname "$0")" && pwd -P)"
TARGET="${1:-$PWD}"
exec "$HERE/PromoteToKing_v2.10.9.1_INCREMENTAL_INSTALLER_FINAL.run" "$TARGET"
