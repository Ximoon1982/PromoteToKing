# Promote to King v2.9.22.7

Focused Fresh Points reconciliation hotfix from the exact frozen v2.9.22.6 baseline.

## Fixes

- Fixes PHP by-reference cleanup that replaced the last Club/Player reconciliation row with `null`, causing Task Control to throw `Cannot read properties of null (reading 'local_status')` and hide otherwise actionable differences.
- Adds defensive browser filtering so a malformed reconciliation row cannot blank an entire difference/action table.
- Makes the existing network-free Club reclassification action visible as **Reclassify staged Club data**.
- Treats Chess.com `0-0` draw team matches as cancelled/void and **0-0 excluded**, even if the match has zero/missing/unbalanced registered boards. These are no longer payload issues.
- Ignores board-count-only differences for already-excluded cancelled 0-0 matches.
- Reports the true acquisition-issue total independently of the 500-row issue detail window.

No Core/Analytics schema migration, CRON, OAuth, ACDM or scoring-formula change.
Core remains 16; Analytics remains 7.
