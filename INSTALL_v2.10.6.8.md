# Install Promote to King v2.10.6.8

v2.10.6.8 is an incremental source update over v2.10.6.7. It requires no database migration, reset, reseed, CRON edit or image deployment.

## Incremental install

Place `P2K_v2.10.6.7_to_v2.10.6.8_INCREMENTAL.zip` in the PromoteToKing application directory and run:

```bash
rm -rf /tmp/p2k-v21068 && mkdir -p /tmp/p2k-v21068 && unzip -q P2K_v2.10.6.7_to_v2.10.6.8_INCREMENTAL.zip -d /tmp/p2k-v21068 && bash /tmp/p2k-v21068/update-v2.10.6.7-to-v2.10.6.8.sh
```

The updater explicitly selects PHP CLI 8.0+, preferring `/usr/bin/php8.5-cli`, verifies the incremental SHA-256 payload and keeps a rollback backup of every replaced file.

## Switch public reads to Green

After installation:

1. Open **Administration → Scheduled Task Control → Green**.
2. Confirm **Green switch = AVAILABLE**. Queue/convergence advisories do not prevent the switch.
3. Click **Switch reads to Green** and confirm.
4. Verify **Public reads = GREEN**, **Worker routing = both**, and **Browser ingest = both**.
5. Leave GABCRF/GFFL/normal Green cycles running. Blue remains maintained as the rollback source.

If necessary, click **Rollback reads to Blue**. This immediately selects Blue reads and restores both maintenance paths without deleting Green data.

Use **Make Green primary** only when you intentionally want Green to become the sole Team Points maintenance target. That action pauses the Blue Team Points workers but does not delete Blue data.
