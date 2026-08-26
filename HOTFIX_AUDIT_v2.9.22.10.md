# v2.9.22.10 telemetry hotfix audit

## Root cause
The Task Control status pulse intentionally became lightweight in v2.9.22.8. However, the browser still executed `state.taskDetails.clear()` every 60 seconds. A selected task therefore lost its lazy-loaded work snapshot on every status refresh and fell back to bootstrap transport health until the user explicitly re-selected the card. The regression affected all four server-backed task panels, not only Club Points.

## Correction
- Removed destructive detail-cache invalidation.
- Status refresh now re-fetches only the selected server task detail.
- Added an in-flight detail-load guard so concurrent card/status requests coalesce.
- A failed refresh retains the previous detail snapshot.
- Expanded scalar detail rendering from 12 to 32 entries and surfaces TaskRegistry execution details.

## Server telemetry
The Team Points detail endpoint remains lane-local. It does not restore the old whole-Core status aggregation. Club Points now reports club-index observed/verified freshness, match-state and due-detail counts plus maintained durable-job/active-queue counters. Member Points reports operational and server-verified freshness independently. Worker idle results now state why no durable job is runnable, and both CRON and bounded ACSR controller runs persist those fields into the unified TaskRegistry.

## Other panels checked
- Club Points: restored and enriched.
- Member Points: restored and enriched.
- Match monitoring: existing detailed summary/work report retained through status refresh.
- Tournaments: existing detailed summary/work report retained through status refresh.

## Risk controls
No schema, CRON, scoring, OAuth, reconstruction or queue scheduling policy is changed. The selected-detail refresh preserves the v2.9.22.8 load-shedding rule: one server panel at a time, never all panels on bootstrap/status.
