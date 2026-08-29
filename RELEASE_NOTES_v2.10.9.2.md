# Promote to King v2.10.9.2

## MCA date recovery + canonical source integrity corrective

v2.10.9.2 is a corrective release on top of canonical v2.10.9.1. It fixes the remaining MCA date-backfill failures and also corrects a legacy source-integrity problem where browser/download copies such as `arena-123 (1).csv` and `arena-123 (2).csv` could be treated as separate MCA events.

### Date recovery fixes

- HTML date normalization replaces element boundaries with whitespace before collapsing text, so adjacent Chess.com metadata can no longer collapse into strings such as `playersAug`.
- Unicode non-breaking and narrow no-break spaces used around Chess.com times are normalized before date parsing.
- MCA index parsing recovers date evidence around each arena link even when `DOMDocument` is unavailable.
- Manual **Backfill missing dates** uses the same `McaArenaParser` / `McaIndexParser` path as the durable worker.
- When the arena page has no date, manual repair walks the paginated MCA index one page per step, preserving serial >=1-second request pacing.
- Existing `date_index` failures using the v2.10.9.1 terminal messages are automatically requeued after deployment.

### MCA source-integrity fixes

- A shared canonical source catalogue recognizes `arena-123.csv`, `arena-123 (1).csv`, `arena-123 (2).csv`, etc. as copies of the same Chess.com arena ID.
- Exactly one canonical source per recognized arena participates in MCA processing, player totals, arena counts, points, W/D/L, podiums, Top-10s and Arena Insights.
- Legacy duplicate bytes are **not deleted**. They remain visible in Admin for audit and are explicitly marked as excluded copies.
- Duplicate groups whose SHA-256 values differ are flagged as content conflicts rather than silently discarded.
- New manual uploads normalize browser-copy suffixes to the canonical arena identity and replace the existing canonical arena source instead of creating another active event.
- Historical seeding, manual date repair and durable acquisition all use the same canonical arena identity.
- The MCA worker detects derived arena rows that were built from excluded duplicate sources, marks a canonical rebuild required, and attempts that rebuild with the existing worker budget. The Admin **Process complete MCA dataset** action remains an unlimited manual rebuild path if needed.
- Admin now reports canonical MCA sources separately from retained CSV records, duplicate records/groups, conflicting groups and unidentified legacy sources.

### About the 559 local arenas vs 558 currently advertised

The release does **not** delete a historical local arena merely because the current Chess.com index advertises one fewer arena. A removed/unlisted historical arena can still be valid data. The corrected dashboard distinguishes local acquisition arenas from canonical stored sources so this one-arena external-index discrepancy can be audited independently without corrupting history.

### Preserved behavior

- No database schema change; Analytics schema remains 10.
- No destructive runtime-data cleanup. Duplicate source files are retained.
- No CRON definition change; the existing v2.10.9 every-minute MCA scheduler remains valid.
- Pairings/game acquisition semantics are unchanged.
