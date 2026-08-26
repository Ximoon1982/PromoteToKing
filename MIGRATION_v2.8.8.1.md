# Promote to King v2.8.8.1 migration

## From v2.8.8

This is an in-place hotfix with **no schema migration**.

- Core remains schema **6**.
- Analytics remains schema **5**.
- Do **not** reset or re-seed either database.
- Preserve production `server/team-points/config/config.local.php`, `data/server-config.json`, generated runtime data, tournament archives and match-tracking data.
- The changed Analytics logic watermark automatically causes one normal Analytics rebuild, which repairs materialized Opponent Intelligence W/D/L totals from canonical finished-match summaries.

## Directly from v2.8.7

The additive v2.8.8 Core migration still applies: Core 5 → 6 adds the opponent club icon/profile cache columns. No reset/re-seed is required.

## CRON

The schedule is unchanged from v2.8.5 through v2.8.8.1. No new entry is needed.
