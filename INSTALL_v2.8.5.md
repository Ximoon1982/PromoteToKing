# Promote to King Standalone v2.8.5 — upgrade/install

1. Back up the current web tree and both MariaDB databases.
2. Upload/extract the v2.8.5 standalone tree over the application files. Preserve your production `config.local.php`/secrets; do not replace them with example configuration files.
3. Do **not** empty or recreate Core or Analytics. The first authenticated Team Points admin/CRON execution performs the additive Core 4 → 5 migration.
4. Replace the current user's CRON list with the supplied script if that is the intended SSH user:

   ```bash
   chmod +x reset-install-cron-v2.8.5.sh
   ./reset-install-cron-v2.8.5.sh 'TEAM_POINTS_CRON_TOKEN' 'SHARED_CRON_TOKEN' 'https://www.promotetoking.org'
   ```

5. Trigger or wait for the first Club/Player CRON executions. In Team Points Administration verify:
   - Club index observed is recent (target ≤5 minutes);
   - Roster observed is recent (target ≤30 minutes);
   - Club and Player workers appear separately;
   - no unresolved failed urgent board work remains.
6. Check Hall of Fame → Achievements and one player Profile. Cards should render before any missing-avatar background lookup, and reopening should reuse cached/materialized data.
7. Check Team Insights. A finished authoritative 0–0 match must not contribute to any metric or graph.

See `MIGRATION_v2.8.5.md`, `CRON_SETUP_v2.8.5.md`, and `RELEASE_NOTES_v2.8.5.md` for details.
