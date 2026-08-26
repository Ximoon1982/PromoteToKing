# Promote to King v2.10.6.19 — heatmap transport and provenance corrective

v2.10.6.19 corrects the historical Opponent Insights heatmap backfill introduced in v2.10.6.18.

## Historical rating provenance

Chess.com historical finished-match detail payloads do not expose player ratings. The backfill therefore no longer treats `/pub/match/{id}` as a sufficient heatmap source. Green compatibility now recovers paired board ratings from already-stored `p2k_g_games` game observations, using point-event and identity evidence to identify the P2K side across username aliases. Only board positions with both ratings participate in a match average, and `rated_board_count` is once again the number of paired boards rather than the number of individual rating values.

GABCRF now explicitly revisits finished matches whose compatibility rating metadata is still empty while Green already contains rated game evidence. This lets existing Green board/game history populate heatmaps without new Chess.com traffic.

## Accelerated residual backfill

The durable heatmap queue now uses `heatmap_board_detail` work and queues only known finished P2K board URLs that do not already have a Green game with both ratings. The obsolete v2.10.6.18 `heatmap_match_detail` ledger is retired automatically when the backfill is prepared.

Green Accelerator transport is corrected to:
- classify accelerator Chess.com work as background traffic so P0/admin requests retain priority;
- use a bounded 16-request logical feeder into the shared OAuth gateway instead of enqueuing an entire 48-item batch at once;
- disable JSONP fallback for accelerator work so an OAuth gateway failure cannot fan out into dozens of script-tag retries;
- preserve server-side OAuth rate coordination and retries;
- throttle live accelerator-log rendering and suppress repetitive per-request error spam after a small diagnostic sample.

Task Control now reports the actual OAuth gateway active/queued POST counts, gateway target/transport cap, and learned safe rate.

GAB external counters once again count only true GAB opponent-profile work; the independent heatmap ledger no longer appears as GAB external pending work.

No database reset/reseed, schema change, CRON schedule change, Blue/Green routing change, scoring change, or artwork change is required.
