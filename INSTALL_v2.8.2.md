# Promote to King v2.8.2 — upgrade

v2.8.2 is an in-place upgrade from the validated v2.8.x Core/Analytics architecture. **Do not recreate the databases and do not rerun the fresh initializer.**

1. Back up the website and both MariaDB databases. Preserve production configuration/secrets and generated `data/` directories.
2. Pause the three scheduled tasks from Administration (or disable the host CRON entries briefly).
3. Upload the contents of the v2.8.2 standalone ZIP over the existing site. The 640×640 rank/achievement PNGs intentionally have the same paths and names, so FTP overwrite is sufficient.
4. Open an administrator Team Points/Administration page or run the normal Team Points CRON once. `Repository::upgradeExistingSchema()` upgrades supported v2.8 databases to Core/Analytics schema revision 4.
5. Verify Administration → System health, Live ranks/MCA import, Match monitoring, Open Match Analyzer and Storage.
6. Verify Dashboard, Insights, Hall of Fame and a unified player profile on desktop and mobile.
7. Resume scheduled tasks. Endpoint URLs and normal CRON cadence are unchanged.

Schema 4 is additive: Core adds `max_rating` and `first_discovered_at`; Analytics materializes the same fields. Historical `max_rating` values populate as authoritative match details are refreshed. Historical rows are deliberately marked as pre-upgrade instead of being guessed as newly discovered. The 24-hour metric therefore becomes exact for matches first discovered after v2.8.2 is deployed.
