#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
OUT="${1:-$ROOT/dist/v2.14.5}"
PKG="PromoteToKing_v2.14.5_INCREMENTAL_FROM_2.13.x"
DIR="$OUT/$PKG"
ZIP="$OUT/$PKG.zip"
TEMPLATE="$ROOT/tools/release/v2145/install-promote-to-king-v2.14.5-universal.sh.in"
CANON="$ROOT/tools/release/v2145/canonical-hash.py"
STAMPER="$ROOT/tools/release/v2145/stamp-runtime-cache-key.py"
EARLIEST="9c2f08e3f59945ae983f30d6b214f10140dbd345"
HEAD="$(git -C "$ROOT" rev-parse HEAD)"
BUILD_ID="v2145-atomic-promotion-rollback"

[[ "$(cat "$ROOT/VERSION")" == "2.14.5" ]] || { echo 'VERSION must be 2.14.5' >&2; exit 1; }
git -C "$ROOT" merge-base --is-ancestor "$EARLIEST" "$HEAD" || { echo "HEAD must descend from qualified v2.13.0 $EARLIEST" >&2; exit 1; }

FILES=(
  .htaccess
  VERSION
  ReleaseControl.php
  PreviewRouter.php
  PublicRouter.php
  ui-v2.html
  trophies/index.html
  RecruitMatch.html
  ChallengeListAssistant.html
  MaxRatingBackfill.php
  api/_common.php
  api/router.php
  server/shared/TaskRegistry.php
  server/team-points/public/session.php
  server/team-points/src/Auth.php
  server/team-points/src/Database.php
  server/team-points/src/PreviewIsolation.php
  server/team-points/src/bootstrap.php
  server/team-points-green/src/GreenConfig.php
  assets/js/admin/admin-shell.js
  assets/js/admin/tool-registry.js
  assets/js/admin/trophy-gallery-admin-v2121.js
  assets/js/admin/trophy-gallery-admin-view-v2121.js
  assets/js/admin/trophy-gallery-engraver-v2121.js
  assets/js/admin/trophy-gallery-poc.js
  assets/js/admin/trophy-gallery-r5fix3.8.js
  assets/trophy-gallery/trophy-gallery-r5fix3.8.css
  assets/trophy-gallery/engraving/editor-v2121.html
  assets/js/pages/recruit-match-v2121-bootstrap.js
  assets/js/pages/recruit-match.js
  assets/js/pages/challenge-list-assistant.js
  assets/js/dashboard/insights-controller.js
  assets/js/pages/dashboard-insights.js
  assets/js/shared/real-oauth.js
  assets/js/shared/team-points-client.js
  assets/js/site-config.js
  assets/js/shared/events-showcase-core.js
  assets/js/events-showcase-line-v2122.js
  server/events-showcase/public/embed.php
  server/events-showcase/public/embed-card.php
  server/team-points/sql/analytics-schema.sql
  server/team-points/public/arenas-insights-export.php
  server/team-points/public/arenas-insights.php
  server/team-points/src/LiveRanksService.php
  server/team-points/src/McaResultsCronService.php
  server/team-points/src/OAuthSession.php
  server/team-points/src/Repository.php
  server/team-points-green/sql/core-schema.sql
  server/team-points-green/src/GreenCompatibility.php
  server/team-points-green/src/GreenRepository.php
  server/team-points-green/public/max-rating-backfill.php
  server/team-points-green/tools/converge-v2.13.1.php
  server/team-points-green/tools/converge-v2.13.2.php
  server/trophy-gallery/src/TrophyGalleryStore.php
  server/release-control/config/.htaccess
  server/release-control/config/config.example.php
  server/release-control/public/preview-oauth-session.php
  server/release-control/src/bootstrap.php
  server/release-control/src/ReleaseControlAuth.php
  server/release-control/src/ReleaseControlState.php
  server/release-control/src/ReleaseSlotPolicy.php
  server/release-control/src/ReleaseSlotFilesystemProbe.php
  server/release-control/src/ReleaseSlotStore.php
  server/release-control/src/ReleaseSlotMaterializer.php
  server/release-control/src/ReleaseCandidatePackage.php
  server/release-control/src/ReleaseStateStore.php
  server/release-control/src/ReleaseRuntimeTree.php
  server/release-control/src/ReleaseDeploymentManager.php
  server/release-control/src/ReleaseCandidateInstaller.php
  server/release-control/src/ReleasePreviewTree.php
  server/release-control/src/ReleasePreviewSession.php
  server/release-control/tools/materialize-current-slot.php
  server/release-control/tools/install-candidate.php
  server/release-control/tools/prepare-preview.php
  server/release-control/tools/verify-slot.php
  server/release-control/tools/release-slot-paths.py
  server/release-control/README.md
)

