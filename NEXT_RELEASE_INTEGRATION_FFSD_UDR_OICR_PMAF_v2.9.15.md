# Post-v2.9.15 next-release integration — FFSD / UDR / OICR / PMAF

Baseline: exact frozen Promote to King v2.9.15 standalone, SHA256 `83813c121c82b6c318e230065baaf52fd6930854ebdbb2723acb4159f0dc070e`.
Source policy: internal source/package history only; GitHub is not used.

## Fast Fetch Stability & Diagnostics Fix (FFSD)

- Continuous Refresh / Fast Fetch work-class telemetry records HTTP status, timeout, gateway failure, cache/lease failure, abort and observation rejection reasons.
- Observation delivery emits compact result events so a successful network fetch and a rejected observation are distinguishable.
- Controller errors clear after healthy planning/cycles and a liveness reschedule prevents an unexpected early return from leaving the worker wedged.
- Task Control separates canonical server checks due from browser-acquisition work claimable now.
- Task Control high-frequency/detail/activity/queue/tracking rendering is null-safe; missing optional DOM sections cannot crash the refresh path.
- Existing v2.9.15 throughput, OAuth pacing and P0 Interactive Survival limits are unchanged.

## UI v2 Deep-Link Routing Fix (UDR)

- UI-v2 Administration links explicitly carry `ui=v2`, preventing `ui-version-gate.js` from redirecting them into the classic UI.
- OAuth's default UI-v2 return path carries `ui=v2`.
- UI-v2 compatibility aliases retain other route dimensions rather than returning a partial navigation state.
- Administration deep-link state is not discarded while asynchronous administrator resolution is pending; denied access still falls back safely.

## Opponent Intelligence Coverage & Results Fix (OICR)

- Opponent profile W/D/L totals are aggregated over full history, not the bounded 200-row detail list.
- Result coverage is explicit: canonical results, missing finished results and coverage percentage are reported separately.
- `loss` maps explicitly to `losses`; dynamic pluralization can no longer create a `losss` bucket.
- Win-rate / profile classifications use matches with known canonical results as denominator.
- Analytics match facts can recover missing opponent/P2K strength points from canonical paired-board rating evidence, improving heatmap coverage without fabricating ratings.
- The Analytics logic watermark changes while retaining the historical canonical-outcome marker, causing the normal derived Analytics rebuild path to refresh affected materializations.
- Opponent Chess.com links are normalized to human `https://www.chess.com/club/<slug>` URLs at both persistence and read boundaries, repairing legacy PubAPI-form links without a database migration.

## Player Matches Archive Fallback Fix (PMAF)

- `/pub/player/{username}/matches` remains the primary authoritative discovery source.
- Fallback activates only for a current known-valid P2K member when that endpoint is specifically unusable: HTTP 404/410 or a structurally unusable matches payload.
- Retryable transport/gateway/429/5xx failures, and 401/403 authentication/authorization failures, never activate archive fan-out.
- Fallback uses `/pub/player/{username}/games/archives` and schedules at most six archive months per durable queue generation by default.
- A single bounded protected runtime ledger tracks fallback state, archive-index generations, pending/scheduled months, cooldown and primary re-probe time.
- Personal archives are filtered against already-known P2K match IDs. Unknown unrelated club matches are never expanded into `sync_match` work.
- When a known P2K match needs authoritative participation repair, PMAF queues normal `sync_match` verification and replays only the relevant month afterward.
- Primary `/matches` is re-probed after the cooldown and immediately retires fallback state when healthy.

## Compatibility

- Core schema remains 13.
- Analytics schema remains 6.
- Four-task external CRON cadence is unchanged.
- No production/private configuration is added to the release tree.
