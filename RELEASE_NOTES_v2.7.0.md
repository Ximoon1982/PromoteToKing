# Promote to King v2.7.0

## Administration
- Administration is reorganized into Daily matches, Live arenas, and Administration tools.
- Daily matches contains Upcoming Matches, Match Creation, Challenge Assistant, and Open Match Analyzer.
- Live arenas contains Live Ranks computation.
- Administration tools contains Scheduled Tasks Control, Task Logs, and Runtime Diagnostics with Insights API health.
- Tournament-add controls are shown only for the Tournaments scheduled task.
- Match-monitoring controls are shown only for Match Monitoring.
- Added a client control that queues incremental tournament discovery and missing-medalist processing.

## Tournament worker
- Tournament maintenance is split into persistent discovery, status, and podium stages.
- Each CRON invocation performs one API-heavy stage, saves its cursor, and rotates to the next stage.
- A daily cycle records per-stage completion and remains resumable after timeout or transient API failure.
- The worker uses a 45-second application deadline for the IONOS 60-second web-CRON ceiling.
- Recommended cadence: every five minutes.

## Compatibility
- Existing tournament archive data and discovery cursors are retained.
- Existing CRON endpoint remains valid; only its schedule changes.
