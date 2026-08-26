# v2.10.6.9 Green public-data migration note

## Objective

After v2.10.6.8 the routing flag could correctly say **GREEN** while some public features still read Green-hosted compatibility tables that had last been materialized during an earlier GAB phase. The observed symptoms included:

- Dashboard Club Points below the native Green value.
- Dashboard finished-match aggregate lagging native Green.
- Opponent Insights heatmap coverage collapsing to a small partial population.
- Dashboard System Health flagging the deliberately non-authoritative Blue Club Points task.

v2.10.6.9 makes Green authoritative at the data-contract level, not only at the database-router level.

## Read policy

### Native Green reads

These public values use Green Core directly when the effective public source is Green:

- Dashboard club totals / Club Points.
- Signed-in player Team Points summary and W/D/L/game totals.
- Current membership/identity chronology required by that player summary.

### Live Green compatibility contracts

Existing public services that depend on the mature `p2k_tp_*` / `p2k_an_*` contracts retain those APIs, but their source is now live Green:

- every Green worker match/member update projects immediately;
- browser/accelerator observations project the affected match/board/member immediately;
- 404/410 terminal match/board observations also project immediately;
- compatibility analytics refresh while GAB is running.

This preserves stable public service contracts while removing the stale-GAB materialization behavior.

## GAB/GABCRF after cutover

GABCRF remains useful. It repairs historical compatibility drift and proves eventual completeness. It is no longer a freshness gate for current Green observations or analytics rebuilds.

## Rollback

Blue remains intact. If a Green-serving defect is discovered, **Rollback reads to Blue** remains available. v2.10.6.9 does not delete Blue data or alter CRON definitions.
