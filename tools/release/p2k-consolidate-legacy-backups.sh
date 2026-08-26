#!/usr/bin/env bash
# v2.9.14: safely consolidate legacy directory-style updater backups.
# Default is dry-run. Use --apply only after reviewing the inventory.
set -euo pipefail

MODE=dry-run
[[ "${1:-}" == "--apply" ]] && { MODE=apply; shift; }
SITE_ROOT=${1:-$(pwd)}
BACKUP_ROOT="$SITE_ROOT/_backup"
[[ -d "$BACKUP_ROOT" ]] || { echo "No _backup directory found."; exit 0; }

printf '%-10s %12s %12s  %s\n' ACTION FILES DIRS PATH
for dir in "$BACKUP_ROOT"/*; do
  [[ -d "$dir" ]] || continue
  files=$(find "$dir" -type f 2>/dev/null | wc -l | tr -d ' ')
  dirs=$(find "$dir" -type d 2>/dev/null | wc -l | tr -d ' ')
  archive="$dir.tar.gz"
  if [[ "$MODE" == dry-run ]]; then
    printf '%-10s %12d %12d  %s\n' WOULD_ARCHIVE "$files" "$dirs" "$dir"
    continue
  fi
  tmp="$archive.tmp-$$"
  rm -f -- "$tmp"
  parent=$(dirname "$dir"); name=$(basename "$dir")
  tar -C "$parent" -czf "$tmp" "$name"
  tar -tzf "$tmp" >/dev/null
  mv "$tmp" "$archive"
  chmod 600 "$archive" 2>/dev/null || true
  sha256sum "$archive" > "$archive.sha256"
  chmod 600 "$archive.sha256" 2>/dev/null || true
  # Only remove the directory after the archive is readable and the source file
  # count is represented by at least that many regular files in the tar listing.
  archived_files=$(tar -tzf "$archive" | grep -v '/$' | wc -l | tr -d ' ')
  if (( archived_files < files )); then
    echo "ERROR: archive verification count mismatch for $dir" >&2
    exit 4
  fi
  rm -rf -- "$dir"
  printf '%-10s %12d %12d  %s\n' ARCHIVED "$files" "$dirs" "$archive"
done
