# Promote to King v2.11.0

## Administration consolidation

- The Public/Admin toggle is now the canonical Administration entry point.
- Legacy `page=administration&adminTool=...` bookmarks are normalized into the toggle Admin routes; no new feature is added to the legacy Administration surface.
- Recruitment is a native Admin → Members card/detail and reuses the existing persisted candidate pool, resumable run/checkpoint state and CSV export backend.
- Admin mode uses the existing red Administration accent for selected toggle/category/detail navigation; Public mode retains the normal gold accent.
- Remaining embedded admin tools use the parent page as the vertical scrolling context to reduce nested/mobile scrolling problems.
- The legacy Production Migration surface is retired from normal Administration navigation.

## Green becomes the production database

- Green Core + Green Analytics are the sole Team Points production read route.
- Public reads are fail-closed on Green configuration/schema failure; there is no automatic production fallback to Blue.
- Blue is preserved as a static recovery/reference copy and is no longer a production route.
- The 2.11.0 promotion tool normalizes live state to `public_read_target=green`, `migration_phase=green_primary`, `worker_target=green`, and `client_ingest_target=green`, then pauses the old Blue Team Points task flags without deleting Blue data.
- Blue/Green read switching, rollback, worker-target switching, client dual-ingest switching, migration-phase controls and migration-era seeding controls are retired.
- Historical heatmap-backfill controls are retired.
- GAB/compatibility projection may continue as non-gating technical-debt work; GAB/compatibility readiness no longer determines whether Green can be production.
- GFFL, Green worker execution, GQAC/accelerator behavior, runtime/cycle monitoring, worker history and match monitoring remain operational.
- MCA administration now resolves its Core/Analytics connections through the Green-only public-read router; the explicit MCA Blue → Green snapshot synchronizer is retired.

## Safety and data preservation

- No production data reset is performed.
- No Blue database is deleted or rewritten by the release.
- No new database schema is required by this release; the installer verifies the already-required Green Core schema >= 17 and Green Analytics schema >= 9 before promotion.
- The Green promotion operation is idempotent.
- Existing Green runtime, configuration and stored data are preserved.
- Existing protected/local configuration files remain outside the release payload and must be preserved byte-for-byte by the installer.

## Release installation expectations

The v2.11.0 incremental installer is intended for the canonical v2.10.9.8 deployment and for idempotent re-installation of v2.11.0. It must:

1. select the IONOS PHP CLI explicitly (8.5 first, then supported fallbacks),
2. validate the source payload and PHP/JavaScript syntax before touching production,
3. verify Green configuration and schema prerequisites,
4. create a timestamped rollback backup of every replaced file plus the pre-promotion Green routing state and Blue task-control state,
5. install the release files transactionally,
6. execute the idempotent Green-primary promotion,
7. verify Green is the effective production route and VERSION is 2.11.0,
8. roll files and routing/task state back if a post-install check fails.

## Baseline

v2.11.0 is based on the complete canonical v2.10.9.8 source promoted to `main` on 2026-09-01. The active development/release branch is `release/v2.11.0`.
