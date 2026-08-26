# v2.10.1.1 cumulative fix map

The source contains all fixes previously folded into v2.10.1, plus:

1. **Migration Panel Resilience Hotfix 1** — Green routing/status remains usable if Blue telemetry or comparison fails.
2. **Blue task-state schema compatibility** — task telemetry reads only `task_key`, `status`, and `pause_requested`, avoiding optional timestamp columns absent from the installed Blue schema.

These fixes are integrated directly; no hotfix is installed sequentially.
