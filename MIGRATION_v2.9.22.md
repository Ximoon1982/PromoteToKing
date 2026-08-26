# Promote to King v2.9.22 migration

Upgrade source: frozen v2.9.21.

## Schema

v2.9.22 performs an additive Core schema upgrade from **14 to 15**. Analytics remains **7**.

Core 15 adds isolated Fresh Points Reconstruction staging tables for runs, matches, members, boards and games, plus per-track approval timestamps. Existing canonical Club Points, Player Points, match, board, game, roster and queue data are not reset or re-seeded.

`Repository::upgradeExistingSchema()` applies `server/team-points/sql/core-migration-v2.9.22.sql` automatically during the verified incremental update/schema check.

## Operational behavior

The Club worker now enforces the v2.9.22 canonical-drain policy in code. This is intentional: an existing protected `config.local.php` may still contain the historical 25-item/25-second values. The release does not overwrite that protected file; runtime policy safely raises the Club capacity/time floor instead.

No manual SQL operation is required when using the supplied updater.
