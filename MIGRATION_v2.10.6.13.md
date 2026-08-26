# Migration to v2.10.6.13

Source baseline: v2.10.6.12.

This is a source/UI-only migration.

- No database schema migration.
- No data reset or reseed.
- No CRON change.
- No Green/Blue routing change.
- No scoring change.
- No image/artwork change.

Deployment replaces only files changed or added by v2.10.6.13. The incremental installer verifies the installed source reports v2.10.6.12 before applying the payload, validates payload SHA-256 hashes, creates a rollback backup, installs the new files, then verifies VERSION and MIGRATION_VERSION are v2.10.6.13.

After installation, hard-refresh `ui-v2.html` once so the v2.10.6.13 cache generation is loaded.
