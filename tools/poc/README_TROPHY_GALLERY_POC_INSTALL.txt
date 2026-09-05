Promote to King — Trophy Gallery POC overlay installer r3
=========================================================

Purpose
-------
Installs the admin-only Trophy Gallery proof of concept on top of an existing
Promote to King 2.11.x tree without replacing the full application.

Runtime source
--------------
feature/trophy-gallery-poc runtime commit:
aed6ad0fcebd95e0dd176f3eeb33e9d30b714505

The installer retrieves trophy-gallery-poc.js from that exact immutable GitHub
commit over HTTPS. It does not track a moving branch.

Files changed in the target P2K tree
------------------------------------
1. assets/js/admin/tool-registry.js
   - a marked Trophy Gallery loader block is inserted before the existing
     v2.11.x registry return.
2. assets/js/admin/trophy-gallery-poc.js
   - the POC runtime payload.
3. ui-v2.html
   - only the assets/js/admin/tool-registry.js URL is adjusted for the test
     overlay.
   - the existing qualified v= fingerprint is preserved byte-for-byte.
   - a separate p2k_trophy_poc=poc-aed6ad0fcebd-20260905-r3 query token is
     appended so browsers cannot reuse the pre-overlay cached registry.

Example on qualified v2.11.3
----------------------------
Before:
assets/js/admin/tool-registry.js?v=p2k-2.11.3-dcd71c8e76c0-f2065dcf0f2da32e

During POC test:
assets/js/admin/tool-registry.js?v=p2k-2.11.3-dcd71c8e76c0-f2065dcf0f2da32e&p2k_trophy_poc=poc-aed6ad0fcebd-20260905-r3

Removal returns the URL to the original qualified form.

No PHP, database, configuration, data/storage, OAuth or CRON files are changed.

Safety
------
- Requires a 2.11.x marker in ui-v2.html.
- Requires the expected v2.11.x admin-registry sentinel.
- Refuses to duplicate a native Trophy Gallery integration referenced outside
  the overlay marker.
- Makes a timestamped backup under:
  .p2k-poc-backups/trophy-gallery-<UTC timestamp>/
- Stores overlay restoration state under:
  .p2k-poc-state/trophy-gallery-overlay.json
- Rolls back automatically if post-install validation fails.
- Reinstallation is idempotent: one loader block and one POC cache token only.
- r3 recognizes and upgrades the earlier v1 loader-only overlay.
- r3 also recognizes the short-lived r2 overlay that replaced v= and restores
  the original qualified fingerprint before appending the separate POC token.

Qualification
-------------
The branch smoke gate executes the installer against production-shaped 2.11.3
fixtures. It proves:
- clean install;
- immediate reinstall / idempotency;
- remove / exact restoration of ui-v2.html and tool-registry.js;
- upgrade from the v1 loader-only overlay;
- upgrade from the r2 replaced-v cache form;
- preservation of the qualified v2.11.3 registry fingerprint;
- JavaScript and shell syntax;
- isolation from release/v2.11.4.

Install / upgrade an earlier overlay
------------------------------------
chmod +x PromoteToKing_TrophyGallery_POC_2.11x.run install-trophy-gallery-poc.sh
./install-trophy-gallery-poc.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing

Or directly:
./PromoteToKing_TrophyGallery_POC_2.11x.run /kunden/homepages/43/d141198007/htdocs/PromoteToKing

After installation
------------------
Reload ui-v2.html. The modified document requests tool-registry.js with the
additional POC cache token, so a previously cached qualified registry is not
reused.

If the HTML document itself is still cached unusually aggressively, open once:
https://www.promotetoking.org/ui-v2.html?ui=v2&page=dashboard&poc=trophy-r3

Remove the test overlay
-----------------------
./PromoteToKing_TrophyGallery_POC_2.11x.run /kunden/homepages/43/d141198007/htdocs/PromoteToKing --remove

Notes
-----
- The POC stores edits in browser localStorage; it does not write to the P2K DB.
- The Trophy Hall tab and Trophy Gallery admin panel mount only when the existing
  P2K Administration tab is unlocked for an authenticated admin.
- Seed trophy artwork uses the image URLs already incorporated into the POC.
