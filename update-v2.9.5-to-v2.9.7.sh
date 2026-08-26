#!/usr/bin/env bash
# Promote to King v2.9.5 -> v2.9.7 verified incremental updater.
# Argumentless by design. Run from the production PromoteToKing root.
set -u

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SITE_ROOT=${P2K_SITE_ROOT:-$SCRIPT_DIR}
[[ "$SITE_ROOT" == //* ]] && SITE_ROOT="/${SITE_ROOT#//}"
ARCHIVE_NAME="PromoteToKing_v2.9.5_to_v2.9.7_INCREMENTAL.zip"
ARCHIVE="$SITE_ROOT/$ARCHIVE_NAME"
MANIFEST_NAME="INCREMENTAL_MANIFEST_v2.9.5_to_v2.9.7.json"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP="$SITE_ROOT/_backup/${STAMP}_v2.9.5_to_v2.9.7"
STAGE="$SITE_ROOT/.p2k-v297-stage-$STAMP"
BASE_URL=${P2K_BASE_URL:-https://www.promotetoking.org}; BASE_URL=${BASE_URL%/}

find_php_cli() {
  local c resolved
  if [[ -n "${P2K_PHP_CLI:-}" ]]; then
    if [[ -x "$P2K_PHP_CLI" ]] && "$P2K_PHP_CLI" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1; then printf '%s\n' "$P2K_PHP_CLI"; return 0; fi
    return 1
  fi
  for c in /usr/bin/php8.5-cli /usr/bin/php8.4-cli /usr/bin/php8.3-cli /usr/bin/php8.2-cli /usr/bin/php8.1-cli /usr/bin/php8.0-cli /usr/bin/php8.5 /usr/bin/php8.4 /usr/bin/php8.3 /usr/bin/php8.2 /usr/bin/php8.1 /usr/bin/php8.0 php8.5-cli php8.4-cli php8.3-cli php8.2-cli php8.1-cli php8.0-cli php8.5 php8.4 php8.3 php8.2 php8.1 php8.0 php; do
    resolved="$c"; [[ "$c" != */* ]] && resolved=$(command -v "$c" 2>/dev/null || true)
    [[ -n "$resolved" && -x "$resolved" ]] || continue
    if "$resolved" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1; then printf '%s\n' "$resolved"; return 0; fi
  done
  return 1
}

PHP_BIN=$(find_php_cli || true)
UNZIP_BIN=$(command -v unzip || true)
CRONTAB_BIN=$(command -v crontab || true)
CURL_BIN=$(command -v curl || true)
PROBLEM=""
[[ -z "$PHP_BIN" ]] && PROBLEM="No working PHP CLI was found."
[[ -z "$UNZIP_BIN" ]] && PROBLEM="unzip is unavailable."
[[ -z "$CRONTAB_BIN" ]] && PROBLEM="crontab is unavailable."
[[ ! -f "$ARCHIVE" ]] && PROBLEM="Incremental archive not found: $ARCHIVE"
[[ ! -f "$SITE_ROOT/VERSION" ]] && PROBLEM="VERSION file is missing from site root."
CURRENT=""; [[ -f "$SITE_ROOT/VERSION" ]] && CURRENT=$(tr -d '\r\n ' < "$SITE_ROOT/VERSION")
if [[ "$CURRENT" == "2.9.7" ]]; then
  echo "Promote to King is already VERSION 2.9.7; no files were changed."
  exit 0
fi
[[ "$CURRENT" != "2.9.5" ]] && PROBLEM="Expected verified production VERSION 2.9.5, found '$CURRENT'."

rollback_update() {
  echo "ROLLBACK: restoring pre-update files and CRON state." >&2
  if [[ -f "$BACKUP/new-files.txt" ]]; then
    while IFS= read -r rel; do [[ -n "$rel" ]] && rm -f -- "$SITE_ROOT/$rel" 2>/dev/null || true; done < "$BACKUP/new-files.txt"
  fi
  if [[ -d "$BACKUP/files-before" ]]; then
    (cd "$BACKUP/files-before" && find . -type f -print0) | while IFS= read -r -d '' rel; do
      rel=${rel#./}; mkdir -p "$SITE_ROOT/$(dirname "$rel")"; cp -p "$BACKUP/files-before/$rel" "$SITE_ROOT/$rel" 2>/dev/null || true
    done
  fi
  if [[ -f "$BACKUP/crontab-before.txt" ]]; then
    if [[ -s "$BACKUP/crontab-before.txt" ]]; then "$CRONTAB_BIN" "$BACKUP/crontab-before.txt" 2>/dev/null || true; else "$CRONTAB_BIN" -r 2>/dev/null || true; fi
  fi
  echo "Rollback/backup retained at: $BACKUP" >&2
}

if [[ -n "$PROBLEM" ]]; then
  echo "ERROR: $PROBLEM" >&2
  exit 2
fi

trap 'rm -rf "$STAGE" 2>/dev/null || true' EXIT
rm -rf "$STAGE" 2>/dev/null || true
mkdir -p "$STAGE" "$SITE_ROOT/_backup" "$BACKUP/files-before"
chmod 700 "$SITE_ROOT/_backup" "$BACKUP" 2>/dev/null || true

echo "===== P2K v2.9.5 -> v2.9.7 VERIFIED INCREMENTAL UPDATE ====="
echo "Root:    $SITE_ROOT"
echo "Archive: $ARCHIVE"
echo "Backup:  $BACKUP"

"$UNZIP_BIN" -q "$ARCHIVE" -d "$STAGE" || { echo "ERROR: archive extraction failed before production changes." >&2; exit 3; }
[[ -f "$STAGE/$MANIFEST_NAME" ]] || { echo "ERROR: incremental manifest is missing." >&2; exit 4; }

# Validate the entire extracted payload and reject protected/runtime paths before
# backing up or touching production.
"$PHP_BIN" -r '
  $root=$argv[1];$mf=$argv[2];$m=json_decode((string)file_get_contents($mf),true);
  if(!is_array($m)||($m["from"]??"")!=="2.9.5"||($m["to"]??"")!=="2.9.7"||!is_array($m["files"]??null)){fwrite(STDERR,"Invalid incremental manifest.\n");exit(2);}
  $protected=["server/team-points/config/config.local.php","server/team-points/config/oauth.local.php","data/server-config.json"];
  foreach($m["files"] as $rel=>$expected){
    if(!is_string($rel)||$rel===""||str_starts_with($rel,"/")||str_contains($rel,"..")){fwrite(STDERR,"Unsafe manifest path.\n");exit(3);}
    if(str_starts_with($rel,"data/")||$rel==="data"||str_starts_with($rel,"logs/")||$rel==="logs"||in_array($rel,$protected,true)){fwrite(STDERR,"Protected path in payload: $rel\n");exit(4);}
    $p=$root."/".$rel;if(!is_file($p)){fwrite(STDERR,"Missing payload: $rel\n");exit(5);}
    $got=hash_file("sha256",$p);if(!hash_equals((string)$expected,(string)$got)){fwrite(STDERR,"Hash mismatch: $rel\n");exit(6);}
  }
  foreach($protected as $rel){if(is_file($root."/".$rel)){fwrite(STDERR,"Protected private file unexpectedly shipped: $rel\n");exit(7);}}
  echo "Payload manifest: OK (".count($m["files"])." files).\n";
' "$STAGE" "$STAGE/$MANIFEST_NAME" || exit 5

echo "===== BACKUP CONFIGURATION / DATA / LOGS / CRONTAB ====="
# User-controlled and runtime state is backed up wholesale, but never replaced by
# this incremental payload.
if [[ -d "$SITE_ROOT/config" ]]; then cp -a "$SITE_ROOT/config" "$BACKUP/config"; fi
if [[ -d "$SITE_ROOT/server/team-points/config" ]]; then mkdir -p "$BACKUP/server/team-points"; cp -a "$SITE_ROOT/server/team-points/config" "$BACKUP/server/team-points/config"; fi
if [[ -d "$SITE_ROOT/data" ]]; then cp -a "$SITE_ROOT/data" "$BACKUP/data"; fi
for d in logs server/logs server/team-points/logs; do
  if [[ -d "$SITE_ROOT/$d" ]]; then mkdir -p "$BACKUP/$(dirname "$d")"; cp -a "$SITE_ROOT/$d" "$BACKUP/$d"; fi
done
"$CRONTAB_BIN" -l > "$BACKUP/crontab-before.txt" 2>/dev/null || : > "$BACKUP/crontab-before.txt"
chmod -R go-rwx "$BACKUP" 2>/dev/null || true

APPLY_LIST="$BACKUP/apply-files.txt"
"$PHP_BIN" -r '$m=json_decode((string)file_get_contents($argv[1]),true);foreach(array_keys($m["files"]??[]) as $p)echo $p,"\n";' "$STAGE/$MANIFEST_NAME" > "$APPLY_LIST"
: > "$BACKUP/new-files.txt"
while IFS= read -r rel; do
  [[ -n "$rel" ]] || continue
  if [[ -f "$SITE_ROOT/$rel" ]]; then
    mkdir -p "$BACKUP/files-before/$(dirname "$rel")"; cp -p "$SITE_ROOT/$rel" "$BACKUP/files-before/$rel"
  else
    printf '%s\n' "$rel" >> "$BACKUP/new-files.txt"
  fi
done < "$APPLY_LIST"

echo "===== APPLY VERIFIED DELTA ====="
APPLY_OK=1
while IFS= read -r rel; do
  [[ -n "$rel" ]] || continue
  mkdir -p "$SITE_ROOT/$(dirname "$rel")"
  if ! cp -p "$STAGE/$rel" "$SITE_ROOT/$rel"; then APPLY_OK=0; break; fi
  # Only files delivered by this release receive normalized permissions. Existing
  # configs/data/logs are never recursively chmodded.
  if [[ "$rel" == *.sh ]]; then chmod 755 "$SITE_ROOT/$rel" 2>/dev/null || true; else chmod 644 "$SITE_ROOT/$rel" 2>/dev/null || true; fi
done < "$APPLY_LIST"

POST_OK=$APPLY_OK
if [[ "$POST_OK" -eq 1 ]]; then
  "$PHP_BIN" -r '
    $root=$argv[1];$mf=$argv[2];$m=json_decode((string)file_get_contents($mf),true);
    foreach($m["files"] as $rel=>$expected){$p=$root."/".$rel;if(!is_file($p)||!hash_equals((string)$expected,(string)hash_file("sha256",$p))){fwrite(STDERR,"Installed hash mismatch: $rel\n");exit(2);}}
  ' "$SITE_ROOT" "$STAGE/$MANIFEST_NAME" || POST_OK=0
fi
[[ "$(tr -d '\r\n ' < "$SITE_ROOT/VERSION" 2>/dev/null)" == "2.9.7" ]] || POST_OK=0
if [[ "$POST_OK" -eq 1 ]]; then
  grep -q 'version: "2.9.7"' "$SITE_ROOT/assets/js/site-config.js" || POST_OK=0
  grep -q '"version": "2.9.7"' "$SITE_ROOT/site-manifest.json" || POST_OK=0
  grep -q 'CORE_SCHEMA_VERSION = 10' "$SITE_ROOT/server/team-points/src/Repository.php" || POST_OK=0
fi

if [[ "$POST_OK" -eq 1 ]]; then
  while IFS= read -r rel; do
    [[ "$rel" == *.php ]] || continue
    "$PHP_BIN" -l "$SITE_ROOT/$rel" >/dev/null || { echo "PHP lint failed: $rel" >&2; POST_OK=0; break; }
  done < "$APPLY_LIST"
fi
if [[ "$POST_OK" -eq 1 ]]; then
  for f in "$SITE_ROOT/cron-dispatch-v2.9.7.sh" "$SITE_ROOT/reset-install-cron-v2.9.7.sh" "$SITE_ROOT/install-oauth-poc-v2.9.7.sh"; do
    [[ -f "$f" ]] && bash -n "$f" || { POST_OK=0; break; }
  done
fi

# Private host values must still exist byte-for-byte whenever they existed in backup.
if [[ "$POST_OK" -eq 1 ]]; then
  for rel in server/team-points/config/config.local.php server/team-points/config/oauth.local.php data/server-config.json; do
    before="$BACKUP/$rel"; after="$SITE_ROOT/$rel"
    if [[ -f "$before" ]]; then
      [[ -f "$after" ]] || { echo "Protected file disappeared: $rel" >&2; POST_OK=0; break; }
      cmp -s "$before" "$after" || { echo "Protected file changed: $rel" >&2; POST_OK=0; break; }
    fi
  done
fi

if [[ "$POST_OK" -eq 1 ]]; then
  echo "===== INSTALL / VERIFY v2.9.7 CRON ====="
  P2K_SITE_ROOT="$SITE_ROOT" P2K_PHP_CLI="$PHP_BIN" "$SITE_ROOT/reset-install-cron-v2.9.7.sh" || POST_OK=0
fi
if [[ "$POST_OK" -eq 1 ]]; then
  INSTALLED=$($CRONTAB_BIN -l 2>/dev/null || true)
  COUNT=$(printf '%s\n' "$INSTALLED" | grep -c 'cron-dispatch-v2.9.7.sh' || true)
  [[ "$COUNT" -eq 4 ]] || { echo "CRON verification found $COUNT v2.9.7 entries, expected 4." >&2; POST_OK=0; }
fi

# GET only: some P2K endpoints intentionally do not support HEAD.
if [[ "$POST_OK" -eq 1 && -n "$CURL_BIN" ]]; then
  HTTP=$($CURL_BIN --silent --location --connect-timeout 8 --max-time 20 --output /dev/null --write-out '%{http_code}' "$BASE_URL/" 2>/dev/null || printf '000')
  [[ "$HTTP" =~ ^2[0-9][0-9]$ ]] || { echo "Production root GET returned HTTP $HTTP." >&2; POST_OK=0; }
fi

if [[ "$POST_OK" -eq 1 ]]; then
  echo "SUCCESS: Promote to King v2.9.7 installed from verified v2.9.5."
  echo "Backup: $BACKUP"
  echo "OAuth POC private config (if present) was preserved."
  exit 0
fi

rollback_update
echo "ERROR: v2.9.7 validation failed; changed release files and CRON state were rolled back." >&2
exit 10
