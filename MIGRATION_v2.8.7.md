# Promote to King v2.8.7 migration

v2.8.7 is an in-place code upgrade from v2.8.6.

- Core remains schema **5**.
- Analytics remains schema **5**.
- **No SQL migration is required.**
- Do not reset, recreate or re-seed either MariaDB database.
- Preserve production `server/team-points/config/config.local.php`, `data/server-config.json`, generated runtime data, tournament archives and match-tracking data.
- ACAMR coordination uses protected runtime filesystem claim files only; no persistent database table is added.
