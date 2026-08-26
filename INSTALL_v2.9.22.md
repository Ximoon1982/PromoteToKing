# Promote to King v2.9.22 installation

## Incremental upgrade from v2.9.21

Place these two files in the existing Promote to King site root:

- `PromoteToKing_v2.9.21_to_v2.9.22_INCREMENTAL.zip`
- `update-v2.9.21-to-v2.9.22.sh`

Then run:

```bash
chmod +x update-v2.9.21-to-v2.9.22.sh
./update-v2.9.21-to-v2.9.22.sh
```

The updater validates the payload before changing production, creates compact rollback/state archives, applies the additive Core 15 migration, preserves protected private configuration, verifies PHP/shell/runtime release markers, and reinstalls exactly four operational v2.9.22 CRON entries plus the weekly backup entry while retaining unrelated CRON jobs.

The standalone ZIP is intended for clean deployment/recovery, not as a substitute for the verified incremental updater on an existing production installation.
