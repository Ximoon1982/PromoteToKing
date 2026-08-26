# Promote to King v2.9.7

v2.9.7 is a focused corrective release rebuilt directly from the production-verified v2.9.5 baseline. The discarded v2.9.6 tree is not used as a source.

## Player worker convergence fixes

- Replaces absolute Player-lane type starvation with bounded fair scheduling. When both are due, `sync_player` and `sync_player_stats`/`sync_player_profile` receive guaranteed turns; archive/operational work retains a protected share and reconciliation remains cheap/high priority.
- Prevents a completed authoritative `/player/{username}/matches` scan from being committed as `done` unless `player_matches_checked_at` was persisted successfully.
- Writes Player Matches freshness immediately when the final authoritative slice finishes, before optional board rediscovery and independent of remaining worker-processing budget.
- Skips stale duplicate queue rows when the corresponding Player Matches, Stats or Profile freshness is already current, avoiding unnecessary Chess.com calls and allowing old backlog to drain without a queue reset.
- Keeps existing durable queue data; no destructive repair/reset/reseed is performed.

## Task Control and diagnostics

- Lane-specific Member Points details now use the fully decorated job object, so real per-type queue counts are shown instead of false `0 / 0` values.
- Queue tables distinguish Total, Pending, Running, Retry, Done, Skipped and Failed.
- Member Points details expose last start and last success for `reconcile_members`, `sync_player`, `sync_player_stats` and `sync_player_archive`.
- Runtime Diagnostics uses per-request timeouts and `Promise.allSettled()`, so one stalled diagnostic endpoint cannot leave the panel on “Collecting runtime diagnostics…” forever.
- CRON execution budget is tightened to a 30-second Player/combined worker segment and a 42-second PHP endpoint ceiling while the shell cURL watchdog stays at 55 seconds.

## OAuth proof of concept

- Keeps real Chess.com OAuth testing isolated at `OAuthTest.php?oauth=2`; production simulated OAuth (`?oauth=1`) is unchanged.
- Adds protected host configuration at `server/team-points/config/oauth.local.php`.
- Release archives never contain `oauth.local.php`; future updates preserve it like `config.local.php` and server tokens.
- Adds `install-oauth-poc-v2.9.7.sh`, which creates the protected file once, refuses to overwrite an existing one, and applies mode `0600`.
- This remains a proof of concept and is not wired into production authorization.

## UI fixes

- Restores the complete Live Rook artwork presentation: full artwork uses `object-fit: contain`, and the achievement Rook uses new v2.9.7-named artwork so stale browser caches cannot retain a clipped derivative.
- A player's unfolded achievement-catalogue group header now shows achieved/total progress plus a progress bar.

## Compatibility

- Core DB schema remains **10**.
- Analytics DB schema remains **6**.
- Achievement catalogue remains **162** definitions.
- No destructive DB migration.
- v2.9.5 is the only supported incremental source for this package.

### v2.9.5 false-completion recovery

v2.9.7 does not reset or rewrite the durable queue. On Player-worker startup it detects current members whose earlier `sync_player` row is already `done` while `player_matches_checked_at` is still NULL and no Player continuation is active. It enqueues a distinct priority `sync_player` repair row. Existing historical queue rows remain intact for diagnostics.

