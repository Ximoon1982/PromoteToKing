# Promote to King v2.9.19 installation

## Incremental upgrade from exact v2.9.18

Place these two files in the deployed Promote to King root:

- `PromoteToKing_v2.9.18_to_v2.9.19_INCREMENTAL.zip`
- `update-v2.9.18-to-v2.9.19.sh`

Do **not** manually unzip the incremental archive. Run:

```bash
chmod +x update-v2.9.18-to-v2.9.19.sh
./update-v2.9.18-to-v2.9.19.sh
```

The updater verifies that production is exact VERSION 2.9.18, creates compact rollback archives, validates every incremental payload hash, applies the release, preserves protected DB/OAuth/shared configuration, verifies Core14/Analytics7, and installs the v2.9.19 managed CRON script names.

## MIAC seed

The supplied v2.9.18 MIAC seed remains the release seed. `install-miac-seed-v2.9.19.sh` is idempotent and preserves existing runtime review/confirmation state. No destructive reseed occurs.

## Managed schedule

- Club: every 5 minutes.
- Tournaments: every 10 minutes, offset 2.
- Player: every 10 minutes, offset 4.
- Match tracking: hourly at minute 17.
- Weekly long-life backup: Sunday 03:37.

Unrelated CRON entries are preserved.
