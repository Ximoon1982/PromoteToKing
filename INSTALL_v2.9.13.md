# Install Promote to King v2.9.13

## Incremental upgrade from v2.9.12

Upload these two files to the existing P2K site root:

- `PromoteToKing_v2.9.12_to_v2.9.13_INCREMENTAL.zip`
- `update-v2.9.12-to-v2.9.13.sh`

Then run from the site root:

```bash
chmod +x update-v2.9.12-to-v2.9.13.sh
./update-v2.9.12-to-v2.9.13.sh
```

The updater is argumentless. It verifies the v2.9.12 source installation and every payload hash, backs up protected/runtime configuration under `_backup`, applies Core schema 13, compacts outstanding legacy queue duplicates, verifies no active uncanonicalized queue rows remain, preserves protected configuration byte-for-byte, and reinstalls/verifies the four P2K CRON entries while retaining unrelated CRON entries.

## Full standalone

For a new installation, extract `PromoteToKing_Standalone_v2.9.13.zip` into the target directory, configure protected local settings, install OAuth configuration if used, and run `reset-install-cron-v2.9.13.sh` where server CRON is required. Fresh installations create Core schema 13 directly and have no legacy queue backlog to compact.
