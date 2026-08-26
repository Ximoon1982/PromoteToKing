# Promote to King v2.10.6.22

## GABCRF deadlock resilience and Admin corrective

This corrective makes Green Analytics Bootstrap compatibility reconciliation resilient to normal MariaDB/InnoDB serialization deadlocks. SQLSTATE `40001` / error `1213` is now treated as transient: the current GABCRF batch is retried up to three times with short backoff; if contention persists, the slice yields and the next CRON invocation retries the same cursor. Pass number, cursor, processed/changed counters and last full-pass remaining count are preserved.

A GABCRF lane already stopped by a stored deadlock is automatically returned to `running` by the Green worker after upgrade. The manual Start / resume action also preserves GABCRF convergence bookkeeping; only an explicit restart may discard it. Non-transient GAB errors retain the existing fatal/error behavior.

The administrator Dashboard's `New matches · 24 h` match-detail path now replaces its transient Chess.com refresh/loading modal when the stored match profile opens, so closing the profile returns to the discovered-match list rather than revealing the obsolete loading screen.

The Administration category formerly labelled `Admin & maintenance` is renamed `Maintenance` in the six-tab menu, category cards and detail breadcrumb.

No database schema/reset/reseed, CRON definition, Blue/Green routing, scoring, heatmap formula, public navigation, or artwork changes are included.
