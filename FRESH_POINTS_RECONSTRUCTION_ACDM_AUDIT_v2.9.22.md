# v2.9.22 Fresh Points Reconstruction / ACDM audit

## Authority boundary

Fresh reconstruction uses existing Core facts only as discovery hints. Network-acquired reconstruction facts are stored in separate Core-15 staging tables. No canonical fact is changed during scanning. Explicit administrator approval is required and the apply operation takes the normal Club/Player worker advisory lane lock.

## Reconstruction checkpoints and observability

The run row stores overall/Club/Player progress, current phase, timestamps and client metric snapshots. Dedicated staging tables retain discovered/resolved match, member, board and game work. Task Control displays per-phase found/pending/processed/issues/progress counters and global match/member/board/game/API metrics.

## Apply semantics

Club approval promotes reconstructed match status/board counts/scores and recalculates Club Points, including explicit zero-point treatment for finished 0-0 void records. Player approval rebuilds the reconstructed closing-roster members' board/game history and Player Points from staged results. Analytics is rebuilt after canonical promotion.

Queue cleanup is scoped: work conclusively satisfied by the applied reconstruction is marked skipped/superseded with the reconstruction run ID. Unresolved, active, unrelated profile/stat and other maintenance work is retained.

## ACDM correction

The v2.9.20 telemetry showed that a healthy ~25 calls/s OAuth browser transport did not translate into comparable canonical queue drain: Club CRON was capped at 25 items per five-minute run and browser ACDM disappeared while Android suspended the page. v2.9.22 moves the principal correction into server worker policy: Club has 100-item capacity and a 34-second execution floor; browser ACDM adapts up to 64 items/pulse when awake. P0 foreground protection and shared OAuth adaptive pacing remain unchanged.
