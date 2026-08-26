# Promote to King v2.8.1 Hotfix 2 — database migration

Source: any working v2.8.x Core + Analytics database pair (schema revision 1 or either historical schema-revision-2 branch).
Target: **schema revision 3** in both databases.

The Hotfix 2 convergence migration exists because v2.8.1 and RecoveryFix7 independently used revision 2 for different additive fields. Revision 3 contains both sets.

## Core

`server/team-points/sql/core-migration-v2.8.1-hotfix2.sql` idempotently adds, when missing:

- `daily_rating`, `chess960_rating`, `rating_updated_at` on `p2k_tp_members`;
- `p2k_avg_rating`, `opponent_avg_rating` on `p2k_tp_match_metadata`;
- Core schema revision 3.

## Analytics

`server/team-points/sql/analytics-migration-v2.8.1-hotfix2.sql` idempotently adds, when missing:

- materialized Daily/Chess960 member ratings and update timestamp;
- `last_standard_game_at` and `last_chess960_game_at`;
- match average-rating fields;
- `first_place_count` for Live/MCA aggregates;
- persistent `p2k_an_achievement_unlocks`;
- Analytics schema revision 3.

The repository invalidates the previous Analytics refresh marker/state after an Analytics upgrade so the first post-upgrade read rebuilds the combined projections.

## Supported convergence paths

- original v2.8 schema 1 → combined schema 3;
- v2.8.1/Hotfix1 schema 2 → combined schema 3;
- RecoveryFix7 schema 2 → combined schema 3.

No initializer, seed reload, table replacement, or destructive reset is required. Pre-v2.8 single-database layouts remain outside the supported in-place migration path.

## Invocation

Migration is attempted automatically by Team Points public/admin endpoints and by the Team Points CRON before they reject an older supported v2.8 schema.

## Rollback

The migration is additive/idempotent. A code rollback may leave the extra columns/table in place. Back up both databases before deployment as normal.