rm -rf "$OUT"
mkdir -p "$DIR/payload"
for path in "${FILES[@]}"; do
  [[ -f "$ROOT/$path" ]] || { echo "Missing target runtime file: $path" >&2; exit 1; }
  mkdir -p "$DIR/payload/$(dirname "$path")"
  cp -p "$ROOT/$path" "$DIR/payload/$path"
done

CACHE_KEY="$(python3 "$ROOT/tools/release/static_asset_cache_key.py" key --version 2.14.5 --source-head "$HEAD" --build-id "$BUILD_ID")"
python3 "$ROOT/tools/release/static_asset_cache_key.py" stamp --root "$DIR/payload" --version 2.14.5 --source-head "$HEAD" --build-id "$BUILD_ID" >/dev/null
python3 "$STAMPER" "$DIR/payload" "$CACHE_KEY"
python3 "$ROOT/tools/release/static_asset_cache_key.py" verify --root "$DIR/payload" --version 2.14.5 --source-head "$HEAD" --build-id "$BUILD_ID" >/dev/null

grep -Fq "const TROPHY_RUNTIME_KEY = \"$CACHE_KEY\";" "$DIR/payload/assets/js/admin/tool-registry.js"
grep -Fq "assets/js/pages/recruit-match.js?v=$CACHE_KEY" "$DIR/payload/assets/js/pages/recruit-match-v2121-bootstrap.js"
grep -Fq "?v=$CACHE_KEY" "$DIR/payload/ChallengeListAssistant.html"
grep -Fq "assets/js/admin/trophy-gallery-r5fix3.8.js?v=$CACHE_KEY" "$DIR/payload/ui-v2.html"
grep -Fq "assets/trophy-gallery/trophy-gallery-r5fix3.8.css?v=$CACHE_KEY" "$DIR/payload/ui-v2.html"
grep -Fq "../assets/js/admin/trophy-gallery-r5fix3.8.js?v=$CACHE_KEY" "$DIR/payload/trophies/index.html"
grep -Fq "../assets/trophy-gallery/trophy-gallery-r5fix3.8.css?v=$CACHE_KEY" "$DIR/payload/trophies/index.html"
grep -Fq 'data-v2121-preview' "$DIR/payload/assets/js/admin/trophy-gallery-admin-view-v2121.js"
grep -Fq 'Use in gallery' "$DIR/payload/assets/trophy-gallery/engraving/editor-v2121.html"
grep -Fq 'Download image' "$DIR/payload/assets/trophy-gallery/engraving/editor-v2121.html"
grep -Fq "assets/js/shared/events-showcase-core.js?v=$CACHE_KEY" "$DIR/payload/server/events-showcase/public/embed.php"
grep -Fq "assets/js/events-showcase-line-v2122.js?v=$CACHE_KEY" "$DIR/payload/server/events-showcase/public/embed.php"
grep -Fq "assets/js/shared/events-showcase-core.js?v=$CACHE_KEY" "$DIR/payload/server/events-showcase/public/embed-card.php"
grep -Fq 'function teamClubSlug(team)' "$DIR/payload/assets/js/shared/events-showcase-core.js"
grep -Fq 'hydratePromises=new Map()' "$DIR/payload/server/events-showcase/public/embed-card.php"
grep -Fq 'if(!force&&adminDetailDefinition())return' "$DIR/payload/assets/js/admin/admin-shell.js"
grep -Fq 'function loadTrophyAdminV2134()' "$DIR/payload/assets/js/admin/tool-registry.js"
grep -Fq 'function mountPreview(host,record)' "$DIR/payload/assets/js/admin/trophy-gallery-poc.js"
grep -Fq 'value="minimum_matches"' "$DIR/payload/ChallengeListAssistant.html"
grep -Fq 'qualifyingMatchCount < settings.minimumMatchCount' "$DIR/payload/assets/js/pages/challenge-list-assistant.js"
grep -Fq 'title: "Recruitment confidence"' "$DIR/payload/assets/js/admin/tool-registry.js"
grep -Fq 'route: "recruit"' "$DIR/payload/assets/js/admin/tool-registry.js"
grep -Fq 'function standaloneToolHref(route,{classic=false}={})' "$DIR/payload/assets/js/admin/tool-registry.js"
grep -Fq 'classicAdminTab: "management"' "$DIR/payload/assets/js/admin/tool-registry.js"
grep -Fq 'v2.14.5 · Atomic promotion and rollback' "$DIR/payload/ReleaseControl.php"
grep -Fq "'candidate_install' => true" "$DIR/payload/server/release-control/src/ReleaseControlState.php"
grep -Fq "'personal_preview' => true" "$DIR/payload/server/release-control/src/ReleaseControlState.php"
grep -Fq "'promotion'=>\$candidateValid && \$publicValid" "$DIR/payload/server/release-control/src/ReleaseControlState.php"
grep -Fq "'rollback'=>\$mode === 'slots'" "$DIR/payload/server/release-control/src/ReleaseControlState.php"
grep -Fq "'public_slot_routing'=>true" "$DIR/payload/server/release-control/src/ReleaseControlState.php"
grep -Fq "'candidate_cron' => false" "$DIR/payload/server/release-control/src/ReleaseControlState.php"
grep -Fq 'P2KRC_PREVIEW' "$DIR/payload/.htaccess"
grep -Fq 'PublicRouter.php' "$DIR/payload/.htaccess"
grep -Fq 'class ReleaseRuntimeTree' "$DIR/payload/server/release-control/src/ReleaseRuntimeTree.php"
grep -Fq 'class ReleaseDeploymentManager' "$DIR/payload/server/release-control/src/ReleaseDeploymentManager.php"
grep -Fq "promoteCandidate" "$DIR/payload/server/release-control/src/ReleaseStateStore.php"
grep -Fq "rollbackPublic" "$DIR/payload/server/release-control/src/ReleaseStateStore.php"
grep -Fq 'CANDIDATE_PREVIEW_WRITE_BLOCKED' "$DIR/payload/PreviewRouter.php"
grep -Fq 'CANDIDATE_PREVIEW_SIDE_EFFECT_BLOCKED' "$DIR/payload/PreviewRouter.php"
grep -Fq 'P2K_PREVIEW_SANDBOX' "$DIR/payload/server/team-points/src/PreviewIsolation.php"
grep -Fq 'SET SESSION TRANSACTION READ ONLY' "$DIR/payload/server/team-points/src/PreviewIsolation.php"
grep -Fq 'P2KTPPREVIEWSESSID' "$DIR/payload/server/team-points/src/Auth.php"
grep -Fq 'PreviewIsolation::active()' "$DIR/payload/server/shared/TaskRegistry.php"
grep -Fq "read_and_close" "$DIR/payload/server/release-control/src/ReleaseControlAuth.php"
grep -Fq "hardlink_snapshot_isolation" "$DIR/payload/server/release-control/src/ReleaseSlotFilesystemProbe.php"
grep -Fq "'routing_enabled'=>false" "$DIR/payload/server/release-control/src/ReleaseCandidateInstaller.php"
grep -Fq "PHP_SAPI !== 'cli'" "$DIR/payload/server/release-control/tools/install-candidate.php"
grep -Fq "PHP_SAPI !== 'cli'" "$DIR/payload/server/release-control/tools/prepare-preview.php"
grep -Fq 'RECOVERY_PREFIXES = ("server/release-control/",)' "$DIR/payload/server/release-control/tools/release-slot-paths.py"

