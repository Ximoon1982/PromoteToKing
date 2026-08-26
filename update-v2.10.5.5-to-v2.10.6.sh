#!/usr/bin/env bash
set -Eeuo pipefail
TARGET_VERSION="2.10.6"
SOURCE_VERSION_EXPECTED="2.10.5.5"
ROOT="${1:-$PWD}"
PKG="${2:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)}"
ROOT="$(cd "$ROOT" && pwd -P)"; PKG="$(cd "$PKG" && pwd -P)"
FILES="$PKG/FILES.tsv"; PAYLOAD="$PKG/payload"
fail(){ echo "ERROR: $*" >&2; exit 2; }
trim(){ tr -d '\r\n[:space:]' < "$1"; }
[[ -f "$ROOT/VERSION" ]] || fail "VERSION missing from $ROOT"
SOURCE_VERSION="$(trim "$ROOT/VERSION")"
if [[ "$SOURCE_VERSION" == "$TARGET_VERSION" ]]; then echo "Promote to King v$TARGET_VERSION is already installed; no files were changed."; exit 0; fi
[[ "$SOURCE_VERSION" == "$SOURCE_VERSION_EXPECTED" ]] || fail "Expected v$SOURCE_VERSION_EXPECTED, found '$SOURCE_VERSION'."
[[ -f "$FILES" && -d "$PAYLOAD" ]] || fail "Incremental payload/FILES.tsv missing."
command -v sha256sum >/dev/null 2>&1 || fail "sha256sum is required."
PHP_BIN=""
for cand in "${P2K_PHP_CLI:-}" /usr/bin/php8.5-cli /usr/bin/php8.5 /usr/bin/php8.4-cli /usr/bin/php8.4 /usr/bin/php8.3-cli /usr/bin/php8.3 /usr/bin/php8.2-cli /usr/bin/php8.2 /usr/bin/php8.1-cli /usr/bin/php8.1 /usr/bin/php8.0-cli /usr/bin/php8.0 php8.5-cli php8.5 php8.4-cli php8.4 php8.3-cli php8.3 php8.2-cli php8.2 php8.1-cli php8.1 php8.0-cli php8.0 php; do
  [[ -n "$cand" ]] || continue; resolved="$cand"; [[ "$cand" != */* ]] && resolved="$(command -v "$cand" 2>/dev/null || true)"
  [[ -n "$resolved" && -x "$resolved" ]] || continue
  if "$resolved" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1; then PHP_BIN="$resolved"; break; fi
done
[[ -n "$PHP_BIN" ]] || fail "PHP CLI 8+ is required."

while IFS=$'\t' read -r rel expected; do
  [[ -n "$rel" ]] || continue; [[ "$rel" != /* && "$rel" != *".."* ]] || fail "Unsafe payload path: $rel"
  [[ -f "$PAYLOAD/$rel" ]] || fail "Payload missing: $rel"
  got="$(sha256sum "$PAYLOAD/$rel" | awk '{print $1}')"; [[ "$got" == "$expected" ]] || fail "Payload SHA-256 mismatch: $rel"
done < "$FILES"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"; BACKUP="$ROOT/_p2k_backups/v${SOURCE_VERSION}-to-v${TARGET_VERSION}-$STAMP"; mkdir -p "$BACKUP/files"; EXISTED="$BACKUP/existed.tsv"; : > "$EXISTED"
rollback(){ rc=$?; trap - ERR INT TERM; echo "Update failed; restoring v$SOURCE_VERSION files..." >&2; while IFS=$'\t' read -r rel existed; do [[ -n "$rel" ]] || continue; if [[ "$existed" == 1 ]]; then mkdir -p "$(dirname "$ROOT/$rel")"; cp -p "$BACKUP/files/$rel" "$ROOT/$rel"; else rm -f "$ROOT/$rel"; fi; done < "$EXISTED"; echo "File rollback complete. Additive DB migrations, if already committed, are intentionally retained." >&2; exit "$rc"; }
trap rollback ERR INT TERM

while IFS=$'\t' read -r rel expected; do
  [[ -n "$rel" ]] || continue; dst="$ROOT/$rel"
  if [[ -f "$dst" ]]; then printf '%s\t1\n' "$rel" >> "$EXISTED"; mkdir -p "$BACKUP/files/$(dirname "$rel")"; cp -p "$dst" "$BACKUP/files/$rel"; else printf '%s\t0\n' "$rel" >> "$EXISTED"; fi
  mkdir -p "$(dirname "$dst")"; cp -p "$PAYLOAD/$rel" "$dst"; [[ "$rel" == *.sh ]] && chmod 755 "$dst" 2>/dev/null || true
done < "$FILES"

[[ "$(trim "$ROOT/VERSION")" == "$TARGET_VERSION" ]] || fail "VERSION did not advance."
[[ "$(trim "$ROOT/MIGRATION_VERSION")" == "$TARGET_VERSION" ]] || fail "MIGRATION_VERSION did not advance."
grep -q 'version: "2.10.6"' "$ROOT/assets/js/site-config.js" || fail "Browser release marker is stale."

# Additive Blue/compatibility schema migration.
"$PHP_BIN" -r '
require $argv[1]."/server/team-points/src/bootstrap.php";
$r=new \P2K\TeamPoints\Repository(\P2K\TeamPoints\Database::core(),\P2K\TeamPoints\Database::analytics());
if(!$r->upgradeExistingSchema() || $r->schemaVersion()<17 || $r->analyticsSchemaVersion()<9){fwrite(STDERR,"Blue/compatibility schema migration did not reach Core 17 / Analytics 9.\n");exit(3);} echo "Blue/compatibility schema: Core ".$r->schemaVersion()." / Analytics ".$r->analyticsSchemaVersion().".\n";
' "$ROOT"

# Green compatibility schema uses the same mature Repository contract when Green is configured.
if [[ -f "$ROOT/server/team-points-green/config/green.local.php" ]]; then
  "$PHP_BIN" -r '
  require $argv[1]."/server/team-points/src/bootstrap.php"; require $argv[1]."/server/team-points-green/src/bootstrap.php";
  $g=\P2K\Green\GreenRepository::open();$s=(new \P2K\Green\GreenCompatibility($g))->ensureSchema();
  if((int)($s["core_schema"]??0)<17||(int)($s["analytics_schema"]??0)<9){fwrite(STDERR,"Green compatibility schema did not reach Core 17 / Analytics 9.\n");exit(4);} echo "Green compatibility schema: Core ".$s["core_schema"]." / Analytics ".$s["analytics_schema"].".\n";
  ' "$ROOT"
fi

while IFS=$'\t' read -r rel expected; do [[ -n "$rel" ]] || continue; case "$rel" in *.php) "$PHP_BIN" -l "$ROOT/$rel" >/dev/null;; *.sh) bash -n "$ROOT/$rel";; esac; done < "$FILES"

# Install only the dedicated MCA block; unrelated CRON definitions are preserved.
P2K_PHP_CLI="$PHP_BIN" bash "$ROOT/reset-install-mca-cron-v2.10.6.sh" "$ROOT"

trap - ERR INT TERM
echo "SUCCESS: Promote to King v$TARGET_VERSION installed from v$SOURCE_VERSION."
echo "Backup: $BACKUP"
echo "Database migration: additive Core 17 / Analytics 9; no reset or reseed."
echo "MCA Results Auto-Sync: twice-daily scheduler installed when crontab is available."
