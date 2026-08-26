# Promote to King v2.8.2 database migration

Target: Core schema revision **4** and Analytics schema revision **4**.

The migration is additive and is invoked automatically by the normal Team Points repository upgrade path before public/admin operations reject an older supported v2.8 schema.

## Core

`server/team-points/sql/core-migration-v2.8.2.sql` adds:

- `max_rating` to `p2k_tp_match_metadata`, populated from authoritative Chess.com match settings as match details are refreshed;
- `first_discovered_at`, set at the first club-index observation for newly discovered matches;
- indexes supporting recent-discovery and max-rating analytics.

Existing historical rows cannot be assigned a trustworthy original discovery time, so the migration marks them as pre-upgrade rather than inventing a recent timestamp. This prevents false positives in **New matches · 24 h**. From deployment onward, discovery time is durable and exact.

## Analytics

`server/team-points/sql/analytics-migration-v2.8.2.sql` adds/materializes the matching `max_rating` and `first_discovered_at` dimensions. The normal refresh path repopulates projections from Core.

## Compatibility

The repository first converges earlier v2.8 schema branches through the retained schema-3 migration when necessary, then applies schema 4. No table/database recreation or seed reload is required.
