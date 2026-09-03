# P2K v2.11.3 — Runtime Ownership & Job Orchestration Consolidation

## Contract

This is a behavior-equivalent consolidation release. It preserves the complete v2.11.2 user interface, display, functions, routes, payloads, data semantics, database schemas, stored formats, CRON entries, OAuth/session behavior and operational workflows.

## Completed consolidation

- The API client remains a compatibility facade over explicit request-semantics, OAuth-context, transport and request-coordination owners.
- All 17 existing API-client HTML loaders retain dependency-before-facade order.
- Recruitment scan exposes its already-existing run state through a read-only `AdminJob` adapter.
- Recruitment remains authoritative for execution, lifecycle, checkpoint persistence and public response formatting.

## Backend and asynchronous audit

| Runtime | Policy owner retained | Shared boundary | Decision |
|---|---|---|---|
| Recruitment scan | Recruitment JSON run and endpoint | Read-only AdminJob telemetry | Adopted |
| Continuous refresh | Feature controllers | Cancellation and lifecycle registration | Retained |
| Active convergence refresh | Analytics/worker | Deadline and retry classification | Retained |
| Authenticated-member refresh | OAuth API client | Deduplication and request scheduling | Retained |
| Analysis coordination | Analysis feature | Cancellation and cache lifecycle | Retained |

The retained runtimes are intentionally not forced into `AdminJob`: doing so would either move policy into a generic layer or require persistence/scheduling changes, both outside this release contract.

## Qualification rules

Strict parity rejects production changes outside the explicit allowlist and rejects every visual asset/style change. Architecture and source tests freeze module ordering, public compatibility surfaces, the read-only Recruitment adapter, and the absence of new scheduling or persistence policy. The release package must additionally prove upgrade, preservation, idempotency and rollback from representative v2.11.x baselines.
