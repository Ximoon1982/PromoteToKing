# Promote to King v2.11.3 architecture

## Release boundary

v2.11.3 consolidates runtime ownership while preserving v2.11.2 UI, display, DOM, routes, payloads, workflows, persistence, scheduling and authentication behavior. The production allowlist is machine-enforced against promoted v2.11.2 commit `4ececcc230ca07099b346cb47396ad00bedd5c21`; visual assets and styles remain forbidden.

## Frontend runtime ownership

`api-client.js` remains the public compatibility facade. Request semantics, OAuth gateway context, transport execution and request coordination now have explicit factory boundaries. Every existing HTML entrypoint loads those dependencies in the same fixed order immediately before the facade. Existing globals, request timing policy, retries, errors and diagnostics remain the observable contract.

## Administrative jobs

`AdminJob` is an observation boundary in v2.11.3, not a scheduler or persistence framework. Recruitment scan uses a read-only telemetry adapter because its existing JSON run already owns identity, lifecycle, candidates and results. `RecruitmentRunStateReader` reads that state without saving or migrating it; `JobRunner` exposes normalized telemetry only. Start, pause, resume, checkpoint and response-envelope policy remain with Recruitment. This is not execution-level lifecycle adoption.

An execution-level candidate audit covered Recruitment, the SQL-backed Team Points worker, fair-play reconciliation and the remaining asynchronous runtimes. Each candidate couples execution to existing persistence, scheduling, cancellation, retry or public workflow policy. Moving any of those responsibilities would exceed this release's behavior-equivalence contract, so execution-level `AdminJob` adoption is explicitly deferred to a separately approved release.

## Async ownership decision

Continuous refresh, convergence refresh, authenticated-member refresh and analysis coordination retain their feature owners. Their shared-looking mechanics carry different deadlines, retry classifications, cancellation rules and cache lifecycles. v2.11.3 therefore introduces no universal async engine, no new scheduler, no persistence migration and no CRON change.

## Dependency direction

Shared mechanics may be called by feature policy; shared mechanics may not import page or feature policy. Public compatibility facades remain in place. Endpoint formatting remains at the API boundary, SQL ownership remains in repositories, persisted formats remain authoritative, and authentication/session behavior remains frozen.
