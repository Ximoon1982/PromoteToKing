# Promote to King v2.9.19 migration

v2.9.19 requires **no database schema migration**.

## Database

- Core remains schema **14**.
- Analytics remains schema **7**.
- Existing Core canonical match, board, game, point and member facts are retained.
- Existing MIAC seed and review state are retained; the v2.9.18 seed is not replaced or re-imported destructively.

## Identity-derived refresh

The behavioral migration is generation-driven. MIAC identity-map generation is now included in general Analytics and Achievement source watermarks. A confirmed topology/conflict change therefore makes affected derived projections stale and lets the existing bounded CMDI maintenance path rebuild them from canonical Core/MCA source evidence.

Historical MCA and Daily-board substitution evidence can create confirmed MIAC edges under the strict one-to-one rules documented in the release notes. Evidence is stored before source/ownership replacement; explicit rejections and contradictory known `player_id` values remain blocking conditions.

## CRON

The managed schedule remains five entries: four operational jobs plus the weekly long-life backup. The v2.9.19 installer replaces the managed v2.9.18 script names while retaining the exact cadences and preserving unrelated CRON entries.

No manual SQL, database recreation, or CSV reseed is required.
