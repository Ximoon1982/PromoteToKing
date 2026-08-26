# Promote to King Standalone v2.8.7

v2.8.7 is built on the finalized v2.8.6 progressive-loading baseline. It integrates the post-v2.8.6 architecture/performance/correctness audit and adds **ACAMR — Authenticated Client-Assisted Member Refresh**.

## ACAMR — authenticated client-assisted member refresh

ACAMR uses authenticated browsers as a low-pulse discovery/verification accelerator for the existing Team Points workers. It does **not** create a second source of truth: browser Chess.com responses remain untrusted observations and canonical facts are still written only after server-side verification through the shared Chess.com gateway.

Activation contract:

- **Real OAuth authentication:** ACAMR is active regardless of the simulated-OAuth feature flag.
- **Simulated authentication:** ACAMR is active only when the `oauth` / `simulatedOAuth` feature flag is enabled.
- **No authenticated session:** ACAMR is inactive.

ACAMR scope includes:

- Club Points and Member Points discovery/computation pipeline;
- current-member team-match and board discovery required for point calculation;
- current player archives used to locate finished team games;
- Daily/Classical and Chess960 rating refresh hints;
- low-frequency authoritative roster refresh hints.

ACAMR explicitly excludes:

- tournaments;
- match-registration monitoring, recruitment, or registered-player tracking.

The client scheduler elects one same-origin leader across tabs/iframes and requests small server-assigned member claims. Different connected users are distributed across the member pool through temporary protected filesystem claims. Default pulse is 20 seconds and claims are held for 20 minutes to avoid duplicate work.

For ACAMR monthly archive observations, the server no longer has to blindly re-fetch the complete archive simply to discover relevant work. If a known P2K participation is identified, ACAMR schedules the corresponding authoritative board verification. The board worker then fetches Chess.com itself and stores the canonical game result/point event. Client-computed scores are never accepted.

## Post-v2.8.6 audit integration

- Dashboard historical totals now use the canonical Core snapshot and cannot be overwritten by Chess.com's bounded recent match index.
- Redundant Dashboard club-members/profile requests are removed where local Core data already exists.
- Core generation/freshness participates in mixed Core/Analytics cache invalidation.
- Player/Profile membership freshness is overlaid from Core.
- Club resolution avoids repeated population-wide COUNT scans.
- A paused Player-history lane still performs the cheap authoritative roster freshness pass.
- Members custom-range sections share one range projection; Team Insights progressive sections share one normalized daily base dataset.
- Daily and Live Hall summaries use bounded SQL aggregates and fetch member rows only for the selected page.
- Tournament browsing uses a materialized browse index with early ETag/304 handling; Hall search uses its per-player tournament index.
- Dashboard secondary work remains automatic but is routed through the shared low-priority scheduler.
- Hall and integrated Insights logic are lazy modules, reducing the initial Dashboard controller size.

## Correctness retained

- Finished authoritative 0–0 matches stay stored with `is_void=1` for traceability and remain excluded from every public analytical metric, graph and achievement path.
- Club and Player Team Points workers remain separate.
- Failed-board recovery and synchronization-health semantics remain intact.
- Avatar/profile fetching remains persistent, demand-driven and visible-batch only.
- Tournament Achievement Date Revision 2 remains intact.

## Database / CRON

- Core schema: **5**.
- Analytics schema: **5**.
- No database reset, re-seed or SQL migration is required from v2.8.6.
- Server CRON cadence is unchanged: Club 5 min, Tournament 10 min, Player 30 min, league monitoring hourly.
- ACAMR is opportunistic only; server workers remain fully autonomous when no authenticated users are online.
