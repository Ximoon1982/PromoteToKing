# Promote to King v2.12.0 — Match Recruitment Assistant

The Match Recruitment Assistant now answers the operational question “Who is eligible to recruit?” using a DB-first, API-second pipeline.

- Resolves a Chess.com team-match URL, slug containing its numeric ID, or numeric ID.
- Selects stored Daily standard or Daily Chess960 ratings from the match rules.
- Applies rating, registration and opponent-membership exclusions before live player checks.
- Uses the shared OAuth/API scheduler for reduced-candidate last-online, timeout and current-load verification.
- Exposes configurable online-window and timeout thresholds, a live filtering funnel, observed-throughput ETA, explicit partial-failure results, sortable/searchable output and UTF-8 CSV export.
- Retains the canonical Admin guard, embedded detail integration, shared transport limits and current P2K data stores.

No database migration or new CRON task is introduced.

## Corrective qualification

- ETA derives from observed wall-clock completion throughput and a recent rolling window; it never assumes scheduler concurrency.
- Opponent membership is fetched once per scan through the shared API client with `no-store`. Failure stops classification instead of accepting stale or unknown membership.
- Timeout eligibility uses Chess.com Daily `record.timeout_percent`, the same canonical metric as the existing P2K recruitment implementation. Missing values remain unverified.
- Current match load remains optional `/games` enrichment. An isolated load failure displays an unavailable value without rejecting an otherwise verified candidate.
- The Core-only recruitment pool no longer invokes population-wide Analytics member-activity computation.
