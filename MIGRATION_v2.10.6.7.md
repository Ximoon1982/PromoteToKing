# Migration notes — v2.10.6.7

There is no database migration in v2.10.6.7. Existing Green Core/Analytics data, GAB lane state, GFFL debt, GQAC cohorts and Blue rollback data remain in place.

The release only corrects GAB/readiness accounting and the Administration UI. Existing GAB state is intentionally preserved. The new status logic can immediately self-heal the transient case where every GAB lane is complete but `gab_status` was still `running`.

A pending read-parity lane may still contain historical `processed_rows`/`total_rows` values in the database from earlier builds; v2.10.6.7 no longer treats those stale values as current progress. The next actual read-parity smoke audit establishes the exact current denominator.

Public reads remain Blue until the existing fail-closed readiness checks pass and an administrator explicitly applies the Green-read migration phase.
