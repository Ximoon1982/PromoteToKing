# Promote to King v2.8.1 Hotfix 2 — RecoveryFix7 integrated

This package reconciles the v2.8.1 Hotfix1 baseline with the later RecoveryFix7 work.
The public/application version remains **2.8.1**; the combined Core/Analytics database
schema advances to **revision 3** because the two source branches had independently
used revision 2 for different additive changes.

## Integrated RecoveryFix7 work

- Opportunistic storage of Daily and Chess960 member ratings through the shared Chess.com gateway.
- Materialized last finished Standard and Chess960 game timestamps per member.
- Match Recruitment Assistant RM-2 using the stored category-specific rating pool.
- RM-2 excludes already registered, unrated, out-of-range, below-lowest-opponent, and opponent-club members,
  then recruits the strongest eligible members first until the significant-advantage target is reached.
- Team Insights: best-fit Y scales after zoom, green Started / red Finished / gold In progress,
  rolling-future accentuation, and Low/Medium/High current-year Club Points projections.
- 18 already-shipped achievement image/miniature pairs are wired to their catalogue entries.

## v2.8.1 features retained

- Live tracked-lineup history finishing at the current lineup, real UTC axis, hover, drag zoom and keyboard navigation.
- Integrated Administration Live ranks, Storage & Capacity, and Open Match Analyzer.
- Public Chess.com club API as the primary administrator source with configured fallback.
- Tournament medal styling, enlarged/fixed Insights visualizations, opponent intelligence,
  unified player-profile improvements, and persistent achievement unlock history.

## Responsive tab unification

- Non-dashboard tabs now share the dashboard mobile content width and outer gutter.
- Embedded Team Insights, Hall of Fame content and Administration tools cannot widen the parent page.
- Wide tables retain their desktop columns but scroll inside their own table viewport on small screens.
- Nested Hall/Insights/Administration tab bars stay compact and scroll horizontally instead of wrapping into oversized blocks.
- Mobile cards, toolbars and filters use reduced spacing while desktop dimensions remain unchanged.

## Database migration

Existing **v2.8.x Core + Analytics** installations upgrade in place to schema revision 3.
The new convergence migration is idempotent and covers both historical schema-2 variants:

- a v2.8.1 / Hotfix1 database gets the RecoveryFix7 rating/activity fields;
- a RecoveryFix7 database gets the v2.8.1 match-rating, MCA first-place, and achievement-unlock fields;
- an original v2.8 schema-revision-1 database gets both sets.

No fresh initializer or seed reload is required for an existing v2.8.x database.
Pre-v2.8 single-database installations remain outside the supported migration path.
