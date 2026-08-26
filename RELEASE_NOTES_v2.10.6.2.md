# Promote to King v2.10.6.2

Cumulative MCA source-sync corrective release. This package may be applied directly to either v2.10.6 or v2.10.6.1.

## MCA source acquisition corrective

- Removes the undocumented `arena URL + .csv` assumption introduced in v2.10.6.
- Reads the arena page first and uses an actual Chess.com CSV/export URL only when the page exposes one.
- When no export URL is exposed, reconstructs a Results-compatible CSV from the complete Player Results table.
- The page fallback is accepted only when the number of parsed player rows exactly matches the arena's advertised player count; partial/paginated source data is refused.
- Existing source-request pacing remains strictly serial with at least one second between source requests.

## Actionable source-sync errors

- The MCA Source Data panel now shows each failed arena, failed stage, attempt count, exact stored error and last-attempt time.
- Existing v2.10.6 error rows are surfaced immediately after upgrade; they do not need to be reproduced.
- Adds **Retry failed events**, which resets only failed entries to the arena-page stage and preserves already completed events.

## MCA Blue -> Green synchronization

- Adds **Sync MCA Blue → Green** to Administration Tools → MCA source data.
- Copies only the eight `p2k_lr_*` MCA source/derived tables from Blue Analytics to Green Analytics.
- Verifies every Blue stored MCA source file against its recorded SHA-256 before Green is touched.
- Refuses schema mismatch or accidental Blue/Green database identity collisions.
- Replaces the Green MCA snapshot inside a transaction and validates every destination row count before commit.
- Does not reset, reseed, or alter non-MCA Green data.

## Compatibility

- No database schema migration is required.
- No source files are removed.
- Includes the v2.10.6.1 dashboard-CSRF hotfix when installed directly over v2.10.6.
