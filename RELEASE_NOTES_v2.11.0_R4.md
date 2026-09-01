# Promote to King v2.11.0 R4

Corrective Recruitment integration release for v2.11.0. No database schema, Team Points calculation, Recruitment criteria, or Green-production logic change.

## Fixes

- Recruitment now renders inside the canonical Admin detail shell instead of replacing `adminDashboardHost`; Admin category navigation remains visible.
- The obsolete v2.11.0 R3 Recruitment mount loader is retired.
- Empty Team Points `storage.runtime_dir` now correctly means the protected default `data/runtime-v280`, so Recruitment state is stored under `data/runtime-v280/recruitment-admin` rather than attempting `/recruitment-admin`.
- Recruitment non-GET requests reuse `P2K_TEAM_POINTS_CLIENT.endpointRequest`, preserving the existing same-origin HttpOnly Team Points session and `X-P2K-CSRF` validation.
- The secured POST bridge is concurrency-safe for parallel candidate checkpoints.
- `ui-v2.html` receives an R4 `site-config.js` cache key during deployment.

## Installer validation

- R3 → R4 correction: PASS.
- Idempotent R4 reinstall: PASS.
- Forced post-patch rollback: PASS; prior files restored and newly introduced R4 file removed.
- Parallel secured Recruitment POST simulation: PASS.
- Installer proactively creates and write-tests `data/runtime-v280/recruitment-admin`.
