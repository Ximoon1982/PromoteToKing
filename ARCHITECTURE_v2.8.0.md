# Promote to King v2.8.0 storage architecture

## Purpose

v2.8.0 is a clean-break storage release designed to keep Promote to King comfortably below IONOS MariaDB quotas for the next several years and to prevent a disposable cache or failed bulk import from locking the durable data store.

The application uses three independent storage domains:

1. **Core MariaDB** — canonical facts and current worker state.
2. **Analytics MariaDB** — rebuildable projections and analytical products.
3. **Protected filesystem** — compressed Chess.com API cache, application logs, temporary runtime state, and optional archives.

Administrator page admission remains independent of all three storage domains. A database failure can make a data-backed panel unavailable, but it must not lock an administrator out of pages that do not require that database.

## Core database

The Core database is authoritative. It contains compact normalized facts:

- members, with integer `member_id`;
- opponents and aliases;
- one canonical row per match;
- one canonical row per member/match board;
- up to two compact game rows per board;
- Team Points worker jobs and queue items;
- CRON leases and current task-control state;
- audit/repair state with bounded retention;
- a tiny initialization audit record.

High-volume rows use integer foreign keys rather than repeating usernames and long URLs. Compatibility SQL views expose the old logical surfaces used by retained application code, but those views do not duplicate the data.

The Core database deliberately does **not** contain:

- Chess.com HTTP response bodies;
- seed-import copies;
- bulk raw JSON archives;
- permanent application logs.

## Analytics database

Analytics is a projection database. Every Team Points analytical row can be rebuilt from Core without reacquiring Chess.com history.

It contains:

- player lifetime totals;
- player monthly facts;
- match analytical facts;
- club totals;
- durable daily Insights facts;
- opponent aggregates;
- Live Rank tables;
- storage history and projection state;
- Analytics initialization and refresh state.

If Analytics is damaged or lost, Core remains the source of truth. Rebuilding Analytics does not require rescanning global Chess.com match IDs or every member's complete match history.

To avoid repeatedly scanning a growing historical Core archive, analytical projections are refreshed on demand at a bounded cadence (`analytics_refresh_interval_seconds`, default six hours) rather than after every five-minute collection invocation.

## Filesystem cache

`server/shared/FilesystemCache.php` stores Chess.com API responses as sharded gzip files. Default location:

`data/runtime-v280/cache/chesscom/`

Properties:

- SHA-256 URL identity;
- gzip compression;
- atomic temporary-file + rename writes;
- TTL stored with each response;
- expired-entry deletion;
- least-recent-file eviction when the configured byte cap is exceeded;
- default hard cache budget: 512 MiB;
- `.htaccess` denial and blank indexes in runtime directories.

The cache is disposable. Deleting it may increase Chess.com API traffic temporarily, but does not delete durable P2K facts.

## No SQL seed staging

The fresh initializer does not upload a second full copy of the snapshot into SQL staging tables.

It:

1. verifies the six embedded source files locally;
2. verifies that both configured databases are empty;
3. creates the Core and Analytics schemas;
4. streams idempotent normalized member/match/board/game batches directly into Core;
5. validates exact Core counts and Club Points;
6. builds Analytics from Core;
7. stores only small initialization audit rows;
8. creates a paused incremental catch-up job.

The initializer never creates `p2k_tp_seed_*`, `p2k_tp_http_cache`, or `p2k_shared_http_cache` tables.

## Storage housekeeping

The Team Points CRON periodically invokes bounded housekeeping. Default retention:

- completed/skipped job items: 14 days;
- failed job items: 30 days;
- worker/process/task logs: 30 days;
- consistency-repair history: 180 days;
- filesystem API cache: TTL + hard byte cap;
- filesystem logs: 30 days.

No routine task removes Core match/game facts.

## Capacity monitoring

The Team Points administration page has **Storage & capacity**.

For Core and Analytics it queries `information_schema.tables` and compares actual data+index bytes with the configured database quota. Each DB is:

- green below 80%;
- red at or above 80%;
- unknown if that DB cannot be reached.

It also measures:

- filesystem cache;
- runtime logs;
- archive directory;
- other v2.8 runtime data.

One sample per UTC day is retained in Analytics. The graph uses the last measurement in each calendar month. Once two calendar months exist, capacity projections use linear growth of the monthly series to estimate dates at 80% and 100%. During the first month only, daily samples provide an explicitly labelled bootstrap estimate.

The storage monitor itself is failure-isolated: inability to inspect one database is reported for that database instead of making the whole administrator page inaccessible.
