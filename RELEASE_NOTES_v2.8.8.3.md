# Promote to King v2.8.8.3

## Dashboard rollback

Production testing confirmed the Dashboard data regression began in v2.8.7. v2.8.8.3 therefore restores the v2.8.6 Dashboard data-loading contract instead of adding further timeout/retry layers.

- v2.8.6 `loadTeamData()` sequence restored.
- v2.8.6 `publicClubDashboard()` sequence restored.
- pre-ACAMR normal API-client observation behavior restored.
- active asset URLs cache-busted as v2.8.8.3.

## ACAMR

ACAMR is disabled in this release. It is not loaded by public/admin pages, the planner issues no claims, and stale ACAMR observations are ignored. Server Club and Player workers/CRON remain authoritative and unchanged.

## Retained functionality

All independent v2.8.8/v2.8.8.1 functionality remains, including Hall/Profile/mobile/modal corrections, Administration resilience, Opponent Intelligence loss correction, opponent logos, Club Intelligence tools, member/recruitment intelligence, and the Team Insights first-day-to-today-plus-six-month Club Points projection.

## Compatibility

Core 6 / Analytics 5. No DB reset or SQL migration. No CRON cadence change.
