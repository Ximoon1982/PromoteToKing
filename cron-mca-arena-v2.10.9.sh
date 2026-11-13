#!/usr/bin/env bash
# Physical IONOS entry point: always follow the currently promoted Release Control slot.
# This file needs one-time installation in the stable physical root; later promotions
# and rollbacks are picked up on each invocation without changing the CRON schedule.
set -euo pipefail
ROOT="${P2K_MCA_PHYSICAL_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)}"
choose_php(){ local c r; for c in "${P2K_PHP_CLI:-}" /usr/bin/php8.5-cli /usr/bin/php8.5 /usr/bin/php8.4-cli /usr/bin/php8.4 /usr/bin/php8.3-cli /usr/bin/php8.3 /usr/bin/php8.2-cli /usr/bin/php8.2 /usr/bin/php8.1-cli php8.5-cli php8.5 php8.4-cli php8.4 php8.3-cli php8.3 php8.2-cli php8.2 php8.1-cli php8.1 php; do [[ -n "$c" ]]||continue;r="$c";[[ "$c" == */* ]]||r="$(command -v "$c" 2>/dev/null||true)";[[ -n "$r"&&-x "$r" ]]||continue;if "$r" -r 'exit(PHP_SAPI === "cli" && PHP_VERSION_ID >= 80100 ? 0 : 1);' >/dev/null 2>&1;then printf '%s\n' "$r";return 0;fi;done;return 1; }
PHP_BIN="$(choose_php||true)"
[[ -n "$PHP_BIN" ]]||{ echo 'No compatible PHP CLI found.' >&2;exit 1; }
# Only read immutable runtime/slot state. Never dispatch into a candidate/preview.
TARGET="$("$PHP_BIN" -r '
  $root=$argv[1];
  $storeFile=$root."/server/release-control/src/ReleaseStateStore.php";
  $physical=$root."/server/team-points/bin/mca-results-sync.php";
  try {
    if (!is_file($storeFile)) { echo $physical; exit(0); }
    require_once $storeFile;
    $store=new P2K\ReleaseControl\ReleaseStateStore($root);
    $state=$store->read();
    $mode=(string)($state["mode"]??"direct-root");
    if ($mode==="direct-root") { echo $physical; exit(0); }
    if ($mode!=="slots") throw new RuntimeException("unsupported serving mode");
    $id=(string)($state["public_release"]??"");
    if (!preg_match("~^\\d+\\.\\d+\\.\\d+-[a-f0-9]{12}$~D",$id))
      throw new RuntimeException("invalid public release ID");
    $directory=dirname($store->path())."/runtime-trees/".$id;
    $metaPath=$directory."/meta/runtime.json";
    $meta=is_file($metaPath)?json_decode((string)file_get_contents($metaPath),true):null;
    if (!is_array($meta)||($meta["release_id"]??"")!==$id||
        ($meta["slot_manifest_sha256"]??"")!==($state["public_manifest_sha256"]??null))
      throw new RuntimeException("public runtime metadata does not match promoted release");
    $target=$directory."/app/server/team-points/bin/mca-results-sync.php";
    if (!is_file($target)) throw new RuntimeException("public MCA worker is missing");
    echo $target;
  } catch (Throwable $e) {
    fwrite(STDERR,"MCA CRON dispatch blocked: ".$e->getMessage()."\n");
    exit(2);
  }
' "$ROOT")" || exit 2
[[ -n "$TARGET" && -f "$TARGET" ]] || { echo 'MCA CRON dispatch blocked: worker path missing.' >&2;exit 2; }
if [[ "${1:-}" == '--print-target' ]]; then printf 'MCA active worker: %s\n' "$TARGET";exit 0;fi
exec "$PHP_BIN" "$TARGET" "${1:-55}"
