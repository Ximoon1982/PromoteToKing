# Promote to King v2.11.4 — Operational Robustness & Migration Closure

v2.11.4 is a bounded operational-hardening increment over the exact qualified v2.11.3 Git baseline `dcd71c8e76c07defacf6270aff4224b10484968b`. It does not change public routes, data schemas, persisted formats, authentication, CRON, navigation, or visual assets.

## Compatibility Analytics

Compatibility Analytics remains required because active Green public endpoints consume the mature `p2k_an_*` projections. The maintenance path remains outside the finite worker lock and retains its five-minute eligibility cadence and independent advisory lock. Before a full rebuild it now checks the existing authoritative AnalyticsBuilder watermarks; when sources have not changed it records a skip reason rather than repeating a full transactional rebuild. Telemetry distinguishes finite-lock time, Green Analytics, Compatibility Analytics, total runtime, rebuild reason, and affected rows.

## GAB convergence

The historical 89.9% display is lane-weighted and is not an authoritative statement that work is stuck. Status now exposes measured obligations, completed and unresolved work, retryable and currently-due work, terminal 404/410 retirements, oldest unresolved work, numerator/denominator, and last real progress. Completion policy and persistence are unchanged.

## Adapter parity

The authoritative parity result is the persisted GAB `read_parity` lane. The status API and Task Control now consume that record, including mismatches and the last error, instead of waiting for a payload flag that the API never emitted. No parity result is fabricated and Green-primary operation does not force completion.

## Invocation lifecycle

Normal, waiting, and caught-error paths finalize an invocation. Disabled and busy exits occur before invocation creation. Failure-state persistence is independently guarded so finalization is still attempted if that write fails. A row still marked running after 15 minutes is classified read-only as `stale_running`; no historical record is automatically rewritten or retried.

## Qualification boundary

The v2.11.4 parity gate permits production changes only in Task Control and the five Green operational files named by the audit. CSS, images, templates outside the cumulative installer payload, GQAC, GFFL, OAuth/session semantics, and all unrelated runtimes remain frozen. Every qualified package receives one immutable static-asset cache key derived from exact source revision and build identity.
