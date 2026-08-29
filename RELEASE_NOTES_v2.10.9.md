# Promote to King v2.10.9

Baseline: v2.10.8

## Scope

v2.10.9 replaces the narrow twice-daily MCA Results intake with durable full-arena acquisition while preserving all existing MCA source files and the current MIRA/Live Ranks processing pipeline.

### Discovery

- Uses the server-readable Chess.com Promote to King live-tournaments index: `https://www.chess.com/club/live-tournaments/promote-to-king?type=multi`.
- Discovery remains due-gated to every 12 hours and starts from page 1.
- Arena IDs remain the monotonic discovery boundary; older index pages are followed only until the known high-water arena is reached.
- New arenas are inserted into the durable acquisition queue with higher priority than historical backfill.
- Index date/time is retained as a valid fallback source.

### Historical backfill

- Existing stored MCA Results sources are seeded once into the new durable arena queue.
- Existing stored Results CSV files are never re-downloaded merely because the new arena tables are empty.
- Historical arenas therefore enter v2.10.9 with their current Results source intact but Pairings/game acquisition pending.
- Every component is resumable independently; a long arena resumes at its next Club, Player, Pairings, or index-date page rather than restarting.

### Club and player performance

- The Results CSV is the primary source.
- `McaArenaParser` understands the consecutive Player Results and Club Results tables in one Chess.com Results CSV, separated by blank rows.
- Existing stored CSV files are parsed locally first.
- For a newly discovered arena, the explicit `download-results` link from the arena HTML is preferred; the deterministic URL remains a fallback.
- If Results CSV retrieval or parsing cannot provide Club Results and/or Player Results, only the missing table is acquired from the corresponding paginated arena HTML.
- Player HTML fallback captures rank, username, rating, club, score, wins, draws, losses, byes and streak where Chess.com exposes them.
- When no usable Results CSV exists for a new arena, the recovered Player Results are also materialized as a compatible local fallback source so the existing Live Ranks/MIRA pipeline remains usable.

### Games

- Game-level data always comes from the arena Pairings pages.
- The worker records Chess.com game ID/link, White/Black usernames, both displayed ratings, result, and source Pairings page.
- Pairings pagination is driven by the explicit page count exposed in the arena HTML.
- Games are upserted by `(club_slug, arena_id, game_id)`, so replaying a page is safe.

### Dates

- Arena-page date/time is authoritative when available.
- The index timestamp is the fallback.
- Index fallback is itself durable and page-resumable for older arenas.
- Existing manual historical date repair remains available for exceptional unresolved sources.

### Scheduling and request safety

- The acquisition worker runs every minute with a requested maximum slice of 55 seconds.
- Discovery is still internally due-gated to 12 hours; most minute runs therefore spend their duty cycle draining the arena backlog.
- All Chess.com requests remain strictly serial with at least one second between request starts.
- Request transport timeout is bounded by the remaining worker slice so a late slow request cannot freely extend the cron duty cycle.
- One global DB advisory lock prevents overlapping MCA workers.
- The CRON installer removes only the prior MCA Results/MCA Arena block and preserves unrelated crontab entries.

## Database

Analytics schema advances from 9 to 10. New tables:

- `p2k_lr_arena_acquisition`
- `p2k_lr_arena_clubs`
- `p2k_lr_arena_players`
- `p2k_lr_arena_games`

The migration also adds the one-time historical-seed marker to `p2k_lr_sync_state`.

No existing MCA source file, runtime configuration, OAuth/session data, or unrelated scheduled task is reset.

## Administration

Admin → MCA source data now reports durable arena backlog, remaining arenas, stored games, stored club/player rows, acquisition errors, and the next index-discovery time. Manual discovery/acquisition and explicit failed-step retry remain available.
