# Promote to King v2.8.8 migration

v2.8.8 is an in-place upgrade from v2.8.7.

- Core schema moves from **5 to 6**.
- Analytics remains schema **5**.
- **No database reset or re-seed is required.**
- The Core migration adds only the long-lived Chess.com opponent-club profile cache fields `icon_url`, `icon_checked_at`, and `profile_updated_at` to `p2k_tp_opponents`.
- The normal Team Points schema upgrader applies `server/team-points/sql/core-migration-v2.8.8.sql` automatically when it sees Core schema 5.
- Preserve production `server/team-points/config/config.local.php`, `data/server-config.json`, generated runtime data, tournament archives and match-tracking data.
- Historical snapshots, anomaly scan state and endpoint/ACAMR telemetry are protected runtime filesystem data, not canonical database facts.

The opponent icon cache follows the same demand-driven principle as member avatars: stale cached icons can be displayed immediately and refresh is bounded/long-lived. No bulk Chess.com opponent-logo crawler is introduced.
