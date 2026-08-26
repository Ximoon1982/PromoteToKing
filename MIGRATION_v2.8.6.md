# Promote to King v2.8.6 migration

v2.8.6 is a file/application upgrade over v2.8.5.

- Core schema: **5** (unchanged)
- Analytics schema: **5** (unchanged)
- No SQL migration is required.
- Do not recreate either database and do not reload the historical seed.

Upload the v2.8.6 application files over v2.8.5 while preserving production `config.local.php` files and secrets. Existing v2.8.5 materialized data, generation state, avatar snapshots, queue state and caches remain compatible.

The public endpoints gained optional progressive `section`/`mode` parameters. Calls without those parameters retain their compatibility behavior.
