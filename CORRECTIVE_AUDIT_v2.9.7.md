# Corrective audit — v2.9.7

## Production evidence from v2.9.5

The live Player queue showed 4,411 original `sync_player` items pending behind 3,612 `sync_player_stats` plus 65 `sync_player_profile` items while Player Matches freshness remained 0.0% and Stats freshness continued increasing. Queue selection sorted by item-type priority before age, confirming deterministic starvation.

A second live query found 62 completed original `sync_player` items corresponding to 62 current members; all 62 still had `player_matches_checked_at IS NULL`. The v2.9.5 source gated the final freshness write behind remaining processing budget, allowing a completed queue slice to become `done` without the authoritative freshness commit.

## v2.9.7 invariants

- Fair Player scheduling: Matches and Stats/Profile cannot starve one another while both are runnable.
- Final authoritative Player Matches completion writes freshness before optional maintenance.
- If the freshness write fails, the task throws/retries instead of becoming `done`.
- Already-current recurring Player/Stats/Profile rows are committed as `skipped` before outbound API access.
- Task Control lane details always use a decorated job with real queue and task breakdown.
- Existing durable queue is preserved; no destructive reset is needed.

## Release safety

v2.9.7 is rebuilt from the exact verified v2.9.5 standalone tree. The v2.9.6 work tree is excluded from the release lineage. Packaging validates the complete delta and protects host-specific configuration/data/log paths.

### v2.9.5 false-completion recovery

v2.9.7 does not reset or rewrite the durable queue. On Player-worker startup it detects current members whose earlier `sync_player` row is already `done` while `player_matches_checked_at` is still NULL and no Player continuation is active. It enqueues a distinct priority `sync_player` repair row. Existing historical queue rows remain intact for diagnostics.

