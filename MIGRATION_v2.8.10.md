# Promote to King v2.8.10 migration

## Database

Core upgrades additively from schema **6 → 7**. Analytics remains schema **5**. Do **not** reset or reseed either database.

The first authenticated Team Points maintenance/worker execution applies `server/team-points/sql/core-migration-v2.8.10.sql`. It repairs historical finished non-void match result/competition-point fields from authoritative stored team scores, preserves authoritative finished 0–0 rows as void, and recreates the canonical match-summary view with score-derived outcomes. The Analytics logic watermark then causes the normal materialized rebuild so Opponent/Club/Match outcome aggregates are refreshed.

Direct upgrades from older pre-v2.8.8 builds still pass through the existing additive Core 5→6 migration before Core 6→7.

## Runtime state and secrets

v2.8.10 intentionally does not ship mutable production files `data/server-config.json`, `data/tournaments/archive.json`, or `data/match-tracking/index.json`. Preserve existing production data directories/configuration when extracting the release.

If an older package already replaced Tournament or Match Tracking state with an empty placeholder, the application now attempts conservative recovery from non-empty backups/history/materialized Tournament browse cache. Recovery cannot recreate information that no longer exists in any retained source.

## CRON

Run `./reset-install-cron-v2.8.10.sh` after deployment. It takes no arguments. It reads protected tokens at runtime, recovers a missing shared token from existing legacy Tournament/Match Tracking cron lines when possible, and otherwise generates a new protected 256-bit token automatically. The installed crontab never embeds secrets.

Tournament status maintenance is executed on every 10-minute Tournament CRON invocation. Discovery/podium work remains staged so the status contract and displayed freshness agree with the configured cadence.
