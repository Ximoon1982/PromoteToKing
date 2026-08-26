# Promote to King v2.10.5.2

Green production-read/write cutover release on top of v2.10.5.1.

## Green Analytics Bootstrap (GAB)

v2.10.5.2 adds a dedicated, resumable Green Analytics Bootstrap. It does **not reseed Green Core**. Instead it prepares Green-owned compatibility/read models required by the existing public site:

- compatibility schema bootstrap inside the Green Core/Analytics databases;
- opponent and trusted identity/reference history migration from Blue;
- MCA / Live Ranks raw files, source rows, attributions and processing history migration;
- historical achievement unlock migration before Green recomputation/verification;
- member, match, board and game projection from canonical Green facts;
- analytics/read-model rebuild;
- bounded missing-opponent enrichment;
- endpoint-level Green read-parity validation.

GAB is checkpointed by lane and is restartable/idempotent. Failed enrichment is visible and retryable. GAB must reach `ready` before Green public reads can be selected.

### Accelerator priority

P0 interactive/user-facing traffic remains highest priority. While GAB is running, GAB is the highest-priority **background** accelerator lane, followed by GFFL hot/due work and then ordinary Green cycle work. GAB does not own an independent concurrency controller: the shared OAuth/ACAMR transport remains the pacing/concurrency authority.

## GFFL — Green Factorized Freshness Lane

GFFL factorizes current-match freshness at match level:

- duplicate freshness obligations for one match are coalesced;
- one authoritative `/pub/match/{id}` fetch satisfies all coalesced obligations;
- a detected board change promotes its match to the hot lane;
- GFFL hot/due work is serviced before ordinary Green background work;
- successful match fetches retire the match debt and update Green-compatible read projections;
- target freshness is configurable from Scheduled Task Control, defaulting to 1,200 seconds (20 minutes).
- completed debt is re-armed from its newly calculated freshness target rather than inheriting an already-expired due timestamp;
- server fallback current-match maintenance shares the configured GFFL freshness target, preventing a parallel five-minute refetch cadence from bypassing factorization.

Green validation uses the GFFL SLO for current-match readiness while preserving the tighter five-minute debt metric for operations/telemetry.

## Public Green read/write cutover

Existing public Team Points contracts now opt into an explicit `PublicReadDatabase` router. Blue workers continue using their original `Database` connections, so selecting Green public reads cannot redirect Blue worker writes accidentally.

Cutover remains explicit and validation-gated:

1. `blue_primary`
2. `shadow_writing`
3. `green_validated`
4. `green_reads_both_writing`
5. `green_primary`

Green-read phases require both Green validation and GAB/read-contract parity. Once Green is selected and reachable, the public read router is fail-closed: an adapter/schema failure is surfaced instead of silently hiding a Blue dependency.

Normal auxiliary Team Points writes for Live Ranks/MCA, MIAC and opponent maintenance use the selected compatibility store. In `green_primary`, legacy Blue ACAMR planning/observation ingest is disabled so stale browser tabs cannot continue spending API budget or mutating Blue; Green Accelerator is the browser background writer.

Blue databases/code remain available as rollback/frozen sources in this release. v2.10.5.2 does **not** physically delete Blue or its databases.

## Scheduled Task Control

Scheduled Task Control is now the normal Green operations surface. It no longer exposes the old Blue Team Points repair card. It provides:

- explicit migration phase control and Blue-read rollback;
- worker routing;
- browser-ingest routing;
- Green Auto/Quick/Deep/Seeding mode control;
- run-now and validation actions;
- GAB Start/Resume and Run Slice controls with per-lane progress/error/timestamps;
- GFFL enable/disable and freshness-SLO controls;
- Green Accelerator Start / Run Once / Stop controls;
- accelerator lane, planned/fetched/accepted/changed/failure, rate and shared-transport metrics;
- cycle count, overall cycle progress, per-phase progress/timestamps;
- timestamped recent Green invocation history and accelerator logs.

The legacy Production Migration page may remain as an emergency/diagnostic page, but it is no longer required for routine migration control.

## Dashboard Match Assistant

Clicking **Matches starting within 7 days** or **Priority calls** now preserves the Match Assistant open intent across asynchronous recommendation/iframe hydration. Terminal recommendation renders reassert the assistant opener, so the assistant remains visible and receives the pending filter instead of being hidden again.

## Migration-control corrective

The earlier `set-migration-phase` HTTP 500 behavior is corrected:

- Blue rollback maintenance is confirmed before a Blue-dependent phase is committed;
- `green_validated` requires successful Green validation;
- Green-read phases require GAB/read adapter parity;
- failed phase prerequisites return controlled errors without advertising a phase that did not safely complete.

## Safety / compatibility

- **No Green Core reseed:** GAB never reseeds canonical Green Core.
- No destructive database reset or database deletion.
- Green schema changes are additive/idempotent.
- Existing five staggered Green CRON entries and 50 s soft / 55 s hard worker runtime remain unchanged.
- Public reads default to Blue after install until an administrator explicitly advances migration phase.
- Blue remains available for rollback during the proving window.
- `assets/images` are unchanged from v2.10.5.1 and remain a separately packaged synchronized archive.
