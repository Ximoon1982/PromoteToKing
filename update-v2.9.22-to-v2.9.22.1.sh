#!/usr/bin/env bash
# Promote to King v2.9.22 -> v2.9.22.1 verified reconstruction-throughput hotfix updater.
# Argumentless by design. Run from the production PromoteToKing root.
set -u

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SITE_ROOT=${P2K_SITE_ROOT:-$SCRIPT_DIR}
[[ "$SITE_ROOT" == //* ]] && SITE_ROOT="/${SITE_ROOT#//}"
ARCHIVE_NAME="PromoteToKing_v2.9.22_to_v2.9.22.1_INCREMENTAL.zip"
ARCHIVE="$SITE_ROOT/$ARCHIVE_NAME"
MANIFEST_NAME="INCREMENTAL_MANIFEST_v2.9.22_to_v2.9.22.1.json"
STAMP=${P2K_BACKUP_STAMP:-$(date -u +%Y%m%dT%H%M%SZ)}
BACKUP_ROOT="$SITE_ROOT/_backup"
STATE_ARCHIVE="$BACKUP_ROOT/${STAMP}_state-v2.9.22-to-v2.9.22.1.tar.gz"
RELEASE_ARCHIVE="$BACKUP_ROOT/${STAMP}_release-files-v2.9.22-to-v2.9.22.1.tar.gz"
STAGE="$SITE_ROOT/.p2k-v29221-stage-$STAMP"
WORK_TMP=$(mktemp -d "${TMPDIR:-/tmp}/p2k-v29221-update.XXXXXX")
ROLLBACK_STAGE="$WORK_TMP/rollback"
ROLLBACK_RESTORE="$WORK_TMP/restore"
APPLY_LIST="$WORK_TMP/apply-files.txt"
NEW_LIST="$WORK_TMP/new-files.txt"
PROTECTED_HASHES="$WORK_TMP/protected-hashes.txt"
BASE_URL=${P2K_BASE_URL:-https://www.promotetoking.org}; BASE_URL=${BASE_URL%/}

find_php_cli(){ local c r; if [[ -n "${P2K_PHP_CLI:-}" ]]; then [[ -x "$P2K_PHP_CLI" ]] && "$P2K_PHP_CLI" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1 && { printf '%s\n' "$P2K_PHP_CLI"; return 0; }; return 1; fi; for c in php8.5 php8.4 php8.3 php8.2 php8.1 php; do r=$(command -v "$c" 2>/dev/null||true); [[ -n "$r" && -x "$r" ]] || continue; "$r" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1 && { printf '%s\n' "$r"; return 0; }; done; return 1; }
PHP_BIN=$(find_php_cli||true); UNZIP_BIN=$(command -v unzip||true); TAR_BIN=$(command -v tar||true); SHA_BIN=$(command -v sha256sum||true); CRONTAB_BIN=$(command -v crontab||true); CURL_BIN=$(command -v curl||true)
PROBLEM=""; [[ -z "$PHP_BIN" ]]&&PROBLEM="No working PHP CLI found."; [[ -z "$UNZIP_BIN" ]]&&PROBLEM="unzip unavailable."; [[ -z "$TAR_BIN" ]]&&PROBLEM="tar unavailable."; [[ -z "$SHA_BIN" ]]&&PROBLEM="sha256sum unavailable."; [[ -z "$CRONTAB_BIN" ]]&&PROBLEM="crontab unavailable."; [[ ! -f "$ARCHIVE" ]]&&PROBLEM="Incremental archive not found: $ARCHIVE"; [[ ! -f "$SITE_ROOT/VERSION" ]]&&PROBLEM="VERSION missing."
CURRENT=""; [[ -f "$SITE_ROOT/VERSION" ]]&&CURRENT=$(tr -d '\r\n ' < "$SITE_ROOT/VERSION")
if [[ "$CURRENT" == "2.9.22.1" ]]; then echo "Promote to King is already VERSION 2.9.22.1; no files were changed."; rm -rf "$WORK_TMP"; exit 0; fi
[[ "$CURRENT" != "2.9.22" ]]&&PROBLEM="Expected verified production VERSION 2.9.22, found '$CURRENT'."
cleanup(){ rm -rf "$STAGE" "$WORK_TMP" 2>/dev/null||true; }; trap cleanup EXIT
[[ -z "$PROBLEM" ]]||{ echo "ERROR: $PROBLEM" >&2; exit 2; }
mkdir -p "$STAGE" "$BACKUP_ROOT" "$ROLLBACK_STAGE/files"; chmod 700 "$BACKUP_ROOT" 2>/dev/null||true

echo "===== P2K v2.9.22 -> v2.9.22.1 VERIFIED HOTFIX ====="
"$UNZIP_BIN" -q "$ARCHIVE" -d "$STAGE" || exit 3
[[ -f "$STAGE/$MANIFEST_NAME" ]]||{ echo "ERROR: incremental manifest missing." >&2; exit 4; }
"$PHP_BIN" -r '
$root=$argv[1];$m=json_decode((string)file_get_contents($argv[2]),true);if(!is_array($m)||($m["from"]??"")!=="2.9.22"||($m["to"]??"")!=="2.9.22.1"||!is_array($m["files"]??null)||!is_array($m["removed"]??null)){fwrite(STDERR,"Invalid manifest.\n");exit(2);} $protected=["server/team-points/config/config.local.php","server/team-points/config/oauth.local.php","data/server-config.json"];
$safe=function($r)use($protected){return is_string($r)&&$r!==""&&!str_starts_with($r,"/")&&!str_contains($r,"..")&&!str_starts_with($r,"data/")&&!str_starts_with($r,"logs/")&&!in_array($r,$protected,true);};foreach($m["files"] as $r=>$h){if(!$safe($r)||!is_file($root."/".$r)||!hash_equals((string)$h,(string)hash_file("sha256",$root."/".$r))){fwrite(STDERR,"Unsafe/missing/hash-mismatch payload: $r\n");exit(3);}}if(count($m["removed"])){fwrite(STDERR,"This hotfix must not remove files.\n");exit(4);} echo "Payload manifest: OK (".count($m["files"])." files).\n";' "$STAGE" "$STAGE/$MANIFEST_NAME" || exit 5
"$PHP_BIN" -r '$m=json_decode((string)file_get_contents($argv[1]),true);foreach(array_keys($m["files"]??[]) as $p)echo $p,"\n";' "$STAGE/$MANIFEST_NAME" > "$APPLY_LIST"
: > "$NEW_LIST"; : > "$PROTECTED_HASHES"
for rel in server/team-points/config/config.local.php server/team-points/config/oauth.local.php data/server-config.json; do [[ -f "$SITE_ROOT/$rel" ]]&&printf '%s  %s\n' "$(sha256sum "$SITE_ROOT/$rel"|awk '{print $1}')" "$rel" >> "$PROTECTED_HASHES"; done

P2K_BACKUP_STAMP="$STAMP" P2K_BACKUP_RETENTION="${P2K_BACKUP_RETENTION:-3}" "$STAGE/tools/release/p2k-state-backup.sh" "$SITE_ROOT" "state-v2.9.22-to-v2.9.22.1" || exit 6
[[ -f "$STATE_ARCHIVE" && -f "$STATE_ARCHIVE.sha256" ]]||{ echo "ERROR: compact state backup missing." >&2; exit 6; }
while IFS= read -r rel; do [[ -n "$rel" ]]||continue; if [[ -f "$SITE_ROOT/$rel" ]]; then mkdir -p "$ROLLBACK_STAGE/files/$(dirname "$rel")"; cp -p "$SITE_ROOT/$rel" "$ROLLBACK_STAGE/files/$rel"; else printf '%s\n' "$rel" >> "$NEW_LIST"; fi; done < "$APPLY_LIST"
cp "$NEW_LIST" "$ROLLBACK_STAGE/new-files.txt"; cp "$PROTECTED_HASHES" "$ROLLBACK_STAGE/protected-hashes.txt"; "$CRONTAB_BIN" -l > "$ROLLBACK_STAGE/crontab-before.txt" 2>/dev/null||: > "$ROLLBACK_STAGE/crontab-before.txt"
"$TAR_BIN" -C "$ROLLBACK_STAGE" -czf "$RELEASE_ARCHIVE" . || exit 7; (cd "$BACKUP_ROOT"&&"$SHA_BIN" "$(basename "$RELEASE_ARCHIVE")") > "$RELEASE_ARCHIVE.sha256"

rollback(){ echo "ROLLBACK: restoring v2.9.22 release files." >&2; rm -rf "$ROLLBACK_RESTORE"; mkdir -p "$ROLLBACK_RESTORE"; "$TAR_BIN" -C "$ROLLBACK_RESTORE" -xzf "$RELEASE_ARCHIVE" 2>/dev/null||true; [[ -f "$ROLLBACK_RESTORE/new-files.txt" ]]&&while IFS= read -r rel; do [[ -n "$rel" ]]&&rm -f "$SITE_ROOT/$rel"; done < "$ROLLBACK_RESTORE/new-files.txt"; if [[ -d "$ROLLBACK_RESTORE/files" ]]; then (cd "$ROLLBACK_RESTORE/files"&&find . -type f -print0)|while IFS= read -r -d '' rel; do rel=${rel#./}; mkdir -p "$SITE_ROOT/$(dirname "$rel")"; cp -p "$ROLLBACK_RESTORE/files/$rel" "$SITE_ROOT/$rel"||true; done; fi; }

APPLY_OK=1
while IFS= read -r rel; do [[ -n "$rel" ]]||continue; mkdir -p "$SITE_ROOT/$(dirname "$rel")"; cp -p "$STAGE/$rel" "$SITE_ROOT/$rel"||{ APPLY_OK=0;break; }; [[ "$rel" == *.sh ]]&&chmod 755 "$SITE_ROOT/$rel" 2>/dev/null||chmod 644 "$SITE_ROOT/$rel" 2>/dev/null||true; done < "$APPLY_LIST"
find "$SITE_ROOT" -type d -name '__pycache__' -prune -exec rm -rf {} + 2>/dev/null||true; find "$SITE_ROOT" -type d -name '.pytest_cache' -prune -exec rm -rf {} + 2>/dev/null||true
POST_OK=$APPLY_OK
[[ "$(tr -d '\r\n ' < "$SITE_ROOT/VERSION" 2>/dev/null)" == "2.9.22.1" ]]||POST_OK=0
grep -q 'version: "2.9.22.1"' "$SITE_ROOT/assets/js/site-config.js"||POST_OK=0; grep -q '"version": "2.9.22.1"' "$SITE_ROOT/site-manifest.json"||POST_OK=0
if [[ "$POST_OK" -eq 1 ]]; then "$PHP_BIN" -r '$m=json_decode((string)file_get_contents($argv[2]),true);foreach($m["files"] as $r=>$h){$p=$argv[1]."/".$r;if(!is_file($p)||!hash_equals((string)$h,(string)hash_file("sha256",$p))){fwrite(STDERR,"Installed hash mismatch: $r\n");exit(2);}}' "$SITE_ROOT" "$STAGE/$MANIFEST_NAME"||POST_OK=0; fi
if [[ "$POST_OK" -eq 1 ]]; then grep -q 'CORE_SCHEMA_VERSION = 15;' "$SITE_ROOT/server/team-points/src/Repository.php" || POST_OK=0; grep -q 'ANALYTICS_SCHEMA_VERSION = 7;' "$SITE_ROOT/server/team-points/src/Repository.php" || POST_OK=0; fi
if [[ "$POST_OK" -eq 1 ]]; then node_bin=$(command -v node||true); [[ -n "$node_bin" ]]&&for f in assets/js/pages/fresh-points-reconstruction.js assets/js/pages/task-control.js assets/js/site-config.js; do "$node_bin" --check "$SITE_ROOT/$f" >/dev/null||POST_OK=0; done; fi
if [[ "$POST_OK" -eq 1 && -s "$PROTECTED_HASHES" ]]; then while read -r expected rel; do got=$(sha256sum "$SITE_ROOT/$rel" 2>/dev/null|awk '{print $1}'); [[ "$got" == "$expected" ]]||{ echo "Protected file changed: $rel" >&2;POST_OK=0;break;}; done < "$PROTECTED_HASHES"; fi
# Hotfix deliberately keeps v2.9.22 CRON jobs; verify rather than reinstall.
if [[ "$POST_OK" -eq 1 ]]; then installed=$($CRONTAB_BIN -l 2>/dev/null||true); op=$(printf '%s\n' "$installed"|grep -c 'cron-dispatch-v2.9.22.sh'||true); weekly=$(printf '%s\n' "$installed"|grep -c 'weekly-backup-v2.9.22.sh'||true); [[ "$op" -eq 4 && "$weekly" -eq 1 ]]||{ echo "CRON verification found $op operational + $weekly weekly v2.9.22 jobs; expected 4 + 1." >&2;POST_OK=0;}; fi
if [[ "$POST_OK" -eq 1 && -n "$CURL_BIN" ]]; then http=$($CURL_BIN --silent --location --connect-timeout 8 --max-time 20 --output /dev/null --write-out '%{http_code}' "$BASE_URL/" 2>/dev/null||printf 000); [[ "$http" =~ ^2[0-9][0-9]$ ]]||POST_OK=0; fi
if [[ "$POST_OK" -eq 1 ]]; then echo "SUCCESS: Promote to King v2.9.22.1 reconstruction-throughput hotfix installed."; echo "Existing v2.9.22 CRON contract retained unchanged."; echo "Rollback: $RELEASE_ARCHIVE"; exit 0; fi
rollback; echo "ERROR: v2.9.22.1 validation failed; release files were rolled back." >&2; exit 10
