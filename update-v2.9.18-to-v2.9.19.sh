#!/usr/bin/env bash
# Promote to King v2.9.18 -> v2.9.19 verified incremental updater.
# Argumentless by design. Run from the production PromoteToKing root.
set -u

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SITE_ROOT=${P2K_SITE_ROOT:-$SCRIPT_DIR}
[[ "$SITE_ROOT" == //* ]] && SITE_ROOT="/${SITE_ROOT#//}"
ARCHIVE_NAME="PromoteToKing_v2.9.18_to_v2.9.19_INCREMENTAL.zip"
ARCHIVE="$SITE_ROOT/$ARCHIVE_NAME"
MANIFEST_NAME="INCREMENTAL_MANIFEST_v2.9.18_to_v2.9.19.json"
STAMP=${P2K_BACKUP_STAMP:-$(date -u +%Y%m%dT%H%M%SZ)}
BACKUP_ROOT="$SITE_ROOT/_backup"
STATE_ARCHIVE="$BACKUP_ROOT/${STAMP}_state-v2.9.18-to-v2.9.19.tar.gz"
RELEASE_ARCHIVE="$BACKUP_ROOT/${STAMP}_release-files-v2.9.18-to-v2.9.19.tar.gz"
STAGE="$SITE_ROOT/.p2k-v2919-stage-$STAMP"
WORK_TMP=$(mktemp -d "${TMPDIR:-/tmp}/p2k-v2919-update.XXXXXX")
ROLLBACK_STAGE="$WORK_TMP/rollback"
ROLLBACK_RESTORE="$WORK_TMP/restore"
APPLY_LIST="$WORK_TMP/apply-files.txt"
REMOVED_LIST="$WORK_TMP/removed-files.txt"
NEW_LIST="$WORK_TMP/new-files.txt"
PROTECTED_HASHES="$WORK_TMP/protected-hashes.txt"
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
TAR_BIN=$(command -v tar || true)
SHA_BIN=$(command -v sha256sum || true)
PROBLEM=""
[[ -z "$PHP_BIN" ]] && PROBLEM="No working PHP CLI was found."
[[ -z "$UNZIP_BIN" ]] && PROBLEM="unzip is unavailable."
[[ -z "$CRONTAB_BIN" ]] && PROBLEM="crontab is unavailable."
[[ -z "$TAR_BIN" ]] && PROBLEM="tar is unavailable."
[[ -z "$SHA_BIN" ]] && PROBLEM="sha256sum is unavailable."
[[ ! -f "$ARCHIVE" ]] && PROBLEM="Incremental archive not found: $ARCHIVE"
[[ ! -f "$SITE_ROOT/VERSION" ]] && PROBLEM="VERSION file is missing from site root."
CURRENT=""; [[ -f "$SITE_ROOT/VERSION" ]] && CURRENT=$(tr -d '\r\n ' < "$SITE_ROOT/VERSION")
if [[ "$CURRENT" == "2.9.19" ]]; then
  echo "Promote to King is already VERSION 2.9.19; no files were changed."
  rm -rf "$WORK_TMP" 2>/dev/null || true
  exit 0
fi
[[ "$CURRENT" != "2.9.18" ]] && PROBLEM="Expected verified production VERSION 2.9.18, found '$CURRENT'."

restore_state_archive() {
  [[ -f "$STATE_ARCHIVE" ]] || return 0
  if [[ -f "$STATE_ARCHIVE.sha256" ]]; then
    (cd "$BACKUP_ROOT" && "$SHA_BIN" -c "$(basename "$STATE_ARCHIVE").sha256") >/dev/null 2>&1 || return 1
  fi
  "$TAR_BIN" -tzf "$STATE_ARCHIVE" >/dev/null 2>&1 || return 1
  "$TAR_BIN" -C "$SITE_ROOT" -xzf "$STATE_ARCHIVE" || return 1
}

rollback_update() {
  echo "ROLLBACK: restoring pre-update release files, mutable state and CRON." >&2
  rm -rf "$ROLLBACK_RESTORE" 2>/dev/null || true; mkdir -p "$ROLLBACK_RESTORE"
  if [[ -f "$RELEASE_ARCHIVE" ]]; then
    if [[ -f "$RELEASE_ARCHIVE.sha256" ]]; then
      (cd "$BACKUP_ROOT" && "$SHA_BIN" -c "$(basename "$RELEASE_ARCHIVE").sha256") >/dev/null 2>&1 || true
    fi
    if "$TAR_BIN" -C "$ROLLBACK_RESTORE" -xzf "$RELEASE_ARCHIVE" 2>/dev/null; then
      if [[ -f "$ROLLBACK_RESTORE/new-files.txt" ]]; then
        while IFS= read -r rel; do [[ -n "$rel" ]] && rm -f -- "$SITE_ROOT/$rel" 2>/dev/null || true; done < "$ROLLBACK_RESTORE/new-files.txt"
      fi
      if [[ -d "$ROLLBACK_RESTORE/files" ]]; then
        (cd "$ROLLBACK_RESTORE/files" && find . -type f -print0) | while IFS= read -r -d '' rel; do
          rel=${rel#./}; mkdir -p "$SITE_ROOT/$(dirname "$rel")"; cp -p "$ROLLBACK_RESTORE/files/$rel" "$SITE_ROOT/$rel" 2>/dev/null || true
        done
      fi
      if [[ -f "$ROLLBACK_RESTORE/crontab-before.txt" ]]; then
        if [[ -s "$ROLLBACK_RESTORE/crontab-before.txt" ]]; then "$CRONTAB_BIN" "$ROLLBACK_RESTORE/crontab-before.txt" 2>/dev/null || true; else "$CRONTAB_BIN" -r 2>/dev/null || true; fi
      fi
    fi
  fi
  restore_state_archive || true
  echo "Compact rollback archives retained under: $BACKUP_ROOT" >&2
}

cleanup() { rm -rf "$STAGE" "$WORK_TMP" 2>/dev/null || true; }
trap cleanup EXIT

if [[ -n "$PROBLEM" ]]; then echo "ERROR: $PROBLEM" >&2; exit 2; fi
rm -rf "$STAGE" 2>/dev/null || true
mkdir -p "$STAGE" "$BACKUP_ROOT" "$ROLLBACK_STAGE/files"
chmod 700 "$BACKUP_ROOT" 2>/dev/null || true

printf '%s\n' "===== P2K v2.9.18 -> v2.9.19 VERIFIED INCREMENTAL UPDATE =====" "Root:    $SITE_ROOT" "Archive: $ARCHIVE" "Backup:  compact archives under $BACKUP_ROOT"

"$UNZIP_BIN" -q "$ARCHIVE" -d "$STAGE" || { echo "ERROR: archive extraction failed before production changes." >&2; exit 3; }
[[ -f "$STAGE/$MANIFEST_NAME" ]] || { echo "ERROR: incremental manifest is missing." >&2; exit 4; }

# Validate payload before touching production. Runtime/private state must never be shipped.
"$PHP_BIN" -r '
  $root=$argv[1];$mf=$argv[2];$m=json_decode((string)file_get_contents($mf),true);
  if(!is_array($m)||($m["from"]??"")!=="2.9.18"||($m["to"]??"")!=="2.9.19"||!is_array($m["files"]??null)||!is_array($m["removed"]??null)){fwrite(STDERR,"Invalid incremental manifest.\n");exit(2);}
  $protected=["server/team-points/config/config.local.php","server/team-points/config/oauth.local.php","data/server-config.json"];
  $safe=function($rel)use($protected){
    if(!is_string($rel)||$rel===""||str_starts_with($rel,"/")||str_contains($rel,"..")){return false;}
    if(str_starts_with($rel,"data/")||$rel==="data"||str_starts_with($rel,"logs/")||$rel==="logs"||in_array($rel,$protected,true)){return false;}
    return true;
  };
  foreach($m["files"] as $rel=>$expected){
    if(!$safe($rel)){fwrite(STDERR,"Unsafe/protected payload path: $rel\n");exit(3);}
    $p=$root."/".$rel;if(!is_file($p)){fwrite(STDERR,"Missing payload: $rel\n");exit(5);}
    $got=hash_file("sha256",$p);if(!hash_equals((string)$expected,(string)$got)){fwrite(STDERR,"Hash mismatch: $rel\n");exit(6);}
  }
  $seen=[]; foreach($m["removed"] as $rel){
    if(!$safe($rel)){fwrite(STDERR,"Unsafe/protected removal path: $rel\n");exit(8);}
    if(isset($m["files"][$rel])||isset($seen[$rel])){fwrite(STDERR,"Conflicting/duplicate removal path: $rel\n");exit(9);}
    $seen[$rel]=true;
    if(is_file($root."/".$rel)){fwrite(STDERR,"Removed path unexpectedly shipped in payload: $rel\n");exit(10);}
  }
  foreach($protected as $rel){if(is_file($root."/".$rel)){fwrite(STDERR,"Protected private file unexpectedly shipped: $rel\n");exit(7);}}
  echo "Payload manifest: OK (".count($m["files"])." files, ".count($m["removed"])." removals).\n";
' "$STAGE" "$STAGE/$MANIFEST_NAME" || exit 5

"$PHP_BIN" -r '$m=json_decode((string)file_get_contents($argv[1]),true);foreach(array_keys($m["files"]??[]) as $p)echo $p,"\n";' "$STAGE/$MANIFEST_NAME" > "$APPLY_LIST"
"$PHP_BIN" -r '$m=json_decode((string)file_get_contents($argv[1]),true);foreach(($m["removed"]??[]) as $p)echo $p,"\n";' "$STAGE/$MANIFEST_NAME" > "$REMOVED_LIST"
: > "$NEW_LIST"; : > "$PROTECTED_HASHES"
for rel in server/team-points/config/config.local.php server/team-points/config/oauth.local.php data/server-config.json; do
  [[ -f "$SITE_ROOT/$rel" ]] && printf '%s  %s\n' "$(sha256sum "$SITE_ROOT/$rel" | awk '{print $1}')" "$rel" >> "$PROTECTED_HASHES"
done

# Compact mutable-state backup: one tar.gz + checksum instead of a recursive data/ copy.
echo "===== COMPACT STATE BACKUP ====="
P2K_BACKUP_STAMP="$STAMP" P2K_BACKUP_RETENTION="${P2K_BACKUP_RETENTION:-3}" "$STAGE/tools/release/p2k-state-backup.sh" "$SITE_ROOT" "state-v2.9.18-to-v2.9.19" || exit 6
[[ -f "$STATE_ARCHIVE" && -f "$STATE_ARCHIVE.sha256" ]] || { echo "ERROR: compact state backup was not created." >&2; exit 6; }

# Release-file rollback is also archived; temporary per-file copies live outside SITE_ROOT.
while IFS= read -r rel; do
  [[ -n "$rel" ]] || continue
  if [[ -f "$SITE_ROOT/$rel" ]]; then mkdir -p "$ROLLBACK_STAGE/files/$(dirname "$rel")"; cp -p "$SITE_ROOT/$rel" "$ROLLBACK_STAGE/files/$rel"; else printf '%s\n' "$rel" >> "$NEW_LIST"; fi
done < "$APPLY_LIST"
# Files intentionally removed by this release are also captured in the compact rollback archive.
while IFS= read -r rel; do
  [[ -n "$rel" ]] || continue
  if [[ -f "$SITE_ROOT/$rel" ]]; then mkdir -p "$ROLLBACK_STAGE/files/$(dirname "$rel")"; cp -p "$SITE_ROOT/$rel" "$ROLLBACK_STAGE/files/$rel"; fi
done < "$REMOVED_LIST"
cp "$NEW_LIST" "$ROLLBACK_STAGE/new-files.txt"
cp "$REMOVED_LIST" "$ROLLBACK_STAGE/removed-files.txt"
cp "$PROTECTED_HASHES" "$ROLLBACK_STAGE/protected-hashes.txt"
"$CRONTAB_BIN" -l > "$ROLLBACK_STAGE/crontab-before.txt" 2>/dev/null || : > "$ROLLBACK_STAGE/crontab-before.txt"
"$TAR_BIN" -C "$ROLLBACK_STAGE" -czf "$RELEASE_ARCHIVE" . || exit 7
chmod 600 "$RELEASE_ARCHIVE" 2>/dev/null || true
(cd "$BACKUP_ROOT" && "$SHA_BIN" "$(basename "$RELEASE_ARCHIVE")") > "$RELEASE_ARCHIVE.sha256.tmp" && mv "$RELEASE_ARCHIVE.sha256.tmp" "$RELEASE_ARCHIVE.sha256"
chmod 600 "$RELEASE_ARCHIVE.sha256" 2>/dev/null || true

# Retain only the newest few release-file rollback archives.
mapfile -t rel_archives < <(find "$BACKUP_ROOT" -maxdepth 1 -type f -name '*_release-files-v2.9.*.tar.gz' -printf '%T@ %p\n' 2>/dev/null | sort -nr | cut -d' ' -f2-)
keep=${P2K_BACKUP_RETENTION:-3}; [[ "$keep" =~ ^[0-9]+$ ]] || keep=3; (( keep < 1 )) && keep=1
for ((i=keep; i<${#rel_archives[@]}; i++)); do old=${rel_archives[$i]}; [[ "$old" == "$RELEASE_ARCHIVE" ]] || rm -f -- "$old" "$old.sha256"; done

echo "===== APPLY VERIFIED DELTA ====="
APPLY_OK=1
while IFS= read -r rel; do
  [[ -n "$rel" ]] || continue
  mkdir -p "$SITE_ROOT/$(dirname "$rel")"
  if ! cp -p "$STAGE/$rel" "$SITE_ROOT/$rel"; then APPLY_OK=0; break; fi
  if [[ "$rel" == *.sh ]]; then chmod 755 "$SITE_ROOT/$rel" 2>/dev/null || true; else chmod 644 "$SITE_ROOT/$rel" 2>/dev/null || true; fi
done < "$APPLY_LIST"
if [[ "$APPLY_OK" -eq 1 ]]; then
  while IFS= read -r rel; do
    [[ -n "$rel" ]] || continue
    rm -f -- "$SITE_ROOT/$rel" || { APPLY_OK=0; break; }
  done < "$REMOVED_LIST"
fi
# Packaging/test caches from older releases are disposable and should not consume host inodes.
if [[ "$APPLY_OK" -eq 1 ]]; then
  find "$SITE_ROOT" -type d -name '__pycache__' -prune -exec rm -rf {} + 2>/dev/null || true
  find "$SITE_ROOT" -type d -name '.pytest_cache' -prune -exec rm -rf {} + 2>/dev/null || true
fi

POST_OK=$APPLY_OK
if [[ "$POST_OK" -eq 1 ]]; then
  "$PHP_BIN" -r '$root=$argv[1];$mf=$argv[2];$m=json_decode((string)file_get_contents($mf),true);foreach($m["files"] as $rel=>$expected){$p=$root."/".$rel;if(!is_file($p)||!hash_equals((string)$expected,(string)hash_file("sha256",$p))){fwrite(STDERR,"Installed hash mismatch: $rel\n");exit(2);}}foreach(($m["removed"]??[]) as $rel){if(is_file($root."/".$rel)){fwrite(STDERR,"Removed file still present: $rel\n");exit(3);}}' "$SITE_ROOT" "$STAGE/$MANIFEST_NAME" || POST_OK=0
fi
[[ "$(tr -d '\r\n ' < "$SITE_ROOT/VERSION" 2>/dev/null)" == "2.9.19" ]] || POST_OK=0
if [[ "$POST_OK" -eq 1 ]]; then
  grep -q 'version: "2.9.19"' "$SITE_ROOT/assets/js/site-config.js" || POST_OK=0
  grep -q '"version": "2.9.19"' "$SITE_ROOT/site-manifest.json" || POST_OK=0
  grep -q 'CORE_SCHEMA_VERSION = 14' "$SITE_ROOT/server/team-points/src/Repository.php" || POST_OK=0
fi

if [[ "$POST_OK" -eq 1 ]]; then
  echo "===== VERIFY CORE 14 / ANALYTICS 7 ====="
  "$PHP_BIN" -r '
    require $argv[1]."/server/team-points/src/bootstrap.php";
    $core=\P2K\TeamPoints\Database::core();$analytics=\P2K\TeamPoints\Database::analytics();$repo=new \P2K\TeamPoints\Repository($core,$analytics);
    if(!$repo->schemaInstalled()||$repo->schemaVersion()<14){if(!$repo->upgradeExistingSchema()){fwrite(STDERR,"Schema recovery failed.\n");exit(2);}}
    if($repo->schemaVersion()<14||$repo->analyticsSchemaVersion()<7){fwrite(STDERR,"Schema validation failed.\n");exit(3);}
    echo "Core schema ".$repo->schemaVersion()." / Analytics ".$repo->analyticsSchemaVersion().".\n";
  ' "$SITE_ROOT" || POST_OK=0
fi

if [[ "$POST_OK" -eq 1 ]]; then
  while IFS= read -r rel; do [[ "$rel" == *.php ]] || continue; "$PHP_BIN" -l "$SITE_ROOT/$rel" >/dev/null || { echo "PHP lint failed: $rel" >&2; POST_OK=0; break; }; done < "$APPLY_LIST"
fi
if [[ "$POST_OK" -eq 1 ]]; then
  for f in "$SITE_ROOT/cron-dispatch-v2.9.19.sh" "$SITE_ROOT/reset-install-cron-v2.9.19.sh" "$SITE_ROOT/install-oauth-v2.9.19.sh" "$SITE_ROOT/install-miac-seed-v2.9.19.sh" "$SITE_ROOT/weekly-backup-v2.9.19.sh" "$SITE_ROOT/tools/release/p2k-state-backup.sh" "$SITE_ROOT/tools/release/p2k-state-restore.sh"; do [[ -f "$f" ]] && bash -n "$f" || { POST_OK=0; break; }; done
fi

# Protected private values must remain byte-identical by hash.
if [[ "$POST_OK" -eq 1 && -s "$PROTECTED_HASHES" ]]; then
  while read -r expected rel; do [[ -f "$SITE_ROOT/$rel" ]] || { echo "Protected file disappeared: $rel" >&2; POST_OK=0; break; }; got=$(sha256sum "$SITE_ROOT/$rel" | awk '{print $1}'); [[ "$got" == "$expected" ]] || { echo "Protected file changed: $rel" >&2; POST_OK=0; break; }; done < "$PROTECTED_HASHES"
fi

if [[ "$POST_OK" -eq 1 ]]; then
  echo "===== INSTALL / VERIFY v2.9.19 CRON ====="
  P2K_SITE_ROOT="$SITE_ROOT" P2K_PHP_CLI="$PHP_BIN" P2K_PRESERVE_SHARED_CONFIG=1 "$SITE_ROOT/reset-install-cron-v2.9.19.sh" || POST_OK=0
fi
if [[ "$POST_OK" -eq 1 && -s "$PROTECTED_HASHES" ]]; then
  while read -r expected rel; do got=$(sha256sum "$SITE_ROOT/$rel" 2>/dev/null | awk '{print $1}'); [[ "$got" == "$expected" ]] || { echo "Protected file changed during CRON install: $rel" >&2; POST_OK=0; break; }; done < "$PROTECTED_HASHES"
fi
if [[ "$POST_OK" -eq 1 ]]; then
  INSTALLED=$($CRONTAB_BIN -l 2>/dev/null || true); COUNT=$(printf '%s\n' "$INSTALLED" | grep -c 'cron-dispatch-v2.9.19.sh' || true); WEEKLY_COUNT=$(printf '%s\n' "$INSTALLED" | grep -c 'weekly-backup-v2.9.19.sh' || true)
  [[ "$COUNT" -eq 4 && "$WEEKLY_COUNT" -eq 1 ]] || { echo "CRON verification found $COUNT operational and $WEEKLY_COUNT weekly v2.9.19 entries; expected 4 + 1." >&2; POST_OK=0; }
fi

if [[ "$POST_OK" -eq 1 && -n "$CURL_BIN" ]]; then
  HTTP=$($CURL_BIN --silent --location --connect-timeout 8 --max-time 20 --output /dev/null --write-out '%{http_code}' "$BASE_URL/" 2>/dev/null || printf '000')
  [[ "$HTTP" =~ ^2[0-9][0-9]$ ]] || { echo "Production root GET returned HTTP $HTTP." >&2; POST_OK=0; }
fi

if [[ "$POST_OK" -eq 1 ]]; then
  LEGACY_DIRS=$(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | wc -l | tr -d ' ')
  echo "SUCCESS: Promote to King v2.9.19 installed from verified v2.9.18."
  echo "Compact rollback state: $STATE_ARCHIVE"
  echo "Compact release rollback: $RELEASE_ARCHIVE"
  echo "Protected OAuth/configuration values (if present) were preserved."
  if (( LEGACY_DIRS > 0 )); then echo "Note: $LEGACY_DIRS legacy directory-style backup(s) remain. Review with: tools/release/p2k-consolidate-legacy-backups.sh"; fi
  exit 0
fi

rollback_update
echo "ERROR: v2.9.19 validation failed; release files, mutable state and CRON were rolled back." >&2
exit 10
