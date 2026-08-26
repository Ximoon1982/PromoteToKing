# Migration to v2.10.6.14

Source baseline: v2.10.6.13.

This is a UI/integration corrective only. No database migration, reset, reseed, CRON edit, scoring migration, or Blue/Green routing change is required.

The installer replaces only the v2.10.6.14 delta, verifies payload hashes before modification, creates a rollback backup, updates release identity, and performs post-install checks.
