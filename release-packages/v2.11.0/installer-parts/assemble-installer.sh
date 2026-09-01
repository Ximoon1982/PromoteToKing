#!/usr/bin/env bash
set -Eeuo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
OUT="${1:-$HERE/../PromoteToKing_v2.11.0_INCREMENTAL_INSTALLER.run}"
EXPECTED="9d0e7f7643c33418d56d989592e2122cc30212f5999816a5f751b3c943a849d5"
cat \
  "$HERE/part-00" \
  "$HERE/part-01" \
  "$HERE/part-02" \
  "$HERE/part-03" \
  "$HERE/part-04" \
  "$HERE/part-05" \
  "$HERE/part-06a" \
  "$HERE/part-06b" > "$OUT"
ACTUAL="$(sha256sum "$OUT" | awk '{print $1}')"
[[ "$ACTUAL" == "$EXPECTED" ]] || { echo "Assembler checksum mismatch: $ACTUAL" >&2; rm -f "$OUT"; exit 1; }
chmod +x "$OUT"
echo "Created $OUT"
echo "SHA256 $ACTUAL"
