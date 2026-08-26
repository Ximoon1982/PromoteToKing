#!/usr/bin/env bash
# Promote to King v2.9.16 - argumentless IONOS CRON installer.
# Safe to run from the production site root. It does not expose tokens.
set -u

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SITE_ROOT=${P2K_SITE_ROOT:-$SCRIPT_DIR}
[[ "$SITE_ROOT" == //* ]] && SITE_ROOT="/${SITE_ROOT#//}"
BASE_URL=${P2K_BASE_URL:-https://www.promotetoking.org}; BASE_URL=${BASE_URL%/}
TP_CONFIG="$SITE_ROOT/server/team-points/config/config.local.php"
SHARED_CONFIG="$SITE_ROOT/data/server-config.json"
PRESERVE_SHARED_CONFIG=${P2K_PRESERVE_SHARED_CONFIG:-0}
DISPATCHER="$SITE_ROOT/cron-dispatch-v2.9.16.sh"

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
BASH_BIN=$(command -v bash || true)
PROBLEM=""
[[ -z "$PHP_BIN" ]] && PROBLEM="No working PHP CLI was found (IONOS normally provides /usr/bin/php8.5-cli)."
[[ -z "$CRONTAB_BIN" ]] && PROBLEM="crontab is unavailable."
[[ -z "$BASH_BIN" ]] && PROBLEM="bash is unavailable."
[[ ! -f "$TP_CONFIG" ]] && PROBLEM="Missing protected Team Points config: $TP_CONFIG"
[[ ! -f "$DISPATCHER" ]] && PROBLEM="Missing dispatcher: $DISPATCHER"

if [[ -n "$PROBLEM" ]]; then
  echo "ERROR: $PROBLEM" >&2
else
  echo "Site root: $SITE_ROOT"
  echo "PHP CLI:  $PHP_BIN"
  "$PHP_BIN" -r 'echo "PHP SAPI: ",PHP_SAPI,PHP_EOL;'

  TOKEN_RC=0
  "$PHP_BIN" -r '
    $tp=$argv[1];$shared=$argv[2];$preserve=((string)($argv[3]??"0"))==="1";
    $c=require $tp;$token=trim((string)($c["app"]["cron_token"]??""));
    if($token===""||str_starts_with($token,"CHANGE_")){fwrite(STDERR,"Team Points cron_token is missing/placeholder.\n");exit(3);}
    $sharedExisted=is_file($shared);$j=$sharedExisted?json_decode((string)file_get_contents($shared),true):[];if(!is_array($j))$j=[];
    $dirty=!$sharedExisted;
    $existing=trim((string)($j["cronToken"]??""));
    $analytics=trim((string)($j["trafficAnalyticsSecret"]??""));
    if($preserve&&$sharedExisted){
      if($existing===""||str_starts_with($existing,"CHANGE_")){fwrite(STDERR,"Existing shared config has no usable cronToken; preservation mode will not rewrite it.\n");exit(7);}
      if($analytics===""){fwrite(STDERR,"Existing shared config has no trafficAnalyticsSecret; preservation mode will not rewrite it.\n");exit(8);}
      echo "Shared CRON token: validated without rewrite.\n";echo "Traffic analytics secret: validated without rewrite.\n";$dirty=false;
    }else{
      if($existing===""||str_starts_with($existing,"CHANGE_")){$j["cronToken"]=$token;$dirty=true;echo "Shared CRON token: repaired from protected Team Points configuration.\n";}else{echo "Shared CRON token: already configured.\n";}
      if(!array_key_exists("schemaVersion",$j)){$j["schemaVersion"]=1;$dirty=true;}
      if($analytics===""){$j["trafficAnalyticsSecret"]=bin2hex(random_bytes(32));$dirty=true;echo "Traffic analytics secret: generated.\n";}else{echo "Traffic analytics secret: already configured.\n";}
    }
    if($dirty){
      $dir=dirname($shared);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)){fwrite(STDERR,"Cannot create shared config directory.\n");exit(4);}
      $tmp=$shared.".tmp.".getmypid();$raw=json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
      if(file_put_contents($tmp,$raw,LOCK_EX)===false){fwrite(STDERR,"Cannot write shared config.\n");exit(5);}chmod($tmp,0600);
      if(!rename($tmp,$shared)){@unlink($tmp);fwrite(STDERR,"Cannot install shared config.\n");exit(6);}chmod($shared,0600);
    }else{echo "Shared server config: unchanged.\n";}
  ' "$TP_CONFIG" "$SHARED_CONFIG" "$PRESERVE_SHARED_CONFIG" || TOKEN_RC=$?

  if [[ "$TOKEN_RC" -ne 0 ]]; then
    echo "ERROR: protected CRON/analytics configuration validation failed (code $TOKEN_RC). Crontab was not modified." >&2
  else
    chmod 755 "$DISPATCHER" 2>/dev/null || true
    OLD_CRON=$($CRONTAB_BIN -l 2>/dev/null || true)
    KEEP=$(printf '%s\n' "$OLD_CRON" | awk '
      BEGIN{skip=0}
      /^# BEGIN PROMOTE TO KING /{skip=1;next}
      /^# END PROMOTE TO KING /{skip=0;next}
      skip==0 && $0 !~ /cron-dispatch-v2\.[0-9]/ {print}
    ')
    TMP=$(mktemp)
    {
      printf '%s\n' "$KEEP"
      printf '%s\n' 'SHELL=/bin/bash' 'PATH=/usr/local/bin:/usr/bin:/bin' '' '# BEGIN PROMOTE TO KING v2.9.16'
      printf '%s\n' "*/5 * * * * P2K_SITE_ROOT=$SITE_ROOT P2K_PHP_CLI=$PHP_BIN $BASH_BIN $DISPATCHER club"
      printf '%s\n' "2-59/10 * * * * P2K_SITE_ROOT=$SITE_ROOT P2K_PHP_CLI=$PHP_BIN $BASH_BIN $DISPATCHER tournaments"
      printf '%s\n' "4-59/10 * * * * P2K_SITE_ROOT=$SITE_ROOT P2K_PHP_CLI=$PHP_BIN $BASH_BIN $DISPATCHER player"
      printf '%s\n' "17 * * * * P2K_SITE_ROOT=$SITE_ROOT P2K_PHP_CLI=$PHP_BIN $BASH_BIN $DISPATCHER match-tracking"
      printf '%s\n' '# END PROMOTE TO KING v2.9.16'
    } > "$TMP"
    if $CRONTAB_BIN "$TMP"; then
      INSTALLED=$($CRONTAB_BIN -l 2>/dev/null || true)
      COUNT=$(printf '%s\n' "$INSTALLED" | grep -c 'cron-dispatch-v2.9.16.sh' || true)
      if [[ "$COUNT" -eq 4 ]]; then
        echo "SUCCESS: v2.9.16 CRON schedule installed (4 tasks)."
        printf '%s\n' "$INSTALLED"
      else
        echo "ERROR: verification found $COUNT v2.9.16 dispatcher entries; restoring previous crontab." >&2
        if [[ -n "$OLD_CRON" ]]; then printf '%s\n' "$OLD_CRON" | $CRONTAB_BIN -; else $CRONTAB_BIN -r 2>/dev/null || true; fi
      fi
    else
      echo "ERROR: crontab installation failed; existing crontab was left/restored." >&2
      if [[ -n "$OLD_CRON" ]]; then printf '%s\n' "$OLD_CRON" | $CRONTAB_BIN -; fi
    fi
    rm -f "$TMP"
  fi
fi
