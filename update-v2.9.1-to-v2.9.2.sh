#!/usr/bin/env bash
# Promote to King v2.9.1 -> v2.9.2 argumentless incremental updater.
# Run from the production site root after uploading the incremental ZIP beside it.
set -u

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SITE_ROOT=${P2K_SITE_ROOT:-$SCRIPT_DIR}
[[ "$SITE_ROOT" == //* ]] && SITE_ROOT="/${SITE_ROOT#//}"
ARCHIVE_NAME="PromoteToKing_v2.9.1_to_v2.9.2_INCREMENTAL.zip"
ARCHIVE="$SITE_ROOT/$ARCHIVE_NAME"
MANIFEST_NAME="INCREMENTAL_MANIFEST_v2.9.1_to_v2.9.2.json"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP="$SITE_ROOT/_backup/${STAMP}_v2.9.1_to_v2.9.2"
STAGE="$SITE_ROOT/.p2k-v292-stage-$STAMP"
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
CRONTAB_BIN=$(command -v crontab || true)
CURL_BIN=$(command -v curl || true)
UNZIP_BIN=$(command -v unzip || true)
PROBLEM=""
[[ -z "$PHP_BIN" ]] && PROBLEM="No working PHP CLI was found."
[[ -z "$CRONTAB_BIN" ]] && PROBLEM="crontab is unavailable."
[[ -z "$UNZIP_BIN" ]] && PROBLEM="unzip is unavailable."
[[ ! -f "$ARCHIVE" ]] && PROBLEM="Incremental archive not found: $ARCHIVE"
[[ ! -f "$SITE_ROOT/VERSION" ]] && PROBLEM="VERSION file is missing from site root."
CURRENT=""; [[ -f "$SITE_ROOT/VERSION" ]] && CURRENT=$(tr -d '\r\n ' < "$SITE_ROOT/VERSION")
[[ "$CURRENT" != "2.9.1" && "$CURRENT" != "2.9.2" ]] && PROBLEM="Expected production VERSION 2.9.1 (or already-updated 2.9.2), found '$CURRENT'."

rollback_update() {
  echo "ROLLBACK: restoring pre-update files and CRON."
  if [[ -f "$BACKUP/new-files.txt" ]]; then
    while IFS= read -r rel; do [[ -n "$rel" ]] && rm -f -- "$SITE_ROOT/$rel" 2>/dev/null || true; done < "$BACKUP/new-files.txt"
  fi
  if [[ -d "$BACKUP/files-before" ]]; then
    (cd "$BACKUP/files-before" && find . -type f -print0) | while IFS= read -r -d '' rel; do
      rel=${rel#./}; mkdir -p "$SITE_ROOT/$(dirname "$rel")"; cp -p "$BACKUP/files-before/$rel" "$SITE_ROOT/$rel" 2>/dev/null || true
    done
  fi
  if [[ -f "$BACKUP/data/server-config.json" ]]; then mkdir -p "$SITE_ROOT/data"; cp -p "$BACKUP/data/server-config.json" "$SITE_ROOT/data/server-config.json" 2>/dev/null || true; elif [[ -f "$SITE_ROOT/data/server-config.json" && -f "$BACKUP/server-config-was-absent" ]]; then rm -f "$SITE_ROOT/data/server-config.json"; fi
  if [[ -f "$BACKUP/crontab-before.txt" ]]; then
    if [[ -s "$BACKUP/crontab-before.txt" ]]; then "$CRONTAB_BIN" "$BACKUP/crontab-before.txt" 2>/dev/null || true; else "$CRONTAB_BIN" -r 2>/dev/null || true; fi
  fi
  echo "Rollback files are retained at: $BACKUP"
}

if [[ -n "$PROBLEM" ]]; then
  echo "ERROR: $PROBLEM" >&2
else
  echo "===== P2K v2.9.1 -> v2.9.2 INCREMENTAL UPDATE ====="
  echo "Root:     $SITE_ROOT"
  echo "Archive:  $ARCHIVE"
  echo "PHP CLI:  $PHP_BIN"
  echo "Backup:   $BACKUP"

  rm -rf "$STAGE" 2>/dev/null || true
  mkdir -p "$STAGE" "$BACKUP/files-before" "$SITE_ROOT/_backup"
  chmod 700 "$SITE_ROOT/_backup" "$BACKUP" 2>/dev/null || true

  EXTRACT_OK=0
  "$UNZIP_BIN" -q "$ARCHIVE" -d "$STAGE" && EXTRACT_OK=1
  if [[ "$EXTRACT_OK" -ne 1 || ! -f "$STAGE/$MANIFEST_NAME" ]]; then
    echo "ERROR: archive extraction/manifest validation failed before production files were changed." >&2
  else
    VERIFY_RC=0
    "$PHP_BIN" -r '
      $root=$argv[1];$mf=$argv[2];$m=json_decode((string)file_get_contents($mf),true);
      if(!is_array($m)||($m["from"]??"")!=="2.9.1"||($m["to"]??"")!=="2.9.2"||!is_array($m["files"]??null)){fwrite(STDERR,"Invalid incremental manifest.\n");exit(2);}
      foreach($m["files"] as $rel=>$expected){
        if(!is_string($rel)||$rel===""||str_starts_with($rel,"/")||str_contains($rel,"..")){fwrite(STDERR,"Unsafe manifest path.\n");exit(3);}
        $p=$root."/".$rel;if(!is_file($p)){fwrite(STDERR,"Missing payload: $rel\n");exit(4);} $got=hash_file("sha256",$p);
        if(!hash_equals((string)$expected,(string)$got)){fwrite(STDERR,"Hash mismatch: $rel\n");exit(5);}
      }
      echo "Payload manifest: OK (".count($m["files"])." files).\n";
    ' "$STAGE" "$STAGE/$MANIFEST_NAME" || VERIFY_RC=$?

    if [[ "$VERIFY_RC" -ne 0 ]]; then
      echo "ERROR: incremental payload validation failed before production files were changed." >&2
    else
      echo "===== BACKUP CONFIGURATION / DATA / LOGS / CRONTAB ====="
      if [[ -d "$SITE_ROOT/config" ]]; then cp -a "$SITE_ROOT/config" "$BACKUP/config"; fi
      if [[ -d "$SITE_ROOT/server/team-points/config" ]]; then mkdir -p "$BACKUP/server/team-points"; cp -a "$SITE_ROOT/server/team-points/config" "$BACKUP/server/team-points/config"; fi
      if [[ -d "$SITE_ROOT/data" ]]; then cp -a "$SITE_ROOT/data" "$BACKUP/data"; else touch "$BACKUP/server-config-was-absent"; fi
      for d in logs server/logs server/team-points/logs; do if [[ -d "$SITE_ROOT/$d" ]]; then mkdir -p "$BACKUP/$(dirname "$d")"; cp -a "$SITE_ROOT/$d" "$BACKUP/$d"; fi; done
      "$CRONTAB_BIN" -l > "$BACKUP/crontab-before.txt" 2>/dev/null || : > "$BACKUP/crontab-before.txt"
      chmod -R go-rwx "$BACKUP" 2>/dev/null || true

      APPLY_LIST="$BACKUP/apply-files.txt"
      "$PHP_BIN" -r '$m=json_decode((string)file_get_contents($argv[1]),true);foreach(array_keys($m["files"]??[]) as $p)echo $p,"\n";' "$STAGE/$MANIFEST_NAME" > "$APPLY_LIST"
      : > "$BACKUP/new-files.txt"
      while IFS= read -r rel; do
        [[ -n "$rel" ]] || continue
        if [[ -f "$SITE_ROOT/$rel" ]]; then mkdir -p "$BACKUP/files-before/$(dirname "$rel")"; cp -p "$SITE_ROOT/$rel" "$BACKUP/files-before/$rel"; else printf '%s\n' "$rel" >> "$BACKUP/new-files.txt"; fi
      done < "$APPLY_LIST"

      echo "===== APPLY VERIFIED PAYLOAD ====="
      APPLY_OK=1
      while IFS= read -r rel; do
        [[ -n "$rel" ]] || continue
        mkdir -p "$SITE_ROOT/$(dirname "$rel")"
        if ! cp -p "$STAGE/$rel" "$SITE_ROOT/$rel"; then echo "Copy failed: $rel" >&2; APPLY_OK=0; break; fi
      done < "$APPLY_LIST"

      if [[ "$APPLY_OK" -eq 1 ]]; then
        find "$SITE_ROOT" -path "$SITE_ROOT/_backup" -prune -o -type d -exec chmod 755 {} + 2>/dev/null || true
        find "$SITE_ROOT" -path "$SITE_ROOT/_backup" -prune -o -type f -exec chmod 644 {} + 2>/dev/null || true
        find "$SITE_ROOT" -path "$SITE_ROOT/_backup" -prune -o -type f -name '*.sh' -exec chmod 755 {} + 2>/dev/null || true
        find "$SITE_ROOT" -path "$SITE_ROOT/_backup" -prune -o -type f \( -name '.env' -o -name '*.local.php' \) -exec chmod 600 {} + 2>/dev/null || true
        [[ -f "$SITE_ROOT/data/server-config.json" ]] && chmod 600 "$SITE_ROOT/data/server-config.json" 2>/dev/null || true
        chmod 700 "$SITE_ROOT/_backup" "$BACKUP" 2>/dev/null || true
      fi

      POST_OK=$APPLY_OK
      if [[ "$POST_OK" -eq 1 && "$(tr -d '\r\n ' < "$SITE_ROOT/VERSION" 2>/dev/null)" != "2.9.2" ]]; then echo "ERROR: VERSION validation failed." >&2; POST_OK=0; fi
      if [[ "$POST_OK" -eq 1 ]]; then
        grep -q 'rated_board_count' "$SITE_ROOT/server/team-points/sql/core-migration-v2.9.2.sql" || POST_OK=0
        grep -q 'rated_board_count' "$SITE_ROOT/server/team-points/sql/analytics-migration-v2.9.2.sql" || POST_OK=0
      fi
      if [[ "$POST_OK" -eq 1 ]]; then bash -n "$SITE_ROOT/cron-dispatch-v2.9.2.sh" && bash -n "$SITE_ROOT/reset-install-cron-v2.9.2.sh" || POST_OK=0; fi
      if [[ "$POST_OK" -eq 1 ]]; then
        for f in \
          "$SITE_ROOT/api/_common.php" \
          "$SITE_ROOT/server/control/public/api.php" \
          "$SITE_ROOT/server/shared/SharedChessGateway.php" \
          "$SITE_ROOT/server/team-points/src/AchievementCatalog.php" \
          "$SITE_ROOT/server/team-points/src/AnalyticsBuilder.php" \
          "$SITE_ROOT/server/team-points/src/ClubIntelligenceService.php" \
          "$SITE_ROOT/server/team-points/src/Repository.php"; do
          "$PHP_BIN" -l "$f" >/dev/null || POST_OK=0
        done
      fi
      if [[ "$POST_OK" -eq 1 && -n "$CURL_BIN" ]]; then
        HTTP=$($CURL_BIN --silent --location --connect-timeout 8 --max-time 20 --output /dev/null --write-out '%{http_code}' "$BASE_URL/" 2>/dev/null || printf '000')
        if [[ ! "$HTTP" =~ ^2[0-9][0-9]$ ]]; then echo "ERROR: production root HTTP check returned $HTTP." >&2; POST_OK=0; else echo "Root HTTP check: $HTTP"; fi
      elif [[ "$POST_OK" -eq 1 ]]; then echo "HTTP check skipped: curl unavailable."; fi

      if [[ "$POST_OK" -eq 1 ]]; then
        echo "===== INSTALL / VERIFY v2.9.2 CRON ====="
        P2K_SITE_ROOT="$SITE_ROOT" P2K_PHP_CLI="$PHP_BIN" "$SITE_ROOT/reset-install-cron-v2.9.2.sh"
        INSTALLED=$($CRONTAB_BIN -l 2>/dev/null || true)
        COUNT=$(printf '%s\n' "$INSTALLED" | grep -c 'cron-dispatch-v2.9.2.sh' || true)
        [[ "$COUNT" -eq 4 ]] || { echo "ERROR: v2.9.2 CRON verification found $COUNT entries." >&2; POST_OK=0; }
      fi

      if [[ "$POST_OK" -eq 1 ]]; then
        echo "SUCCESS: Promote to King v2.9.2 installed."
        echo "Backup: $BACKUP"
      else
        rollback_update
        echo "ERROR: v2.9.2 update failed validation and was rolled back where feasible." >&2
      fi
    fi
  fi
  rm -rf "$STAGE" 2>/dev/null || true
fi
