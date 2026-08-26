# Promote to King v2.8.8.2 release notes

v2.8.8.2 is a focused Dashboard-startup hotfix built on v2.8.8.1.

## Dashboard startup regression fixed

Investigation back to v2.8.6 isolated the first server-side regression to v2.8.7. The Dashboard's initial public database request had moved from a materialized Analytics summary to a live historical Core aggregate, while Core freshness metadata also routed through a state helper that can create the state row with `INSERT IGNORE`.

v2.8.8.2 restores a genuinely read-only/materialized public startup path:

- historical Dashboard totals are served from `p2k_tp_club_totals`;
- current member count is overlaid from the indexed authoritative Core roster;
- Core generation/freshness metadata comes from a SELECT-only state reader;
- public Dashboard/read-metadata GETs do not create or mutate `p2k_tp_state`;
- worker/admin state creation remains unchanged.

## Client startup made non-gating

The v2.8.8.1 3.5-second timeout mitigated a delayed database response but still placed it in front of the second wave. v2.8.8.2 removes that dependency completely: the first materialized database request and the automatic current matches/Live/secondary refresh path are launched independently. If the database response is delayed, the rest of the Dashboard continues loading.

## Retained v2.8.8.1 fixes

- immediate configured/local administrator recognition;
- Administration iframe authorization race handling and bounded retry;
- retryable Hall/Insights lazy module loading;
- nested Profile/Achievement modal restoration;
- unclipped responsive Profile rank artwork;
- Opponent Intelligence W/D/L from canonical finished-match summaries plus automatic logic rebuild;
- Team Insights all-history Club Points progression through today + six months, using the shared Low/Medium/High forecast model.

## Database and CRON

- Core schema **6**, Analytics schema **5**.
- No reset, re-seed or schema migration is required from v2.8.8.1.
- CRON cadence is unchanged: Club every 5 minutes, Tournament every 10 minutes, Player every 30 minutes, league monitoring hourly.
- No new ACAMR/Intelligence/hotfix CRON task.