cp "$CANON" "$DIR/canonical-hash.py"
chmod +x "$DIR/canonical-hash.py"
cp "$TEMPLATE" "$DIR/install-promote-to-king-v2.14.5.sh"
chmod +x "$DIR/install-promote-to-king-v2.14.5.sh"

mapfile -t INSTALLER_FILES < <(
  awk '/^FILES=\($/{inside=1;next} inside&&/^\)$/{exit} inside{gsub(/^[[:space:]]+|[[:space:]]+$/,""); if(length) print}' \
    "$DIR/install-promote-to-king-v2.14.5.sh"
)
if [[ "$(printf '%s\n' "${INSTALLER_FILES[@]}")" != "$(printf '%s\n' "${FILES[@]}")" ]]; then
  echo 'Installer activation FILES list differs from packaged FILES list' >&2
  printf 'Packaged files:\n%s\n' "$(printf '%s\n' "${FILES[@]}")" >&2
  printf 'Installer files:\n%s\n' "$(printf '%s\n' "${INSTALLER_FILES[@]}")" >&2
  exit 1
fi

# Version-specific accepted source lineages. Cache-query values are canonicalized
# separately so production-stamped HTML remains verifiable without weakening drift checks.
REFS_2130=(
  9c2f08e3f59945ae983f30d6b214f10140dbd345
  1c17ca07fdf2b20ac872ff24fb92d87750f6938b
  385faccdbf18725b4487144b8a7cf3e2630ed1b1
)
REFS_2131=(
  c31865b0861445109300fabf9faab62f4a6883f1
  03c476147694220764c0b33448429d14c5e8d6c1
  d2b7468f3f660dd7194d402386ce5d24b69ae349
)
REFS_2132=(
  5672d1370ccd702b8f93fba436dafc7153f52f3a
  82004aaf83b81061c629c6812705c83fb257ce70
  081090f923deb8248158aab9709ce9eb29724428
  4307bc8e7abfac51cc2e2169722876a2eca98e89
  caa99dede51fbfcb59581e3721db78b2d77eb7cc
  011110f413e22d961b9ebba08c98672b42da8166
  3fc8198caca0d6632586faf80aa648f388a033b7
  69515a54b8d00128f72be3943674301fd2b4d210
  cba52fd96ad196cb95321d3a607f99c53f4a2283
)
REFS_2133=(
  a5bc57d25263b5b6006a34760e946cf5b9646a72
  2220bb0fff171f130497936e95077531e1b032bb
  51b1cbac05ce65bd76ad674639868eaee072a83b
  c7d4de40c2ca2538eb337e7e127d71e1e9d3a9d5
  b4c0e5939f4bb72245ee4f0ea0b8b79df374a910
  c2207f7fd60fce3d71780d054201d6aa35291341
  8c23a3515e8f53c2c1de3e7741db00e2d3a607b9
  49cfc7b7d201281d03a24a0c4b29907631732018
  675ef78011d749730236af5da9d75f4069dfb102
  082aab7d5b8b30547fb14bd8e6143aa84f74e105
  f0b11fd53dac013852a1e47c163fc4d2c3659d1c
  fe5feaa9d0cd15fec7a72de704dcd72cdbba4d7a
)
REFS_2134=(
  abc70897db538935459d1c4ae7710433786a10d7
  debfcdfe2e828a5db95ba773d4bbb47ff1deb303
)
REFS_2135=(
  f1549dae110ac95b98e15db7d1350ff1dd0e8088
  a1cafe7167752c3660a90bd941eeaa276ffb03b7
)
REFS_2140=(
  92cc618e02fe6d0eaeefedf67fad887f16902e92
)
REFS_2141=(
  9a46ee7a6f8972071289357d0dc23ccd5eb1fab9
)
REFS_2142=(
  9257577544d8ee7d54a9d23152073f340fe90ed1
)
REFS_2143=(
  970a3142891cc9e1e5ecd95d3401aa7e79571754
  2826f1fb512e5bdc8961c197218bae21339ea234
)
REFS_2144=(
  1faa8a1bbfb7aed188d69119a6a92b2423a88171
)
REFS_2145=(
  "$HEAD"
)

