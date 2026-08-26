# Promote to King v2.7.3 installation

This is a drop-in update from v2.7.2. There is no database migration, seed import, or CRON change.

1. Back up the deployed website.
2. Preserve production-only configuration, especially `server/team-points/config/config.local.php`, `data/server-config.json`, and any host-managed secrets.
3. Replace the website files with the v2.7.3 package.
4. No seed importer is required.
5. No database schema action is required; Team Points remains schema 13.
6. No CRON reinstall is required.
7. Test offline-safe administrator page access with `index.html?admin=1`.
8. Open a non-DB administrator tool such as `AnalyzeMatches.htm?admin=1` or `AnalyzeMatch.html` while MariaDB is unavailable; the page must render.
9. DB-backed panels may show a connection error until MariaDB is available. That error must not revoke administrator page access.

For future OAuth authorization, `config/site-branding.js` accepts an optional `adminUsernames` array. OAuth sessions that carry an administrator role/claim are also recognized without DB/API verification.
