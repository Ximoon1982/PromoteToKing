Promote to King — r5fix3.9 / P2K version normalization to 2.11.5

Purpose
-------
Small increment on top of the approved Trophy Gallery r5fix3.8. It does not
change Trophy behavior. It normalizes the CURRENT P2K release/version surfaces
to semantic version 2.11.5.

What is aligned
---------------
- root VERSION
- window.P2K_SITE_CONFIG.version
- explicitly named current APP/SITE/UI/P2K/RELEASE/BUILD version constants
- manifest/package current-version fields when present
- explicit data-version current-version attributes
- numeric semantic-version ?v= asset cache keys across active runtime source

Cache safety
------------
Static assets are NOT all assigned the bare key "2.11.5". The installer derives
one immutable build identity from the exact active runtime tree it finds before
the update and uses:

    2.11.5-b<12-hex-build-identity>

This preserves the project rule that a qualified build has a unique immutable
static-asset cache key while keeping every active cache-key semantic version
aligned to 2.11.5.

Not rewritten
-------------
Historical comments, migration identifiers, documentation, test fixtures,
archives, backups and versioned module filenames are not current application
version declarations and are intentionally left untouched.

Safety
------
- requires the approved r5fix3.8 Trophy baseline
- never invokes IONOS shell PHP
- immutable package checksum verification
- scans only active runtime roots and root web files
- stages and verifies before commit
- race-checks every source byte before replacement
- byte-for-byte backup of every changed file
- same-filesystem atomic replacement
- automatic rollback on commit failure
- exact post-install SHA-256 verification
- complete re-audit of active current-version surfaces

Install
-------
unzip -o PromoteToKing_TrophyGallery_r5fix3.9_VERSION_2.11.5_INCREMENTAL.zip -d trophy-r5fix3.9
cd trophy-r5fix3.9
chmod +x install-p2k-version-2.11.5.sh
./install-p2k-version-2.11.5.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing

Verify
------
./install-p2k-version-2.11.5.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing verify
