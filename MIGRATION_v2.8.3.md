# Promote to King v2.8.3 database migration

Source: a working v2.8.x Core/Analytics database pair, including v2.8.2 Hotfix 2.

Target:
- Core schema: **4** (unchanged)
- Analytics schema: **5**

`server/team-points/sql/analytics-migration-v2.8.3.sql` adds nullable provenance columns to `p2k_an_achievement_unlocks`:

- `source_type`
- `source_name`
- `source_url`

Tournament achievement rows are derived/rebuildable Analytics data. The migration deletes only `achievement_key LIKE 'tournament-%'` rows and invalidates the achievement refresh watermark so they are reconstructed with tournament finish dates and provenance.

No Core rows are deleted or rewritten. The Hotfix 2 single-writer/non-blocking refresh coordination remains in force.

For historical tournaments that predate `finishAt`, the tournament service queues those archive rows for its normal incremental refresh. Once Chess.com's `finish_time` has been stored, a subsequent achievement refresh upgrades the medal achievement date to the authoritative tournament finish date.
