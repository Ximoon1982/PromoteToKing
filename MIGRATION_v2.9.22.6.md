# Migration to v2.9.22.6

v2.9.22.6 upgrades Core schema 15 to Core schema 16. Analytics remains schema 7.

The migration is additive only:

- creates `p2k_tp_reconstruction_actions`;
- adds indexes for run/scope/entity/action-history lookup;
- records Core schema version 16.

Existing members, matches, boards, games, queues, reconstruction staging data, analytics data, OAuth configuration and CRON state are not reset.

The incremental shell updater does not require PHP CLI. After files are deployed, the normal PHP-FCGI Team Points/control bootstrap automatically invokes the existing Repository schema-upgrade path and applies `server/team-points/sql/core-migration-v2.9.22.6.sql` when it first observes Core 15.
