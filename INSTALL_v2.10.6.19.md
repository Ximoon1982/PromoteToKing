# Install v2.10.6.19

The cumulative incremental installer accepts installed source identity **2.10.6.15, 2.10.6.16, 2.10.6.17 or 2.10.6.18** and upgrades it to v2.10.6.19.

After installation:

1. Hard-refresh `ui-v2.html`.
2. Open Administration → Scheduled Task Control → Green Team Points.
3. If GABCRF is in error, click **Start / resume GAB** once. Otherwise leave it running.
4. Under **Historical heatmap backfill**, click **Requeue missing coverage** once. This discards the obsolete v2.10.6.18 match-detail work ledger and builds the corrected board-detail queue.
5. Leave Green mode on Auto/Quick; do not force Deep.
6. Ensure Browser ingest is `Green` or `Both`.
7. Click **Start accelerator**. When residual board work is being fetched, Priority lane should report `HEATMAP`.
8. Task Control should now show real OAuth gateway activity and a learned safe rate once server telemetry is available.
9. Coverage can also rise without external requests as GABCRF reprojects already-stored rated Green games.
10. Public heatmaps become visible after compatibility analytics materialization and the short Opponent Insights cache window.

If many board fetches fail, stop the accelerator and inspect the first sampled error. Accelerator work no longer falls back to JSONP, so the error should identify the actual OAuth/gateway/HTTP condition.

No database reset/reseed or CRON edit is required.
