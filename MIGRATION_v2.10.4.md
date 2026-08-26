# Promote to King v2.10.4 migration notes

## Analytics schema 7 → 8

The migration is additive. `p2k_lr_files` receives nullable/derived MCA event-date provenance fields:
- `actual_event_date`
- `effective_event_date`
- `event_date_precision` (`known`, `interpolated`, `upload-fallback`)
- `event_date_updated_at`

Existing rows initialize their effective date from upload date when no actual date is known. Later actual-date edits recompute neighboring effective dates dynamically by arena ID and invalidate the achievement refresh watermark.

No Core or Green table is dropped/truncated/reseeded.

## Achievement rebuild behavior

MCA-derived achievement unlocks are rebuildable Analytics projections. v2.10.4 reconstructs their crossing dates chronologically by arena using the effective event-date provenance. Approximate provenance remains explicitly marked rather than presented as exact.

## Authentication boundary

The Team Points administrator session is no longer bootstrapped from a browser-posted username. It is derived from the real server-side Chess.com OAuth session and then independently checked against the current club administrators before `P2KTPSESSID` is created.

## Green GQAC / GSCF

GSCF uses one Green-only CRON feeder at minute 2 and 32 of each hour. The feeder has an 18-second soft target and 24-second hard worker budget; the existing `p2k_green_worker` database lease prevents overlap. Blue CRON entries are not modified.

GQAC adds two small Green accounting tables lazily and idempotently on the first `quick_boards` pass:
- `p2k_g_quick_board_cycles`
- `p2k_g_quick_board_cycle_items`

The current quick-board workload is snapshotted once. New board debt discovered after that snapshot is intentionally carried into the next quick cycle. Terminal 200/404/410 observations retire the current-cycle item; newer hints preserve `needs_refresh=1` for next-cycle convergence. No Green data reset or reseed is performed.


## Matches / Team Points artwork

v2.10.4 intentionally retains the seven existing SVG placeholders for First Match, First Step, Rising Star, Clutch Player, Great Strategist, Match Veteran and Match Legend. The exact recovered Aug-6 source filenames and SHA-256 values remain recorded in `ARTWORK_PROVENANCE_v2.10.4.json` for a later artwork-only integration.
