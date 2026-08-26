# Promote to King v2.9.0 release notes

v2.9.0 is based on the verified v2.8.11 production baseline and focuses on correctness, observability, achievement expansion, privacy-preserving audience measurement, and chart usability.

## Highlights

- **Achievement expansion**: same-day match starts (2/3/4/5), reconstructed concurrent team games (5/10/25/50/100), and achievement-group breadth (5/10/15/all eligible groups).
- **Achievement artwork safety**: local per-achievement placeholders for broken/missing/unapproved artwork plus hard fallback; existing artwork is not silently replaced by recovered chat-history art without approval.
- **Achievement leaderboard**: Highlights/Leaderboard view with achievement total, Daily Rank and Live Rank, visible-page avatar loading, and profile navigation.
- **Clickable challenges**: dashboard/profile challenge rows open the relevant player achievement catalogue focused on the achievement.
- **Club Intelligence Traffic / Visitors**: self-hosted cookieless first-party page/session/navigation/geography/duration analytics; no Statcounter/third-party audience tracker.
- **Club Intelligence tables**: sortable header rows with type-aware ascending/descending sorting.
- **Runtime Diagnostics**: package/site/manifest/model/schema/backend/CRON/context/asset version visibility, mismatch warnings, and sanitized copy snapshot.
- **Charts**: shared live-DOM maximize/restore, improved readability, Daily-rank and board-size bar values, board-size mean/median, Club Points monthly Today/future treatment, and cumulative activity ending at Today.
- **Live Rook**: safe bottom canvas margin and regenerated 640/192 derivatives.
- **Member Points IONOS safety**: `sync_player` history is processed in bounded retry-safe cached slices and outbound budgeting includes shared gateway lock acquisition time. The external 55-second watchdog is retained.
- **Core → Analytics convergence**: generation changes bypass stale minimum-interval throttling so a newly-finished match gets a bounded refresh opportunity.
- **Historical Club Points revalidation**: supported resumable/idempotent `sync_match` seeding utility at 25 historical matches per five-minute slot, below fresh/current Club work priority.
- **CRON setup**: v2.9.0 dispatcher verifies real PHP CLI; installer repairs missing shared Tournament/Match-Tracking token from protected Team Points configuration and initializes an independent traffic analytics HMAC secret.

No destructive database reset or re-seed is required. Existing void 0–0 match exclusion rules remain authoritative.
