# Promote to King v2.9.11 — Installation

## Incremental update from v2.9.10

Place these files in the production site root beside `VERSION` and `index.html`:

- `PromoteToKing_v2.9.10_to_v2.9.11_INCREMENTAL.zip`
- `update-v2.9.10-to-v2.9.11.sh`

Do not unzip the incremental archive manually. Run:

```bash
chmod +x update-v2.9.10-to-v2.9.11.sh && ./update-v2.9.10-to-v2.9.11.sh
```

The updater verifies the v2.9.10 source version and every payload hash, backs up release/config/data/log/CRON state under `_backup`, preserves protected configuration, verifies existing Core 12 / Analytics 6 without mutating schema, installs the v2.9.11 CRON dispatcher entries, and rolls back release files/CRON state if post-install validation fails.

## Standalone

Use `PromoteToKing_Standalone_v2.9.11.zip` for a fresh full deployment. Configure protected local settings separately; private `*.local.php` and mutable `data/server-config.json` are not shipped.
