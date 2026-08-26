# Promote to King v2.9.0 installation

## Full standalone

Deploy the root-flat `PromoteToKing_v2.9.0_FULL_STANDALONE.zip` into the site root. Preserve production-only `.env`, `*.local.php`, `data/server-config.json`, databases, runtime data and logs; these mutable secrets/data are not supplied by the standalone package.

After deployment, from the production site root run:

```bash
./reset-install-cron-v2.9.0.sh
```

The helper auto-detects a real PHP CLI (`PHP_SAPI=cli`; on current IONOS production `/usr/bin/php8.5-cli`), validates protected CRON configuration, repairs the shared CRON token if necessary, creates the analytics HMAC secret if absent, and installs four secretless dispatcher entries.

## Incremental update from v2.8.11

Upload both the incremental ZIP and `update-v2.8.11-to-v2.9.0.sh` to the production site root, then run:

```bash
./update-v2.8.11-to-v2.9.0.sh
```

The updater is argumentless. It validates the archive manifest before applying it, creates a timestamped `_backup` containing configuration/data/logs/crontab plus every replaced file, normalizes permissions, validates the updated release, checks the production root over HTTP when curl is available, installs the v2.9.0 CRON schedule, and restores replaced files/crontab on a failed post-update validation where feasible.
