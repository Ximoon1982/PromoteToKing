#!/usr/bin/env bash
# Apply only the physical-root standalone files after promoting the matching RC.
set -euo pipefail
ROOT="${1:-/kunden/homepages/43/d141198007/htdocs/PromoteToKing}"
ROOT="$(cd "$ROOT" && pwd -P)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
cd "$HERE"
PHP_CLI="${PHP_CLI:-/usr/bin/php8.5-cli}"
[[ -x "$PHP_CLI" ]] || PHP_CLI="$(command -v php || true)"
[[ -n "$PHP_CLI" && -x "$PHP_CLI" ]] || { echo "PHP CLI unavailable" >&2; exit 1; }
sha256sum -c STANDALONE_PAYLOAD.sha256
EXPECTED="$(cat STANDALONE_RELEASE.txt)"
[[ "$EXPECTED" =~ ^[0-9a-f]{40}$ ]] || exit 1
export P2K_INC5A_EXPECTED="$EXPECTED" P2K_INC5A_ROOT="$ROOT"
"$PHP_CLI" <<'PHP'
<?php
$root=getenv('P2K_INC5A_ROOT');
require $root.'/server/release-control/src/bootstrap.php';
$state=(new \P2K\ReleaseControl\ReleaseStateStore($root))->read();
if(($state['mode']??'')!=='slots')throw new RuntimeException('Promote the matching Release Control candidate first.');
$id=(string)($state['public_release']??'');$manifest=(string)($state['public_manifest_sha256']??'');
if($id===''||$manifest==='')throw new RuntimeException('Incomplete public release selection.');
$runtime=(new \P2K\ReleaseControl\ReleaseRuntimeTree($root))->describeExisting($id,$manifest);
if(empty($runtime['valid'])||($runtime['source_head']??'')!==getenv('P2K_INC5A_EXPECTED'))throw new RuntimeException('Public release HEAD does not match the standalone update. Nothing changed.');
echo "Verified active public release ".$id.PHP_EOL;
PHP
declare -a paths=() changed=()
declare -A oldhash newhash
while read -r h p; do
  [[ "$p" == payload/* ]] || exit 1
  p="${p#payload/}"
  case "$p" in
    TeamFrance/MatchRecruitment/auth.php|TeamFrance/MatchRecruitment/api/index.php|TeamFrance/MatchRecruitment/template.html|TeamFrance/MatchRecruitment/assets/js/recruitment.js|TeamFrance/MatchRecruitment/compat/ActiveOAuthRuntime.php|TGP/MatchRecruitment/auth.php|TGP/MatchRecruitment/api/index.php|TGP/MatchRecruitment/template.html|TGP/MatchRecruitment/assets/js/recruitment.js|TGP/MatchRecruitment/compat/ActiveOAuthRuntime.php|TeamFrance/MatchAnalyzer/auth.php|TeamFrance/MatchAnalyzer/template.html|TeamFrance/MatchAnalyzer/compat/ActiveOAuthRuntime.php) ;;
    *) echo "Unexpected payload path: $p" >&2; exit 1;;
  esac
  [[ -z "${newhash[$p]:-}" ]] || exit 1
  paths+=("$p"); newhash["$p"]="$h"
done < STANDALONE_PAYLOAD.sha256
[[ "${#paths[@]}" -eq 13 ]] || exit 1
while read -r h p; do oldhash["$p"]="$h"; done < STANDALONE_BASELINE.sha256
for p in "${paths[@]}"; do
  dest="$ROOT/$p"
  [[ ! -L "$dest" && "$(realpath -m "$dest")" == "$dest" ]] || { echo "Unsafe target: $dest" >&2; exit 1; }
  before="${oldhash[$p]:-}"
  [[ -n "$before" ]] || exit 1
  if [[ -f "$dest" ]]; then
    actual="$(sha256sum "$dest" | cut -d' ' -f1)"
    [[ "$actual" == "${newhash[$p]}" || ":$before:" == *":$actual:"* ]] || { echo "Local file differs from GitHub baseline: $p. Nothing changed." >&2; exit 1; }
  elif [[ ":$before:" != *":-:"* || -e "$dest" ]]; then
    echo "Expected existing file missing: $p. Nothing changed." >&2; exit 1
  fi
  if [[ "$p" == *.php ]]; then "$PHP_CLI" -l "$HERE/payload/$p"; fi
  if [[ "$p" == *.js ]] && command -v node >/dev/null 2>&1; then node --check "$HERE/payload/$p"; fi
done
BACKUP="$ROOT/storage/p2k-oauth-increment5a-backups/$(date -u +%Y%m%dT%H%M%SZ)-$$"
mkdir -p "$BACKUP"
for p in "${paths[@]}"; do
  if [[ -f "$ROOT/$p" ]]; then mkdir -p "$BACKUP/$(dirname "$p")"; cp -p "$ROOT/$p" "$BACKUP/$p"; fi
done
rollback(){
  rc=$?
  if ((rc!=0)); then
    echo "Installation failed; restoring changed files from $BACKUP" >&2
    for p in "${changed[@]}"; do
      if [[ -f "$BACKUP/$p" ]]; then cp -p "$BACKUP/$p" "$ROOT/$p"; else rm -f "$ROOT/$p"; fi
    done
  fi
  exit "$rc"
}
trap rollback EXIT
for p in "${paths[@]}"; do
  dest="$ROOT/$p"; mkdir -p "$(dirname "$dest")"
  if [[ -f "$dest" && "$(sha256sum "$dest" | cut -d' ' -f1)" == "${newhash[$p]}" ]]; then continue; fi
  temp="$(mktemp "$(dirname "$dest")/.oauth-inc5a.XXXXXX")"
  cp "$HERE/payload/$p" "$temp"
  if [[ -f "$dest" ]]; then chmod --reference="$dest" "$temp"; else chmod 0644 "$temp"; fi
  changed+=("$p"); mv -f "$temp" "$dest"
done
for p in "${paths[@]}"; do
  [[ "$(sha256sum "$ROOT/$p" | cut -d' ' -f1)" == "${newhash[$p]}" ]] || exit 1
done
trap - EXIT
echo "Standalone OAuth increment 5A installed. Backup: $BACKUP"
echo "Membership snapshots, leaderboard data and host-local config were not modified."
