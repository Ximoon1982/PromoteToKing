# Promote to King v2.10.1.1 — cumulative migration maintenance release

v2.10.1.1 is a clean cumulative point release over v2.10.1. It includes the complete v2.10.0 hotfix/consolidation line, v2.10.1 Migration Panel Resilience Hotfix 1, and the Blue task-state schema compatibility correction.

## Fixed

- Blue task telemetry no longer selects optional `last_start_at`, `last_success_at`, or `updated_at` columns.
- This fixes the migration-page `Partial` state seen on Blue schemas where `p2k_control_tasks` does not contain `last_start_at`.
- Green status, Blue/Green comparison, accelerator behavior, routing and force mode are unchanged.

## Data / runtime impact

- No database schema migration.
- No reset or reseed.
- No Green cycle/stage change.
- No worker/client routing change.
- No Blue task-state write.
- Blue remains public and frozen at baseline v2.9.22.10.

The point release is safe to install over either plain v2.10.1 or v2.10.1 with Migration Panel Resilience Hotfix 1 already applied.
