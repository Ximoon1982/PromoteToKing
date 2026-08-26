# Install Promote to King v2.10.6.9

## Supported source

This incremental release is for **v2.10.6.8**.

## Recommended IONOS command

```bash
rm -rf /tmp/p2k-v21069 && mkdir -p /tmp/p2k-v21069 && unzip -q P2K_v2.10.6.8_to_v2.10.6.9_INCREMENTAL.zip -d /tmp/p2k-v21069 && bash /tmp/p2k-v21069/update-v2.10.6.8-to-v2.10.6.9.sh
```

The updater selects a modern PHP CLI explicitly, preferring `/usr/bin/php8.5-cli` on IONOS, verifies every payload hash, creates a rollback copy of every replaced file, applies the overlay, and syntax-checks changed PHP/shell files.

## Runtime state

The updater does **not** change the persisted public routing state. A site already serving Green remains Green. Worker routing, browser-ingest routing and Green mode are not reset.

Within the next normal Green worker invocation, a compatibility-analytics refresh is eligible even if GAB is still running. Dashboard native Green totals/player points do not need to wait for that analytics rebuild.

## After installation

1. Reload the browser with a hard refresh if an old JavaScript bundle is still open.
2. Open **Administration → Scheduled Task Control → Green**.
3. Confirm `Public reads = GREEN` and `Public data mode = GREEN NATIVE + LIVE`.
4. Confirm the Dashboard Club Points value agrees with the Green value in the migration comparison.
5. Reopen Opponent Insights. Its heatmap/coverage should repopulate from the refreshed Green compatibility analytics instead of the partial bootstrap snapshot.
6. Leave GAB/GABCRF running; it is now convergence/backfill rather than a public freshness prerequisite.

No database migration/reset/reseed, CRON edit, or image deployment is required.
