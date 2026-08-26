# Promote to King v2.8.8.3 Dashboard rollback hotfix

This hotfix responds to production confirmation that the Dashboard data-loading regression first appeared in **v2.8.7**.

## Deliberate rollback

The public Dashboard data path is restored to the proven **v2.8.6 implementation**:

- `loadTeamData()` again waits for the same-origin Team Points summary, then automatically starts the Chess.com members/profile/matches and Live second wave after first paint.
- Current `registered`, `ongoing` and bounded recent `finished` match cards are refreshed exactly as in v2.8.6.
- `Repository::publicClubDashboard()` again uses the v2.8.6 materialized Analytics total row, projected current-member count and scored-finished-match count query.
- The normal Chess.com API client observation path is restored to its pre-ACAMR v2.8.6 behavior.

This deliberately supersedes the Dashboard startup/data-source experiments introduced in v2.8.7, v2.8.8.1 and v2.8.8.2. Later independent features and correctness fixes remain present.

## ACAMR disabled

Authenticated Client-Assisted Member Refresh is fully disabled for this hotfix:

- no public page loads `authenticated-member-refresh.js`;
- the ACAMR planner returns `enabled=false` and issues no claims;
- stale cached pre-hotfix ACAMR observations are rejected with `acamr_disabled`;
- the compatibility client file remains only as an inert stub;
- Club/Player CRON workers remain the sole refresh mechanism.

Dormant ACAMR configuration/code is retained so a future reimplementation can be developed separately without affecting this rollback.

## Retained v2.8.8.x fixes

The hotfix retains the Hall/Profile/mobile/modal fixes, immediate configured-admin recognition and Administration iframe resilience, Opponent Intelligence loss correction, opponent-logo cache, Club Intelligence features other than active ACAMR, and the Team Insights six-month Club Points progression forecast.

## Operations

- Core schema: **6**.
- Analytics schema: **5**.
- No database reset, reseed or SQL migration from v2.8.8.x.
- CRON cadence is unchanged.
