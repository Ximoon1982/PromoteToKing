# Promote to King v2.11.0 R6

R6 is a same-version corrective release for the v2.11.0 Administration and Recruitment surfaces. `VERSION` remains `2.11.0`.

## Canonical Recruitment detail

- Recruitment is registered directly in the canonical `dashboard-v2.js` Administration detail registry under **Members → Recruitment**.
- Recruitment now loads through the **same standard embedded Admin detail / iframe path** used by Team Depth, Chronology and Aliases.
- The dedicated `RecruitmentAdmin.html` embedded page uses the shared admin guard, embedded-page bridge, Team Points client, Chess.com API client and OAuth runtime.
- Recruitment no longer hides or replaces the Admin shell after routing and no longer relies on first-paint suppression, a Recruitment route class, a Recruitment-specific frame exception or a synthetic shell mount.
- The Members overview owns the Recruitment card from initial render, just like the other Members tools.
- The historical top-level **Administration** button is restored for authenticated administrators and enters the canonical Admin shell rather than the retired legacy Administration page.
- Because Recruitment is now a normal embedded detail, its content begins in the normal Admin detail area and its frame height is handled by the same embedded-page mechanism as the other Admin tools.

## OAuth full-throttle candidate evaluation

- The manual **Parallel candidates** control is removed from the Recruitment UI and server criteria.
- Candidate evaluation feeds the existing shared `P2K_API_CLIENT.processPriority()` scheduler without a Recruitment concurrency override.
- In authenticated OAuth Bearer mode, the shared scheduler provides its existing 256-item logical feeder while the OAuth gateway dynamically controls physical connection count, batching, adaptive request rate and throttling.
- Recruitment therefore follows the same centralized OAuth throughput policy as the other high-volume P2K functions instead of maintaining its own 4/12-worker loop.
- Green membership preparation uses batches of 2,000 usernames.
- Secured Recruitment checkpoints use batches of up to 500 results per write to avoid thousands of unnecessary whole-run JSON rewrites on large candidate pools.
- Pause aborts the active shared-scheduler feed and keeps the server checkpoint resumable.

## Live scan telemetry

The Recruitment progress area reports:

- candidates checked and remaining;
- rolling candidates/second with average rate;
- elapsed time and ETA;
- current OAuth gateway requests/second and adaptive target;
- secured checkpoint backlog while evaluation is ahead of persistence.

The rate is based on completed candidate evaluations, not requests merely queued for transport.

## Cumulative R5 corrections retained

R6 is cumulative with R5 and retains:

- the 100,000-candidate Recruitment pool limit;
- Green-native membership preparation and authoritative checkpoint membership revalidation;
- secured Team Points session/CSRF handling and protected Recruitment runtime storage;
- retired Data Reconciliation production mutation path;
- Green-native Freshness and Maintenance freshness metrics.

## Data and deployment safety

- No database schema change.
- No Green or Blue database reset.
- No Recruitment pool/run reset.
- No configuration reset.
- The corrective installer is transactional, creates a pre-install backup of every replaced existing file, supports idempotent reinstall, and rolls back on post-install verification failure.
