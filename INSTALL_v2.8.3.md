# Promote to King v2.8.3 — upgrade

v2.8.3 is an in-place upgrade from v2.8.2/Hotfix 1/Hotfix 2. **Hotfix 2 is already included in this package.**

1. Back up the website files and both MariaDB databases.
2. Pause the Promote to King scheduled tasks during the file upload.
3. Upload the v2.8.3 files over the existing site while preserving production secrets/configuration, especially `server/team-points/config/config.local.php` and `data/server-config.json`.
4. Do **not** run the fresh initializer and do **not** reload the historical seed.
5. Open an administrator Team Points/Insights page once. The normal repository migration upgrades Analytics schema 4→5; Core remains schema 4.
6. Verify Team Insights and Opponent Insights load normally. The v2.8.2 Hotfix 1 placeholder correction and Hotfix 2 non-blocking Analytics refresh are part of v2.8.3.
7. Open Achievements and verify player cards show achievement counts and earned details show dates/source links where available.
8. Run a manual tournament update or let the tournament CRON continue normally. Finished historical tournament rows lacking `finishAt` are incrementally refreshed from Chess.com; medal achievement dates converge to the authoritative tournament finish time.
9. Verify Tournaments → Podium ranking, including a medalist modal from the integrated dashboard.
10. Verify Team/Member/Match/Opponent Insights charts, especially Monthly match activity, Most played opponents, Average boards per match, Monthly active members, and the current-year Club Points forecast.
11. Resume the existing scheduled tasks. Endpoint URLs and cadence are unchanged.

No production database reset is required.
