# Promote to King v2.9.22.1 installation

## Incremental upgrade from v2.9.22

Place these two files in the production Promote to King root:

- `PromoteToKing_v2.9.22_to_v2.9.22.1_INCREMENTAL.zip`
- `update-v2.9.22-to-v2.9.22.1.sh`

Then run:

```bash
chmod +x update-v2.9.22-to-v2.9.22.1.sh
./update-v2.9.22-to-v2.9.22.1.sh
```

The updater validates the payload before touching production, writes compact rollback archives, preserves private configuration, verifies Core 15 / Analytics 7, and leaves the existing v2.9.22 CRON contract unchanged.
