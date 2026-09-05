Promote to King — Trophy Gallery POC overlay installer
======================================================

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
   - only the query-string cache key of assets/js/admin/tool-registry.js is
     changed to a unique POC cache key so browsers cannot reuse the qualified
     pre-POC registry from cache.

No PHP, database, configuration, data/storage, OAuth or CRON files are changed.

Safety
------
- Requires a 2.11.x marker in ui-v2.html.
- Requires the expected v2.11.x admin-registry sentinel.
- Refuses to duplicate a native Trophy Gallery integration.
- Makes a timestamped backup under:
  .p2k-poc-backups/trophy-gallery-<UTC timestamp>/
- Stores the original registry asset URL in:
  .p2k-poc-state/trophy-gallery-overlay.json
  so removal restores the pre-overlay cache URL.
- Rolls back automatically if post-install validation fails.
- Reinstallation is idempotent: the marked loader block and POC cache URL are
  replaced, not duplicated.
- v2 of the installer recognizes the earlier v1 overlay (loader installed but
  no cache-bust state) and upgrades it safely.

Install / upgrade an existing v1 overlay
----------------------------------------
chmod +x PromoteToKing_TrophyGallery_POC_2.11x.run install-trophy-gallery-poc.sh
./install-trophy-gallery-poc.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing

Or directly:
./PromoteToKing_TrophyGallery_POC_2.11x.run /kunden/homepages/43/d141198007/htdocs/PromoteToKing

After installation
------------------
Open/reload ui-v2.html. If the browser still has the document itself cached,
open it once with any harmless document query parameter, for example:

https://www.promotetoking.org/ui-v2.html?ui=v2&page=dashboard&poc=trophy

The document then requests the patched tool-registry.js using the unique POC
cache key.

Remove the test overlay
-----------------------
./PromoteToKing_TrophyGallery_POC_2.11x.run /kunden/homepages/43/d141198007/htdocs/PromoteToKing --remove

Notes
-----
- The POC itself stores edits in browser localStorage; it does not write to the P2K DB.
- The Trophy Hall tab and Trophy Gallery admin panel are mounted only when the
  existing P2K Administration tab has been unlocked for an authenticated admin.
- Seed trophy artwork uses the image URLs already incorporated into the POC.
