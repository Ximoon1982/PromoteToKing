# Migration to Promote to King v2.10.6.16

No database migration, reset or reseed is required.

The first Green worker invocation after deployment automatically repairs an active Quick cycle that is already in `quick_profiles` or `quick_stats` without a GQAC cohort. The repair preserves all acquired Green data, rewinds the active cycle to `quick_boards`, creates the missing finite cohort and then resumes the normal Quick sequence.

After installing, hard-refresh `ui-v2.html` once so v2.10.6.16 Task Control and Admin assets are loaded.
