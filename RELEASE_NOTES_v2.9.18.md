# Promote to King v2.9.18

## Scope

v2.9.18 is built directly from the exact frozen internal v2.9.17 standalone SHA256 `b3153b30b74855fca8e6598fff607642afea8101ac412a83a6effeaccd2b6763`, using internal source/package history only. GitHub is not used.

## Achievement artwork integrity

- Integrates **43 approved new 640×640 achievement masters** from the supplied Achievement Collector/Rivalries, MCA Streaks/Event Victories, MCA Participation/Placement, MCA Wins-in-one-arena/Same-day-starts, and Legacy/Distinct-group-breadth packs.
- Each installed master has a matching **128×128 WebP thumbnail** and **64×64 WebP miniature**, including the compatibility `mini/<key>.webp` alias.
- Rebuilds the derivatives for **all 35 league-specific achievements** (1WL, PCL, TCMAC, TMCL, KOTML) from the currently canonical master. This removes stale historical miniatures/thumbnails even where the master was already correct.
- The release regression verifies derivative pixel lineage from the canonical master, not merely file existence.

## CRON Maintenance Deadline Isolation Fix (CMDI)

- Canonical Club/Player queue work owns the primary invocation deadline and executes first.
- Optional Analytics, intelligence, PIR, achievement, MIRA, housekeeping and storage maintenance is moved behind `CronMaintenanceCoordinator`.
- Every optional maintenance class receives an independent local wall-clock slice, MariaDB statement-time ceiling and a hard return reserve.
- Maintenance exceptions/timeouts are reported independently and cannot turn successfully completed canonical worker work into a failed CRON result.
- Housekeeping/storage operations stop between bounded phases when their local deadline is exhausted.

## Point Integrity & Reconciliation Fix (PIR)

- Adds bounded, cursor-based integrity checks over canonical finished-match data.
- Detects result/competition-point arithmetic mismatches, board-count/finished-game coverage problems, invalid point-event values and complete-board member-event totals inconsistent with the stored P2K team score.
- Findings are durable and idempotent; resolved findings are closed rather than duplicated.
- PIR **never locally rewrites canonical scores, match results or point events**. It queues forced authoritative `sync_match` / `sync_board` verification for repair.

## Member Identity & Alias Chain Fix (MIAC)

- Adds an additive Core identity graph seeded from the supplied historical `seed.zip`.
- Packaged seed provenance SHA256: `fbb0ad58132a65836e2a2c8c10f9f952dc727f41a668671cba69dc822f1b4310`.
- Seed covers 6,956 historical usernames, 429 candidate rename edges and 292 probable chains while retaining board-only and former-roster identities.
- Heuristic seed edges remain **candidate/reviewable** and are not automatically merged.
- Matching verified non-null Chess.com `player_id` or an administrator-confirmed edge can establish canonical identity linkage.
- Contradictory non-null `player_id` values create a **hard conflict** and block automatic merge.
- Identity topology/conflict changes increment an identity-map generation; ordinary unique `player_id` hydration does not.
- Club Intelligence adds an **Aliases & name changes** view with evidence/status and confirm/reject review actions.
- `install-miac-seed-v2.9.18.sh` copies immutable seed inputs into protected `data/miac/seed/` without overwriting runtime review/confirmation state.

## MCA Identity Resolution & Attribution Fix (MIRA)

- MCA source CSV rows/usernames are retained as immutable normalized source evidence.
- Every source row is attributed to a MIAC canonical identity with file, row number, raw username, canonical username, resolution reason, identity generation and conflict state.
- Derived MCA points, participation, W/D/L, streaks, placements, victories, podiums, Top-10s, live ranks and MCA achievement inputs aggregate across confirmed aliases.
- Distinct-event metrics are deduplicated by source event/canonical identity so two aliases in one event cannot double-count participation or placement/victory outcomes.
- MIRA stores the MIAC identity-map generation used for derivation. A topology change makes MCA derived data stale and triggers a bounded rebuild from unchanged stored source rows.
- Rebuilds are CMDI-deadline-aware and roll back rather than committing a partial attribution generation.

## Weekly long-life backup

- Adds one weekly CRON entry at **Sunday 03:37** server time/cron environment.
- Creates a single archive directly in `_backup/` named `PromoteToKing_LongLife_<ISO-year>-W<week>.tar.gz`.
- Covers generated long-life filesystem state including tournaments, live ranks/arenas, match tracking, traffic aggregates, MIAC data, archive history, intelligence/reconciliation snapshots and logs when those roots exist.
- Excludes rebuildable caches/locks, nested backups and the large immutable MIAC source ZIP from each weekly archive.
- Default retention is the newest 52 weekly archives and is configurable through `P2K_WEEKLY_BACKUP_KEEP`.

## Database / scheduling compatibility

- Core schema: **14** (additive upgrade from 13).
- Analytics schema: **7** (additive upgrade from 6).
- Achievement catalogue: **162 definitions** (unchanged; approved artwork replaces placeholders/assets).
- Existing four operational cadences are unchanged: Club 5 min; Tournaments 10 min offset 2; Player 10 min offset 4; Match Tracking hourly minute 17.
- One additional weekly long-life backup entry is installed.
- OAuth tuning namespace and v2.9.17 ACDC/CTAR/P0 Interactive Survival behavior remain intact.
- No destructive reset or reseed.
