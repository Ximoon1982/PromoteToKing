Promote to King — Trophy Gallery r5 installer
================================================

Purpose
-------
Installs the persistent Trophy Gallery r5 feature over an existing Promote to
King 2.11.x tree. The immutable payload source is commit:
a7555ea1e512e99261c4b2ae6451b9496cf89450

The unique r5 browser cache key is:
poc-a7555ea1e512-20260909-r5

Install or upgrade
------------------
chmod +x PromoteToKing_TrophyGallery_POC_2.11x.run install-trophy-gallery-poc.sh
./install-trophy-gallery-poc.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing

Direct command:
./PromoteToKing_TrophyGallery_POC_2.11x.run /kunden/homepages/43/d141198007/htdocs/PromoteToKing install

The installer accepts a current r4 overlay and upgrades it in place. It downloads
only the named r5 files from the immutable commit, preserves the qualified
tool-registry cache identity while appending the r5 token, creates a timestamped
backup, validates the installed tree, and automatically rolls back on failure.
Reinstallation is idempotent.

Data and safety
---------------
Catalog and managed artwork are stored below data/trophy-gallery/. This directory
is not overwritten, backed up as application code, or deleted by removal. Existing
configuration, databases, OAuth/session state, other data/storage and CRON are not
modified. Admin writes use the established P2K admin session and CSRF protection.

Public gallery:
https://www.promotetoking.org/trophies/

Authorized Administration deep link:
https://www.promotetoking.org/ui-v2.html?ui=v2&page=administration&adminCategory=team&trophy=1

Remove code while retaining Trophy data
----------------------------------------
./PromoteToKing_TrophyGallery_POC_2.11x.run /kunden/homepages/43/d141198007/htdocs/PromoteToKing remove

Removal restores the exact pre-r5 managed files and deliberately retains
data/trophy-gallery/. A data purge is never implicit.
