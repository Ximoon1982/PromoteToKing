# Promote to King v2.8.9 installation

1. Back up the web tree and both MariaDB databases.
2. Upload/extract the v2.8.9 standalone package over the current deployment.
3. Preserve protected production configuration such as `config.local.php`, server config and secrets.
4. Do not reset either database. Core remains schema 6 and Analytics remains schema 5.
5. Keep the existing CRON schedule. Reinstall only if required with `reset-install-cron-v2.8.9.sh` from the site root.
6. Perform one hard browser refresh after deployment.
7. Verify the Dashboard: the database summary should populate and the automatic current/Live second wave should follow.
8. Log in with simulated OAuth (`oauth` flag enabled) and verify the configured admin is recognized immediately and Administration opens.
9. Verify ACAMR status from Club Intelligence; simulated auth requires the OAuth flag, while future real OAuth activates it regardless of the flag.
10. Verify Hall/Profile/Insights/Club Intelligence and the Team Insights six-month progression retained from v2.8.8.x.
