Promote to King — Trophy Gallery r5fix2.2 consolidated repair

Fixes:
- duplicated images in public cards;
- public Trophy rows not spanning the full Hall width;
- missing structured information in the Trophy detail modal;
- stale transitive cache chain main index.html -> tool-registry.js -> Trophy runtime;
- removes the competing r5fix1/r5fix2 observer layers rather than stacking another one.

The rich modal now shows League, Competition, Award, Award date, Description,
associated matches when present, and available reference links. It does not
invent the old POC-only player information that does not exist in the persistent
r5 record model.

Install:
  unzip -o PromoteToKing_TrophyGallery_r5fix2.2_CONSOLIDATED_INCREMENTAL_2.11x.zip -d trophy-r5fix2.2
  cd trophy-r5fix2.2
  chmod +x install-trophy-gallery-r5fix2.2.sh
  ./install-trophy-gallery-r5fix2.2.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing

Verify:
  ./install-trophy-gallery-r5fix2.2.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing verify

Then hard-refresh the main P2K page once (Ctrl+F5).
