# Promote to King v2.7.2

## Database-correct Insights

This release replaces the remaining ingestion-time approximations in Team Insights with authoritative database dates:

- match starts use `p2k_tp_match_metadata.start_time`;
- match finishes use `p2k_tp_match_metadata.end_time`;
- game/player activity uses `p2k_tp_point_events.game_end_utc`;
- `participations.first_seen_at` and `match_summaries.finalized_at` are no longer used as chess-event dates.

Schema 13 adds a durable daily fact table (`p2k_tp_insight_daily`) and its refresh state (`p2k_tp_insight_cache_state`). Facts are rebuilt only when source tables change. User-selected date ranges, exact unique-player counts, rolling windows, outcomes and distributions remain live database calculations.

## Team Insights

- Correct cumulative started/finished activity with in-progress matches on an independent scale.
- Complete calendar series, including zero-activity days.
- Rolling activity uses 30 actual calendar days rather than 30 non-empty rows.
- Daily boards now displays boards started, boards finished and Club Points.
- Year-over-year lines stop at the last available date instead of plotting missing future data.
- Relevant legends, hover details, drag-to-zoom selection and reset controls.
- Client-side point dropping was removed; long periods are rendered from complete database series.

## Match Insights

- Match-size share pie and W/D/L distribution.
- League/friendly share pie with explicit wins / draws / losses.
- Corrected average and median duration fields.
- Duration calculations exclude void 0–0 matches and invalid/non-positive timestamp ranges.
- Duration trend hover shows valid-match count and monthly minimum/maximum.
- Duration distribution.
- Standard/Chess960 distribution.
- Human-readable daily time controls such as `3 days / move`.
- Stored `rules`, `time_control` and `is_league` dimensions in match metadata and seed staging.
- Match profiles expose type, time control and league/friendly category.

## Member Insights

- Monthly active-member chart from distinct players with completed games.
- Hover includes monthly games and Team Points without mixing those larger values onto the active-member scale.
- Daily-rank distribution from database Team Points.

## Opponent Insights

- Compact fixed-layout desktop table; horizontal scrolling remains available below desktop width.
- Most-played-opponents treemap with match volume and W/D/L/ongoing details.
- Administrator-only plain-text copy action in opponent intelligence:

  `Promote to King vs TeamX: 10w / 5d / 2l, 3 ongoing.`

## Schema and importer

- Team Points schema: **13**.
- Seed Importer: **v1.2.0**.
- The embedded snapshot remains 4,473 members, 17,091 matches, 104,761 boards, 198,107 game events and 371,168 Club Points.
- The importer additionally retains 16,586 Standard matches, 504 Chess960 matches, seven known daily time controls and 1,139 league-tagged matches.
- The administrator token is shown in clear with length and SHA-256 fingerprint before signing, matching the diagnostic behavior requested during v2.7.1 deployment.

## CRON

The CRON endpoints and cadence are unchanged from v2.7.1. Existing working v2.7.1 CRON wrapper entries do not need to be replaced.
