# Migration to Promote to King v2.10.6.15

Source baseline: **v2.10.6.14**.

This is an application/UI corrective only. No database migration, reset, reseed, CRON edit, scoring change, or Green/Blue data-routing change is required.

After installing, hard-refresh `ui-v2.html` once so the v2.10.6.15 dashboard/admin assets are loaded.

## Acceptance checks

1. Open Admin → Admin & maintenance → Scheduled Task Control.
2. The parent detail offers **Task Control** and **Production migration**.
3. Inside Task Control, verify the native **Scheduled tasks** and **Green Team Points** tabs are visible.
4. Open **Green Team Points** and verify GAB, GFFL, Green Accelerator, cycle/phase and recent invocation sections are present.
5. Refresh the page: Green remains selected and the URL contains `adminToolTab=green`.
6. Use Back/Forward between Scheduled tasks and Green Team Points.
7. Open Misc → Lost & found → Scheduled Task Control; switch to Green and refresh. Green remains reachable.
8. Existing legacy `?page=administration&adminTool=tasks&adminToolTab=green` URLs restore Green directly.
