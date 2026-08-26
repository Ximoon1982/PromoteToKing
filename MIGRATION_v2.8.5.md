# Promote to King v2.8.5 migration

## Database

v2.8.5 is an **in-place additive upgrade**. Do not reset either database and do not reload the historical seed merely for this release.

- Core schema: **5** (from 4)
- Analytics schema: **5** (unchanged)

Core schema 5 adds:

- persistent safe player-profile/avatar snapshot fields on `p2k_tp_members`;
- `p2k_tp_state`, containing the cheap Core generation and roster/club-index observation timestamps.

The Team Points CRON/admin migration path applies `server/team-points/sql/core-migration-v2.8.5.sql` automatically when it first runs against a v2.8.4 Core database. Public read endpoints no longer perform schema DDL; before the first migration they return a controlled schema-not-ready response rather than modifying the database during a page request.

## Existing data

No canonical match, board, game, point-event or tournament data is deleted. Existing avatar fields start empty and are populated on demand through visible Achievement batches, login/profile access, opportunistic Chess.com observations, and normal Player maintenance.

Authoritative finished **0–0 matches** remain stored for traceability but are excluded from all public analytics/graphs/achievement participation metrics.
