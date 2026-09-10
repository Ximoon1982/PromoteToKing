Promote to King — Trophy Gallery r5 installer
================================================

Purpose
-------
Installs the persistent Trophy Gallery r5 feature over an existing Promote to
King 2.11.x tree. The immutable payload source is commit:
e883881c083e1490335fff373bdeb8081ecc72cb

The unique r5 browser cache key is:
poc-e883881c083e-20260910-r5

Install or upgrade
------------------
chmod +x PromoteToKing_TrophyGallery_POC_2.11x.run install-trophy-gallery-poc.sh
./install-trophy-gallery-poc.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing

Direct command:
./PromoteToKing_TrophyGallery_POC_2.11x.run /kunden/homepages/43/d141198007/htdocs/PromoteToKing install

The installer accepts the qualified r4 overlay and upgrades it in place. Its
complete hash-verified payload is embedded in the .run file; installation makes
no GitHub or other network request. It preserves the qualified P2K v= identity,
adds a separate immutable r5 token, creates timestamped backups, validates the
installed tree, and automatically rolls back on failure. Reinstallation is
idempotent.

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
