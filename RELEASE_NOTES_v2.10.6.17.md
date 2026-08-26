# Promote to King v2.10.6.17

## Scope

Focused Green compatibility reconciliation corrective based on the finalized v2.10.6.16 source tree.

### GABCRF game-sequence canonicalization

Historical username aliases can legitimately leave multiple `p2k_g_point_events` rows for the same Green game. After identity canonicalization, more than one of those rows can belong to the same current player. The compatibility projector previously joined all matching point-event aliases directly to `p2k_g_games`, so one authoritative Green game could be inserted twice with the same compatibility `(board_id, sequence_no)`, raising MariaDB error 1062 on `uq_tp_game_board_sequence`.

v2.10.6.17 adds a deterministic game projection layer. Exactly one compatibility game is emitted per authoritative board sequence, preferring the exact canonical username event, then trusted identity evidence, then the newest deterministic source row. Duplicate alias/stale rows are counted as `game_duplicates_resolved` rather than inserted twice.

### Reconciliation completeness

GABCRF candidate and remaining-drift checks now compare canonical Green game-sequence counts with compatibility game counts in addition to board counts and timestamps. Public compatibility parity likewise counts canonical Green game sequences rather than raw alias-expanded point-event joins.

This means historical alias duplication can no longer both crash projection and inflate the parity denominator.

## Recovery

No Green data reset or reseed is required. After installing v2.10.6.17, use **Start / resume GAB**. Only the errored lane is reset to pending; completed GAB lanes remain completed.

## Safety

- No database schema change.
- No database reset or reseed.
- No CRON cadence change.
- No Blue/Green routing change.
- No scoring change.
- No artwork/image change.
- v2.10.6.16 Green cycle-boundary/runtime protections are preserved unchanged.
