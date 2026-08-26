# Promote to King v2.10.6.14

Corrective Admin-detail integration release based on the canonical v2.10.6.13 source tree.

## Admin detail rendering
- Removes the fixed 610/720 px Admin detail viewport.
- The Admin detail iframe now participates in the existing `p2k-frame-height` contract and grows to its rendered content.
- The detail wrapper is borderless/transparent so embedded tools read as the full Admin content surface rather than a nested framed page.
- Single-detail destinations no longer show a redundant one-item detail tab bar.

## Embedded tool lifecycle
- Admin details now receive `active=1`, `p2k-tool-activity`, and `p2k-admin-ready` using the established integrated-frame lifecycle.
- Restores acquisition for Upcoming Matches Analyzer, Match Creation Analyzer, Challenge Assistant, Open Match Analyzer and other activity-aware embedded tools.
- Admin detail frames are deactivated when returning to the card grid.

## Embedded presentation cleanup
- Club Intelligence hides its standalone 12-tab navigation and duplicate title in Admin mode while retaining Refresh and the selected functional content.
- Scheduled Task Control hides its duplicate internal Scheduled/Green surface tabs when embedded; the parent Admin detail tabs own that navigation.
- Runtime Diagnostics keeps Refresh/Copy actions but removes the duplicate embedded title block.
- Tournament Management removes its standalone introductory card in embedded mode.
- Task Logs removes its duplicate heading in embedded mode.
- Data Reconciliation and Production Migration now report dynamic embedded height.
- Production Migration hides its standalone header only while embedded.
- MCA no longer embeds the whole global Scheduled Task Control as an MCA subtab; MCA Source Data remains the focused MCA administration surface.

## Regression boundary
- Public Dashboard, Hall of Fame and Insights DOM/layout remain unchanged from v2.10.6.13.
- UTC behavior from v2.10.6.13 is preserved.
- No database schema/reset/reseed, CRON, Green/Blue routing, scoring, or image change.
