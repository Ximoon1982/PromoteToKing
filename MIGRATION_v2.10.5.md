# Promote to King v2.10.5 — Blue / Green migration controls

v2.10.5 moves routine Green migration operation into Administration Tools.

## Routing states

- **Blue primary** — public reads Blue; server worker and client ingest target Blue.
- **Shadow writing** — public reads Blue; Blue + Green maintenance/ingest run together.
- **Green validated** — public reads Blue; both engines remain maintained after Green readiness validation.
- **Green reads + both writing** — reserved cutover phase.
- **Green primary** — reserved final production phase.

The last two phases are visible but safety-gated in v2.10.5 because the complete public/API compatibility read adapter is not enabled. This prevents a partial migration where only some UI surfaces read Green.

## Rollback

Blue remains maintained during validation. The Production Migration panel can return routing to Blue primary. No v2.10.5 schema action deletes Blue facts.

## Readiness

Use validation plus the integrated observables to check seed completion, unknown matches, board debt, profile/stats completion, integrity leaks, current-fact maintenance, identity mappings, request outcomes, GQAC convergence and Blue/Green comparison before any later public-read cutover release.
