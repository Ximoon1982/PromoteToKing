# Promote to King v2.10.6.18

## Scope

Cumulative Green compatibility and Opponent Insights historical-coverage corrective based on v2.10.6.17.

### Mutable registration lineup correctness

Chess.com daily team-match registration lineups are mutable until the match starts. A player may move from one board to another as registrations change, and a username alias may also change between observations. `p2k_g_match_players` is now treated explicitly as the **current authoritative lineup snapshot**, not as lineup history.

When an authoritative match-detail payload contains a non-empty side lineup, Green retires rows for that side that are no longer present before upserting the latest players. Games and point events are preserved as immutable/historical evidence.

The compatibility projector also adds a defensive global canonical-member assignment: one canonical P2K member can produce at most one compatibility board row per match. Mutable registration/in-progress rows prefer the newest authoritative match observation; finished rows continue to prefer game/board evidence. GABCRF drift/parity board counts use canonical member-match counts rather than raw historical board positions.

This corrects `uq_tp_board_member_match` failures such as duplicate member/match rows caused by registration board movement.

### Accelerated historical heatmap backfill

Opponent heatmaps require paired-board rating provenance. Quick mode does not perform a complete historical discovery/refresh, so historical coverage could remain frozen indefinitely.

v2.10.6.18 adds Administration → Green Team Points → **Historical heatmap backfill**. It queues only already-known, finished, non-void P2K daily matches whose compatibility metadata is still missing paired ratings. No Deep ID scan is used.

The Green Accelerator consumes this queue as a dedicated `HEATMAP` browser lane, after genuine external GAB work and before GFFL bulk debt, while the existing finite-cycle reserve protects Quick-cycle progress. Successful match-detail observations update Green, project compatibility immediately and mark the heatmap work item completed. Transient/terminal failures are tracked independently from GAB.

Task Control exposes paired-rating coverage plus queue pending/completed/failed counts. Opponent Insights also replaces the old six-hour browser heatmap snapshot with a five-minute cache generation so backfilled data becomes visible promptly after compatibility analytics refresh.

## Safety

- No database schema version change.
- No database reset or reseed.
- No CRON definition/cadence change.
- No Blue/Green routing change.
- No scoring change.
- No artwork/image change.
- v2.10.6.16 cycle/runtime protections and v2.10.6.17 canonical game-sequence projection are preserved.
