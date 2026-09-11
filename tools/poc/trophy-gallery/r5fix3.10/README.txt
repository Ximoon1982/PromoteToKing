Promote to King — Trophy Gallery r5fix3.10

Purpose
-------
Small containment correction on top of the approved r5fix3.9 / P2K 2.11.5
state. It fixes the Administration → Trophy Gallery managed artwork previews
when an image escapes its preview box and renders over the page.

Behavior
--------
- keeps the approved r5fix3.8 Trophy runtime and artwork behavior unchanged
- adds one immutable r5fix3.10 JavaScript companion
- scopes the correction only to the native Trophy Gallery administration host
- hard-bounds each managed preview to 280 px and clips its contents
- forces preview images to absolute 100% x 100% contain rendering
- reapplies containment after editor rerenders/uploads through MutationObserver
- uses inline !important containment as a safety net against late/stale/host CSS
- does not change Trophy data, PHP/API/store behavior, OAuth/session or CRON

Install
-------
unzip -o PromoteToKing_TrophyGallery_r5fix3.10_INCREMENTAL.zip -d trophy-r5fix3.10
cd trophy-r5fix3.10
chmod +x install-trophy-gallery-r5fix3.10.sh
./install-trophy-gallery-r5fix3.10.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing

Verify
------
./install-trophy-gallery-r5fix3.10.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing verify
