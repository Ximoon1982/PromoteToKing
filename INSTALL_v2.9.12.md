# Install Promote to King v2.9.12

## Incremental upgrade from v2.9.11

Upload these two files to the existing P2K site root:

- `PromoteToKing_v2.9.11_to_v2.9.12_INCREMENTAL.zip`
- `update-v2.9.11-to-v2.9.12.sh`

Then run from the site root:

```bash
chmod +x update-v2.9.11-to-v2.9.12.sh
./update-v2.9.11-to-v2.9.12.sh
```

The updater is argumentless. It verifies the current version, verifies every incremental payload hash, backs up protected/runtime configuration under `_backup`, applies the payload, verifies Core schema 12 / Analytics schema 6 without running a schema migration, and reinstalls/verifies the four P2K CRON entries while retaining unrelated CRON entries.

## Full standalone

For a new installation, extract `PromoteToKing_Standalone_v2.9.12.zip` into the target site directory, configure the protected local settings, install OAuth configuration if used, and run `reset-install-cron-v2.9.12.sh` where server CRON is required.
