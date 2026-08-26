# Promote to King v2.9.14 installation

## Incremental from v2.9.13

Place these two files in the current P2K root:

- `PromoteToKing_v2.9.13_to_v2.9.14_INCREMENTAL.zip`
- `update-v2.9.13-to-v2.9.14.sh`

Then run:

```bash
chmod +x update-v2.9.13-to-v2.9.14.sh
./update-v2.9.13-to-v2.9.14.sh
```

Do not manually unzip the incremental archive. The updater verifies hashes before applying it, creates compact rollback archives, preserves protected OAuth/configuration values, validates Core 13 / Analytics 6, and reinstalls the four external P2K CRON entries while retaining unrelated CRON jobs.