canonical_ref_hash() {
  local ref="$1" path="$2"
  git -C "$ROOT" show "$ref:$path" | python3 "$CANON" --logical-path "$path"
}

emit_baselines() {
  local version="$1" array_name="$2" path ref absent hashes hash
  local -n refs="$array_name"
  for path in "${FILES[@]}"; do
    absent=0
    hashes=""
    for ref in "${refs[@]}"; do
      if git -C "$ROOT" cat-file -e "$ref:$path" 2>/dev/null; then
        hash="$(canonical_ref_hash "$ref" "$path")"
        case ",$hashes," in
          *",$hash,"*) ;;
          *) if [[ -n "$hashes" ]]; then hashes="$hashes,$hash"; else hashes="$hash"; fi ;;
        esac
      else
        absent=1
      fi
    done
    [[ -n "$hashes" || "$absent" == "1" ]] || { echo "No baseline state for $version $path" >&2; exit 1; }
    printf '%s\t%s\t%s\t%s\n' "$version" "$path" "$absent" "$hashes"
  done
}

{
  emit_baselines "2.13.0" REFS_2130
  emit_baselines "2.13.1" REFS_2131
  emit_baselines "2.13.2" REFS_2132
  emit_baselines "2.13.3" REFS_2133
  emit_baselines "2.13.4" REFS_2134
  emit_baselines "2.13.5" REFS_2135
  emit_baselines "2.14.0" REFS_2140
  emit_baselines "2.14.1" REFS_2141
  emit_baselines "2.14.2" REFS_2142
  emit_baselines "2.14.3" REFS_2143
  emit_baselines "2.14.4" REFS_2144
  emit_baselines "2.14.5" REFS_2145
} > "$DIR/BASELINES.tsv"

