# Promote to King v2.8.8.2 startup hotfix

This hotfix addresses the persistent production symptom where the public Dashboard can remain blank/pending even after the v2.8.8.1 client-side timeout/admin fixes.

## Regression boundary

Comparison of the shipped v2.8.6, v2.8.7 and v2.8.8.1 code identified the first problematic server-side critical-path change in **v2.8.7**:

- v2.8.6 served the first Dashboard historical totals from the one-row materialized Analytics projection;
- v2.8.7 changed that first read to a live aggregate over historical Core match metadata;
- v2.8.7 also added Core freshness metadata through `Repository::state()`, whose normal worker path calls `ensureState()` and can execute `INSERT IGNORE` before reading state.

That meant a public GET could perform a database write and a historical aggregate before returning the first Dashboard payload. If a worker transaction held the relevant database lock, the public PHP request could wait behind it. On a constrained PHP host, blocked requests can also occupy workers needed by Administration iframe requests.

## v2.8.8.2 correction

- Public Dashboard/read metadata now use a new **pure read-only Core state lookup**. Public GETs do not create/update the Core state row.
- Worker/admin write paths retain `state()` + `ensureState()` exactly where state creation is appropriate.
- The first Dashboard historical totals again come from the **materialized Analytics `p2k_tp_club_totals` row**, while current membership still overlays the indexed authoritative Core roster count.
- The browser no longer waits for the first database response before launching the automatic current/Live second wave. The materialized database request and post-paint refreshes progress independently.
- A delayed database response can update the Dashboard when it arrives, but cannot hold the whole Dashboard in a startup state.

All v2.8.8.1 fixes remain included: immediate configured-admin recognition, Administration iframe race/retry handling, retryable Hall/Insights lazy modules, nested modal reliability, profile rank-image containment, canonical Opponent Intelligence outcomes, and the six-month Team Insights Club Points progression forecast.

## Operations

- Core schema: **6**.
- Analytics schema: **5**.
- No database reset/re-seed or new SQL migration from v2.8.8/v2.8.8.1.
- CRON cadence remains unchanged.
