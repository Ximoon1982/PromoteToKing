# Team Points and scheduled-maintenance architecture — v2.7.3

## End-to-end flow

```text
Existing CRON URL or admin command
              │
              ▼
     Unified Task Registry
   p2k_control_tasks/runs/logs
              │ same-task lock
              ▼
     Domain worker/adapter
              │
              ▼
  Shared Chess.com API Gateway
 shared DB cache + health + rate limit
              │
              ▼
       Chess.com PubAPI
              │ normalized results
              ▼
 Domain storage (MariaDB or archive files)
```

The browser is a status-and-command client. It does not call MariaDB, does not own the Team Points processing loop, and is not required for scheduled work.

## Compatibility

Existing endpoints remain valid:

```text
server/team-points/public/cron.php
api/track-upcoming-league-matches/
server/tournaments/public/cron.php
```

Existing Team Points API actions also remain valid. They delegate into the shared task registry and domain queues rather than bypassing central control.

## Unified task registry

`server/shared/TaskRegistry.php` stores:

```text
p2k_control_tasks
p2k_control_task_runs
p2k_control_task_logs
```

Expected health cadences are:

```text
Team Points              5 minutes
Match monitoring         1 hour
Tournaments              5 minutes
```

Warning begins after the expected interval; critical begins after 150% of it. A partial, safely checkpointed run counts as a successful freshness checkpoint.

Each task has its own MariaDB advisory lock. Two invocations of the same task cannot execute simultaneously. Different tasks may run concurrently at the controller level, but their outbound Chess.com requests are serialized by the shared gateway.

## Shared gateway

`server/shared/SharedChessGateway.php` owns:

```text
p2k_shared_http_cache
p2k_shared_gateway_state
```

It centralizes:

- URL-keyed response caching;
- conditional HTTP revalidation;
- one global request lock and minimum delay;
- health state and known-valid probes;
- retries and Retry-After handling;
- abnormal 404/410 incident protection;
- consumer-specific cache TTLs;
- controlled stale-cache fallback.

A numerical match 404 is not treated as conclusive while the known-valid PubAPI health probe is failing.

## Team Points queue

The existing domain tables and queue are retained:

```text
p2k_tp_jobs
p2k_tp_job_items
p2k_tp_members
p2k_tp_participations
p2k_tp_board_states
p2k_tp_point_events
p2k_tp_match_metadata
p2k_tp_match_summaries
p2k_tp_club_totals
p2k_tp_job_logs
p2k_tp_worker_runs
```

Normal queue stages are `sync_members`, `sync_club_matches`, `sync_match`, `sync_player_archive`, and `sync_board`. Explicit repair actions may add `sync_player` or `discover_match_ids`. Unique keys and upserts make every stage idempotent.

## Timeout and checkpoint model

```text
Hosting hard ceiling       about 60 seconds
Endpoint target            48 seconds
Worker segment             35 seconds
Response/commit margin     at least 12 seconds
```

Before starting another outbound request, workers reserve enough time for the configured HTTP timeout plus cleanup. Multi-request operations persist their cursor before returning:

- Team Points historical ID discovery persists the exact next ID.
- Match monitoring runs on an hourly scheduler tick and persists a rotating reference cursor. Individual matches are fetched only when their adaptive due time is reached.
- Tournament discovery/status work persists batch and status cursors.

The next compatible CRON invocation continues from that cursor.

## Administration authentication

A login candidate is verified server-side. The session bootstrap connects to MariaDB, ensures schema v12, initializes the gateway/task registry, and verifies the club administrator list. A recent verified cached club profile may bridge a temporary Chess.com outage. The simulated username alone never grants access.

## Unified control page

`TaskControl.html` and `server/control/public/api.php` expose:

- task health and cadence;
- start/resume, safe pause and refresh;
- work summaries and queue details;
- gateway health and probe action;
- compatibility endpoint mapping;
- unified task runs and logs.

Dashboard System Health rows deep-link to the relevant selected task or gateway maintenance section.

## v2.7.1 clean seed and incremental operating mode

Schema 12 adds staging tables, void-match tracking and due match-detail fields. The standalone seed client verifies and validates the embedded updated snapshot and sends signed batches to `public/seed-import.php`. Staged rows do not affect public totals. Apply requires a paused Team Points task, obtains the worker lock and replaces Team Points domain rows in one transaction.

Normal recurring work is intentionally different from repair work:

1. `sync_members` reconciles the current club roster with one club-members response.
2. `sync_club_matches` reads the club match index and queues only new, changed or due match details.
3. Match details store participations and queue current/previous monthly archives for unresolved players.
4. `sync_player_archive` stores completed games with exact end timestamps.
5. `sync_board` runs later as a fallback and skips its API request when archive events already made the board complete.
6. `sync_player` and `discover_match_ids` remain available only through explicit full-member-history and bounded raw-ID repair actions.

Complete two-game boards and finalized non-void match summaries are immutable. Club totals are rebuilt from those summaries, not from a new historical API scan.

## Schema 13 Insights facts

The Team Insights data path is database-only. Authoritative event times come from `p2k_tp_match_metadata.start_time`, `p2k_tp_match_metadata.end_time`, and `p2k_tp_point_events.game_end_utc`; ingestion timestamps are never used as match dates.

`p2k_tp_insight_daily` permanently stores refreshable daily facts for matches, boards, games, active players and Club Points. `p2k_tp_insight_cache_state` records source freshness. Facts are rebuilt under a MariaDB advisory lock only when source tables change. Date-range unique-member counts, rolling windows, outcomes and interactive distributions remain live queries so filters stay exact.

Match metadata also permanently stores `rules`, `time_control`, and `is_league`. Valid duration analytics exclude void 0–0 matches and non-positive timestamp ranges.
