# Promote to King v2.10.8 installation

Baseline: v2.10.7.

Use `PromoteToKing_v2.10.8_INCREMENTAL_INSTALLER.run` with the companion launcher `install-promote-to-king-2.10.8.sh`.

From any directory:

```sh
bash install-promote-to-king-2.10.8.sh /path/to/PromoteToKing
```

The installer accepts v2.10.7 or v2.10.8 for idempotent replay, verifies the complete embedded payload, lints the changed PHP files, backs up every touched file under `.p2k-backups/v2.10.8-<UTC timestamp>/`, verifies installed hashes, and rolls back automatically on failure.

It does not reset databases or replace runtime data, local configuration, secrets, or CRON entries.
