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

No PHP, database, configuration, data/storage, OAuth or CRON files are changed.

Safety
------
- Requires a 2.11.x marker in ui-v2.html.
- Requires the expected v2.11.x admin-registry sentinel.
- Refuses to duplicate a native Trophy Gallery integration.
- Makes a timestamped backup under:
  .p2k-poc-backups/trophy-gallery-<UTC timestamp>/
- Rolls back automatically if post-install validation fails.
- Reinstallation is idempotent: the marked loader block is replaced, not duplicated.

Install
-------
chmod +x PromoteToKing_TrophyGallery_POC_2.11x.run install-trophy-gallery-poc.sh
./install-trophy-gallery-poc.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing

Or directly:
./PromoteToKing_TrophyGallery_POC_2.11x.run /kunden/homepages/43/d141198007/htdocs/PromoteToKing

Remove the test overlay
-----------------------
./PromoteToKing_TrophyGallery_POC_2.11x.run /kunden/homepages/43/d141198007/htdocs/PromoteToKing --remove

Notes
-----
- The POC itself stores edits in browser localStorage; it does not write to the P2K DB.
- The Trophy Hall tab and Trophy Gallery admin panel are mounted only when the
  existing P2K Administration tab has been unlocked for an authenticated admin.
- Seed trophy artwork uses the image URLs already incorporated into the POC.
