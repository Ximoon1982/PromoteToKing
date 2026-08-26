# Promote to King v2.9.18 migration

v2.9.18 performs additive migrations only.

## Core 13 → 14

Adds MIAC identity state/names/evidence/canonical-map storage, authoritative `player_id` support needed for identity confirmation, and PIR state/issues. Existing members, matches, boards, games and point events remain canonical and are not recreated.

## Analytics 6 → 7

Adds normalized MCA source-row evidence, MIRA per-row attribution, canonical-identity/generation fields and rebuild state. Existing uploaded MCA source files remain the immutable source of truth for derivation.

## MIAC seed

`resources/miac/seed.zip` and `resources/miac/miac-seed.json` are release resources. The install/CRON setup copies them into protected `data/miac/seed/`. Seed import is idempotent. Candidate rename hypotheses do not automatically merge identities.

## PIR authority boundary

PIR does not alter canonical scores/results/points based on arithmetic or CSV evidence. It records an issue and requests authoritative Chess.com match/board revalidation.

## CRON

The four operational schedules retain their existing cadence. A fifth managed entry runs the weekly long-life backup. CMDI isolates optional maintenance inside each Club/Player HTTP invocation; it does not require extra frequent maintenance CRON entries.

No database reset, destructive migration or manual CSV reimport is required.
