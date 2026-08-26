# Promote to King v2.9.3 migration

v2.9.3 is an in-place corrective upgrade from v2.9.2.

- Core schema remains **9**.
- Analytics schema remains **6**.
- No reset, re-seed or destructive database operation is required.
- The existing v2.9.2 schema migration files remain the authoritative additive migration path for installations that have not yet reached Core 9 / Analytics 6.
- Use `update-v2.9.2-to-v2.9.3.sh` beside the incremental ZIP. The updater validates hashes before changing production, backs up configuration, data, logs, replaced files and the current crontab under `_backup`, applies the payload, validates PHP/shell/runtime markers, and installs the four v2.9.3 CRON entries.
