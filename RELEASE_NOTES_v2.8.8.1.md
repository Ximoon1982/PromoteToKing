# Promote to King v2.8.8.1 hotfix release notes

v2.8.8.1 is a focused reliability/correctness hotfix for v2.8.8. It retains the complete v2.8.8 feature set.

## Dashboard and Administration startup

- The Dashboard first-paint critical path no longer waits indefinitely for the initial materialized Team Points database request. After a bounded 3.5-second wait it renders and starts the normal automatic second wave while the database request continues in the background.
- A locally/configured administrator is recognized immediately from the authenticated session/configuration. Chess.com club-profile verification no longer sits in front of an already sufficient local admin decision.
- Embedded Administration pages tolerate parent/iframe initialization-order races instead of depending on one readiness message arriving at exactly the right instant.
- Lazy Hall/Insights modules use an 8-second load guard with one cache-busted retry. A failed first dynamic load is no longer permanently memoized as a dead feature promise.
- Integrated Administration iframes likewise receive one bounded cache-busted retry if their first load does not complete.
- These changes are aimed at the observed symptom where the Dashboard eventually appeared and admin recognition eventually succeeded, but the Administration panel itself could remain blank/pending.

## Unified profile, achievements and modal reliability

- Nested modal restoration remains DOM-preserving.
- Asynchronous Profile enrichment is now scoped to the specific Profile root rather than the currently visible global modal body. Opening an Achievement while Profile enrichment is still running can no longer redirect later Profile callbacks into the nested Achievement modal.
- Profile rank artwork keeps its intrinsic square display box and `object-fit: contain`; responsive profile layouts no longer clip the lower portion of rank images.

## Opponent Intelligence correctness

- Opponent win/draw/loss totals now consume the canonical `p2k_tp_match_summaries.result` outcome used by the rest of current analytics rather than the older match-metadata result field.
- Authoritative void 0–0 matches remain excluded.
- The Analytics source watermark includes `opponent-outcomes-v2881`, forcing one normal materialized Analytics rebuild after deployment so already-stored zero-loss opponent rows are corrected automatically. This is a logic rebuild, not a schema migration.

## Team Insights — Club Points score progression

- Adds **Club Points score progression** immediately above the existing Year-over-year Club Points progression.
- The chart begins on the first stored Club Points day and shows actual cumulative Club Points through today.
- It then projects exactly six calendar months beyond today using the **same forecast engine and assumptions** as the year-over-year chart:
  - latest 90 days split into three 30-day blocks;
  - recent-rate weighting;
  - capped ±8% trend bend;
  - Low / Medium / High scenarios with the same ±10% uncertainty band.
- Forecast implementation is shared, not duplicated, so the two Team Insights projections cannot silently diverge in methodology.

## Database and operations

- Core schema remains **6**.
- Analytics schema remains **5**.
- No database reset, re-seed or SQL migration is required when upgrading from v2.8.8.
- Upgrading directly from v2.8.7 still requires the normal additive Core 5 → 6 migration introduced by v2.8.8.
- CRON cadence is unchanged: Club every 5 minutes, Tournament every 10 minutes, Player every 30 minutes, league monitoring hourly.
- No new hotfix CRON entry is introduced.