python3 - "$DIR/install-promote-to-king-v2.14.5.sh" "$HEAD" "$CACHE_KEY" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1])
text=p.read_text()
text=text.replace("@@SOURCE_HEAD@@",sys.argv[2]).replace("@@CACHE_KEY@@",sys.argv[3])
p.write_text(text)
PY

cat > "$DIR/README_INSTALL.txt" <<TXT
Promote to King v2.14.5 cumulative incremental installer
Qualified source HEAD: $HEAD
Static asset cache key: $CACHE_KEY
Supported installed versions: 2.13.0, 2.13.1, 2.13.2, 2.13.3, 2.13.4, 2.13.5, 2.14.0, 2.14.1, 2.14.2, 2.14.3, 2.14.4, 2.14.5.

This package adds controlled atomic promotion and rollback on top of the isolated
candidate preview. The recommended v2.14.5 path is to run
prepare-candidate-preview-v2.14.5.sh on qualified public v2.14.2. It updates only the
stable recovery/public-routing control plane, installs v2.14.5 as an immutable candidate,
prepares preview, and leaves the physical public VERSION/ui-v2 unchanged. PublicRouter
serves the direct-root baseline until the Super Admin explicitly promotes the candidate.

Promotion and rollback verify sealed slots/runtime trees first and then switch one
protected state file atomically. Existing HTTP/curl CRON endpoints follow the same public
release pointer. The cumulative direct-root installer remains recovery/testing only.

Recommended production candidate-preview install from qualified public v2.14.2:
  cd ~/PromoteToKing
  unzip -q $PKG.zip
  cd $PKG
  bash prepare-candidate-preview-v2.14.5.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing ximoon

Direct-root recovery/test install only:
  bash install-promote-to-king-v2.14.5.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing
