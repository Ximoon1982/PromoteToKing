# Install Promote to King v2.9.18

## Upgrade from canonical v2.9.17

Place these two files together in the current P2K site root:

- `PromoteToKing_v2.9.17_to_v2.9.18_INCREMENTAL.zip`
- `update-v2.9.17-to-v2.9.18.sh`

Do **not** manually unzip the incremental package. Run:

```bash
chmod +x update-v2.9.17-to-v2.9.18.sh
./update-v2.9.17-to-v2.9.18.sh
```

The updater validates the payload before touching production, creates compact rollback archives, preserves protected configuration/OAuth/shared-server values, upgrades the additive database schemas, installs the MIAC initialization seed into `data/miac/seed/`, replaces only managed P2K CRON entries, and verifies four operational v2.9.18 dispatcher entries plus one weekly backup entry.

## Fresh standalone installation

Use `PromoteToKing_Standalone_v2.9.18.zip` with the normal protected configuration/database workflow. After protected configuration is present, run once:

```bash
chmod +x install-miac-seed-v2.9.18.sh reset-install-cron-v2.9.18.sh
./install-miac-seed-v2.9.18.sh
./reset-install-cron-v2.9.18.sh
```

The schema bootstrap imports the compact MIAC seed idempotently. The original `resources/miac/seed.zip` remains immutable provenance; runtime confirmations/rejections are stored separately and are not overwritten by later seed installation.

## Weekly backup

The installed CRON schedule includes:

```cron
37 3 * * 0 .../weekly-backup-v2.9.18.sh
```

It writes one archive per ISO week directly under `_backup/`. Set `P2K_WEEKLY_BACKUP_KEEP` in the CRON environment if a retention other than 52 archives is required.
