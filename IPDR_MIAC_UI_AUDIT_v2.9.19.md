# Promote to King v2.9.19 — IPDR / MIAC / UI audit

## MIAC review and evidence

- POC-style identity chain explorer and transition timeline use the seed topology with live database statuses overlaid.
- Full evidence set is exposed to the UI; manual queue contains unresolved candidate/no-shared-board cases.
- Shared-board candidate edges automatically confirm unless explicitly rejected or blocked by contradictory non-null player IDs.
- Manual review is secured and idempotent.

## Definitive historical evidence

### MCA

The same arena source replacement is eligible only when:
1. exactly one old username disappears;
2. exactly one new username appears;
3. the stable participation fingerprint of old/new matches;
4. every unchanged participant has an unchanged stable fingerprint;
5. the comparison evidence/source hashes are persisted before replacement;
6. there is no explicit rejection or contradictory known player ID.

### Daily boards

The same physical `match_id + board_no` is eligible only for a clean one-to-one owner substitution. Neither old nor new username may own another board in that match. Evidence is persisted before current physical-board ownership changes. Explicit rejection/player-ID conflict guards remain in force.

## Derived data

MIAC generation is a source dependency for Analytics, Achievements and MIRA. Rebuildable derived facts canonicalize confirmed aliases while raw Core evidence remains historical and immutable with respect to identity attribution changes.

## UI acceptance

- Current-member achievement popularity ranking/counts.
- Unearned progress plus complete-catalog Previous/Next/keyboard navigation.
- Dynamic player achievement action text and spacing.
- Dashboard achievement typography/link.
- Members summary metric cleanup.
- Current-only match state does not create a history graph.
- Framed Live Rook artwork remains contained at mobile width.
- Admin priority/health rows align on desktop.
