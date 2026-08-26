# Promote to King Standalone v2.8.6 — upgrade/install

1. Back up the current web tree and both MariaDB databases.
2. Upload/extract the v2.8.6 standalone tree over v2.8.5. Preserve production `config.local.php`/secrets.
3. Do **not** reset Core or Analytics. Both remain schema 5 and no SQL migration is required from v2.8.5.
4. Existing v2.8.5 CRON entries remain valid. If you want to deliberately replace the complete crontab of the current SSH user, run:

   ```bash
   chmod +x reset-install-cron-v2.8.6.sh
   ./reset-install-cron-v2.8.6.sh 'TEAM_POINTS_CRON_TOKEN' 'SHARED_CRON_TOKEN' 'https://www.promotetoking.org'
   ```

5. Hard-refresh once after deployment so old HTML/JS cache entries are replaced by the v2.8.6 cache-busted assets.
6. Verify the public Dashboard: Team database values should appear first; Live/current Chess.com data and Match Assistant recommendations should fill in automatically afterward.
7. Verify Team/Member/Match/Opponent Insights while scrolling: lower graphs/tables should load progressively rather than block the first visible section.
8. Verify Hall of Fame: Achievement first 12, Daily/Live expanded-rank pagination, unified search and Profile progressive tournament/avatar enrichment.
9. Verify Tournaments: Podium Ranking first page should appear without a full archive download; the Tournaments subtab should load only when selected.

See `RELEASE_NOTES_v2.8.6.md`, `MIGRATION_v2.8.6.md` and `CRON_SETUP_v2.8.6.md`.
