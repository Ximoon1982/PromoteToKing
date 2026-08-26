# Promote to King v2.9.1 — installation / upgrade

## Recommended production upgrade from v2.9.0

Upload these two files into the production site root (`~/PromoteToKing`):

- `PromoteToKing_v2.9.0_to_v2.9.1_INCREMENTAL.zip`
- `update-v2.9.0-to-v2.9.1.sh`

Then run:

```bash
cd ~/PromoteToKing
chmod +x update-v2.9.0-to-v2.9.1.sh
./update-v2.9.0-to-v2.9.1.sh
```

The updater validates every payload SHA-256 before touching production; backs up configuration, data, logs, the previous crontab and every replaced file under `_backup`; applies only the verified incremental payload; validates PHP/shell/version markers; performs a root HTTP check when `curl` is available; and installs/verifies the four v2.9.1 CRON entries. Validation failure triggers best-effort rollback to the saved files/crontab.

## Full standalone

`PromoteToKing_v2.9.1_FULL_STANDALONE.zip` is a root-flat standalone deployment package. It deliberately does not contain mutable production secrets/data such as `data/server-config.json`, Team Points `config.local.php`, live logs or runtime databases.

## Database

No destructive reset/re-seed is required. Existing additive schema/migration logic remains in force.

## IONOS PHP

Do not invoke IONOS `/usr/bin/php` as the worker CLI. The release scripts detect and verify a real CLI using `PHP_SAPI === "cli"`; `/usr/bin/php8.5-cli` is preferred when available.

## Data Reconciliation

After upgrade, use Administration → Administration tools → Data Reconciliation to upload/check reconciliation CSV batches. Run **Check only** first. Canonical conflict correction is authoritative-queue based; the apply action requires the exact displayed `APPLY <batch-id>` confirmation.
