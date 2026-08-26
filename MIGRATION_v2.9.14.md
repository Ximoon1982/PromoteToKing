# Promote to King v2.9.14 migration

Upgrade path: **v2.9.13 → v2.9.14**.

- Core schema remains **13**.
- Analytics schema remains **6**.
- No MariaDB schema migration is required.
- OAuth shared rate-controller state intentionally resets to the new 30/s startup seed because v2.9.14 changes its state version/hash namespace.
- Browser OAuth tuning uses a new sessionStorage key so old cap/rate startup values are not reused.
- The updater uses compact archive-based rollback state instead of copying the complete `data/` tree file by file.
- Legacy `_backup` directory backups are not automatically deleted. `tools/release/p2k-consolidate-legacy-backups.sh` is supplied for dry-run-first consolidation.