TXT
printf '%s\n' "$HEAD" > "$DIR/SOURCE_HEAD.txt"
printf '%s\n' "$CACHE_KEY" > "$DIR/ASSET_CACHE_KEY.txt"
(
  cd "$DIR/payload"
  find . -type f -print0 | sort -z | xargs -0 sha256sum
) > "$DIR/PAYLOAD.sha256"

python3 "$ROOT/server/release-control/tools/release-slot-paths.py" --root "$DIR/payload" > "$DIR/CANDIDATE_OVERLAY_PATHS.txt"
: > "$DIR/CANDIDATE_PAYLOAD.sha256"
while IFS= read -r path; do
  [[ -n "$path" ]] || continue
  ( cd "$DIR/payload" && sha256sum "./$path" ) >> "$DIR/CANDIDATE_PAYLOAD.sha256"
done < "$DIR/CANDIDATE_OVERLAY_PATHS.txt"
: > "$DIR/CANDIDATE_REMOVED_PATHS.txt"
CANDIDATE_MANIFEST_SHA="$(sha256sum "$DIR/CANDIDATE_PAYLOAD.sha256" | awk '{print $1}')"
python3 - "$DIR/CANDIDATE_RELEASE.json" "$HEAD" "$CACHE_KEY" "$BUILD_ID" "$CANDIDATE_MANIFEST_SHA" <<'PY'
from pathlib import Path
import json,sys
out,head,cache_key,build_id,manifest_sha=sys.argv[1:]
payload={
  "schema_version":1,
  "version":"2.14.5",
  "source_head":head,
  "cache_key":cache_key,
  "build_id":build_id,
  "qualification_workflow":"P2K v2.14.5 qualification",
  "payload_manifest_sha256":manifest_sha,
  "accepted_public_builds":[
    {"version":"2.14.2","source_head":"9257577544d8ee7d54a9d23152073f340fe90ed1"},
    {"version":"2.14.3","source_head":"2826f1fb512e5bdc8961c197218bae21339ea234"},
    {"version":"2.14.4","source_head":"1faa8a1bbfb7aed188d69119a6a92b2423a88171"}
  ]
}
Path(out).write_text(json.dumps(payload,indent=2)+"\n")
PY
grep -Fxq VERSION "$DIR/CANDIDATE_OVERLAY_PATHS.txt"
grep -Fxq ui-v2.html "$DIR/CANDIDATE_OVERLAY_PATHS.txt"
! grep -Fxq .htaccess "$DIR/CANDIDATE_OVERLAY_PATHS.txt"
! grep -Fxq ReleaseControl.php "$DIR/CANDIDATE_OVERLAY_PATHS.txt"
! grep -Fxq PreviewRouter.php "$DIR/CANDIDATE_OVERLAY_PATHS.txt"
! grep -Fxq PublicRouter.php "$DIR/CANDIDATE_OVERLAY_PATHS.txt"
! grep -Fq 'server/release-control/' "$DIR/CANDIDATE_OVERLAY_PATHS.txt"

cp "$ROOT/tools/release/v2145/prepare-candidate-preview-v2.14.5.sh" "$DIR/prepare-candidate-preview-v2.14.5.sh"
chmod +x "$DIR/prepare-candidate-preview-v2.14.5.sh"

if grep -q '@@' "$DIR/install-promote-to-king-v2.14.5.sh"; then
  echo 'Unresolved installer token' >&2
  exit 1
fi
bash -n "$DIR/install-promote-to-king-v2.14.5.sh"
python3 -m py_compile "$DIR/canonical-hash.py"
rm -rf "$DIR/__pycache__"
if find "$DIR" -type f \( -name '*.pyc' -o -name '*.pyo' \) -print -quit | grep -q .; then
  echo 'Python bytecode leaked into release package' >&2
  exit 1
fi
rm -f "$ZIP"
( cd "$OUT" && zip -X -qr "$PKG.zip" "$PKG" )
unzip -t "$ZIP" >/dev/null
sha256sum "$ZIP"
