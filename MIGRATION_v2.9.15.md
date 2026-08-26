# Promote to King v2.9.15 migration

Upgrade path: **canonical v2.9.14 → v2.9.15**.

- Core schema remains **13**.
- Analytics schema remains **6**.
- No MariaDB schema migration is required.
- OAuth tuning/state namespaces are intentionally retained from v2.9.14; v2.9.15 does not force a fresh adaptive-learning reset.
- External CRON cadence remains unchanged; only the versioned dispatcher/install script names advance to v2.9.15.
- Existing protected `config.local.php`, `oauth.local.php` and `data/server-config.json` remain outside the incremental payload.
