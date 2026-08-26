# Promote to King v2.8.4 installation / upgrade

For an upgrade from v2.8.3, follow `MIGRATION_v2.8.4.md` first.

The important operational change is the split Team Points schedule described in `CRON_SETUP_v2.8.4.md`.

## Database

v2.8.4 does not require a schema bump: Core 4 / Analytics 5. Keep the existing production databases and `server/team-points/config/config.local.php`.

## Recommended verification

- Load the dashboard and confirm normal authentication/data loading.
- Open Scheduled Tasks and confirm Club Points + Player Points appear separately.
- Run Club Points once and verify club match discovery occurs.
- Run Player Points once and verify roster/reconciliation work is independent.
- Verify failed board counts begin recovering where the old parser was responsible.
- Open Achievements and confirm cards are ordered by achievement count.
- Open Hall of Fame: Achievements should be the initial tab.
- Open Tournaments: Podium ranking should be the initial tab and use 🥇/🥈/🥉.
