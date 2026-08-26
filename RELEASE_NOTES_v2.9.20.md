# Promote to King v2.9.20

## Baseline
Built directly from the exact frozen v2.9.19 standalone SHA256 `42074a47ca5bdd3f859199589b5c2aa9b78af7f1c2bafdb1f4621e35fad172c8`, using internal package history only. GitHub is not used.

## MCA OAuth Rate Capture Fix (MORCF)
- Fixes the MCA CSV processing closure in `live-ranks-admin.php` so the requested OAuth rate is captured together with the transport and concurrency settings.
- Prevents the null-to-float failure when authenticated MCA processing calls the shared OAuth batch transport.
- Existing uploaded/staged MCA source data can be processed again; no re-upload is required solely because of this defect.

## Name-transition modal and table pagination
- MIAC/name-transition evidence modals are positioned against the visible parent viewport when Club Intelligence runs inside the Dashboard iframe, including after parent scrolling/resizing.
- Club Intelligence tables use the standard 25-row page size. The Members table is reduced from 50 to 25 rows/page; other `ci-table` tables receive Previous/Next pagination where needed.
- Sorting returns a paginated table to page 1.

## Dashboard Match Board Hydration Fix (DMBHF)
- Registered and ongoing club-match index rows are hydrated from authoritative individual Chess.com `/pub/match/{id}` detail responses through the existing shared cache/OAuth transport.
- Dashboard board totals are computed only from authoritative detail `boards`; game totals are `boards × 2`.
- The Dashboard displays a hydration/loading state instead of a false zero, retains unresolved diagnostics if a detail request fails, and merges details into the same match objects used by the modal.
- Hydration is background traffic and remains subordinate to P0 foreground/interactive survival.

## ACSR Canonical Drain Mode (ACDM)
- When browser-acquisition work is empty/low but authoritative canonical debt remains, ACSR switches to a bounded server-authoritative drain mode.
- Server planning exposes Club vs Player debt/urgency, selects the recommended lane, and proposes adaptive quota, pulse budget and next-delay values.
- The browser chains bounded worker pulses only while they remain productive, adapts quota, and stops a chain on a zero-work pulse.
- ACDM yields immediately when foreground/interactive pressure appears and uses the existing authoritative Worker/queue/coalescing/P0 controls rather than creating a parallel execution path.

## Compatibility
- Core schema: **14** (unchanged)
- Analytics schema: **7** (unchanged)
- Achievement catalogue: **162** (unchanged)
- CRON: **4 operational + 1 weekly backup**, cadences unchanged
- MIAC seed: unchanged
- No database reset, reseed or schema migration
