# Install v2.10.6.18

The cumulative incremental installer accepts installed source identity **2.10.6.15, 2.10.6.16 or 2.10.6.17** and upgrades it to v2.10.6.18.

After installation:

1. Hard-refresh `ui-v2.html`.
2. Open Administration → Scheduled Task Control → Green Team Points.
3. If GABCRF is in error, click **Start / resume GAB** once.
4. Under **Historical heatmap backfill**, click **Prepare / resume backfill**.
5. Confirm that `Pending` becomes non-zero and note the paired-rating coverage.
6. Ensure Browser ingest is `Green` or `Both`.
7. Click **Start accelerator**. The Accelerator priority lane should report `HEATMAP` when it is servicing the historical backfill.
8. Leave normal Green mode on Auto/Quick; do not force Deep for this backfill.
9. Heatmap coverage will rise as match details are accepted. Public heatmaps become visible after the next compatibility analytics refresh and the short browser/server cache window.

`Requeue missing coverage` discards the heatmap work ledger and rebuilds it from matches that are still missing paired-rating provenance. Use it only after the first queue drains or if you intentionally want to retry the currently missing population.

No database reset/reseed or CRON edit is required.
