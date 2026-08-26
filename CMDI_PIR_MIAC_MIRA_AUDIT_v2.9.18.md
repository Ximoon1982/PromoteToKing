# Promote to King v2.9.18 — CMDI / PIR / MIAC / MIRA audit

## CMDI

Canonical worker execution is isolated from optional maintenance. `CronLoop` runs queue work only. `CronMaintenanceCoordinator` assigns each maintenance class a local slice with a database statement ceiling and retains a hard response reserve. Optional maintenance errors are diagnostic and do not rewrite a successful canonical worker outcome.

## PIR

PIR performs bounded integrity scans and stores durable issue identity. It checks canonical competition-point/result arithmetic, board/game coverage, point-event domains and complete-board P2K event totals. Repair is exclusively through forced authoritative queue work; PIR contains no canonical score/result/point overwrite path.

## MIAC

The supplied historical seed is immutable evidence. Heuristic edges remain candidates. Canonical components are formed only by administrator-confirmed edges or equal verified non-null Chess.com `player_id`. Conflicting player IDs are hard conflicts. Canonical-map generation changes only when identity topology/conflict resolution changes.

## MIRA

MCA normalized source rows preserve raw usernames. Attribution records retain raw-to-canonical explanation and generation. Aggregation uses MIAC canonical identities; event-level metrics deduplicate a canonical player within a source event. If the MIAC generation changes, stored MCA derivations are stale and are rebuilt transactionally from unchanged source evidence within the CMDI deadline.

## Weekly backup

The weekly task is deliberately separate from Team Points HTTP CRON so a large archive cannot consume canonical or CMDI maintenance deadlines. One archive is written directly under `_backup/` and the task is idempotent for the current ISO week.
