# Promote to King v2.12.1

Corrective release over qualified v2.12.0. No database schema change and no runtime-data reset.

## Trophy Hall of Fame

- Corrects Hall routing/state so `hall=trophies` survives reload, back/forward and mobile navigation.
- Computes published-award, league and year metrics from catalogue records instead of rendered text.
- Adds newest/oldest and league A→Z/Z→A ordering with prominent centered group headings.
- Removes the redundant Award Type display and the modal "View bigger" control.
- Keeps two Trophy cards per row on portrait-phone widths.

## Trophy administration and engraver

- Replaces full-catalogue editor loading with a lightweight index plus lazy selected-record detail.
- Adds list search, image URL support, pre-save artwork/engraving selection, and in-place save/upload without remounting the editor.
- Persists linked matches end-to-end and refreshes from the read API after writes.
- Preserves legacy Award data for compatibility even though the editor no longer exposes that field.
- Loads only the selected engraver blank initially and only the newly selected blank after trophy/finish changes; validated engraving geometry is unchanged.

## Match Recruitment

- Adds first-class access under Administration → Competitions, separate from Members → Recruitment.
- Uses a deterministic bootstrap for the v2 recruitment core/controller and fails visibly if binding cannot complete.
- `Load match` is bound immediately; submission enters `Resolving match…` before match resolution starts.
- The existing DB-first eligibility/recruitment algorithm is unchanged.

## Upgrade

The v2.12.1 incremental installer accepts exact supported cumulative v2.11.5 production trees and exact qualified v2.12.0 trees, preserves mutable state and CRON, verifies the final immutable tree, and retains automatic rollback behavior on installation failure.
