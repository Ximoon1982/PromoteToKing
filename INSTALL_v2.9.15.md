# Promote to King v2.9.15 installation

## Incremental from canonical v2.9.14

Place these two files in the current P2K root:

- `PromoteToKing_v2.9.14_to_v2.9.15_INCREMENTAL.zip`
- `update-v2.9.14-to-v2.9.15.sh`

Then run:

```bash
chmod +x update-v2.9.14-to-v2.9.15.sh
./update-v2.9.14-to-v2.9.15.sh
```

Do not manually unzip the incremental archive. The updater validates the payload, preserves protected configuration/OAuth state, creates compact rollback archives, validates Core 13 / Analytics 6, and installs the four v2.9.15 CRON entries while retaining unrelated CRON jobs.
