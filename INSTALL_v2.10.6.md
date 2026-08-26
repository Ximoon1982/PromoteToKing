# Install Promote to King v2.10.6

## Incremental upgrade from v2.10.5.5

Unzip `P2K_v2.10.5.5_to_v2.10.6_INCREMENTAL.zip`, then from the extracted package run:

```bash
bash update-v2.10.5.5-to-v2.10.6.sh /homepages/43/d141198007/htdocs/PromoteToKing
```

The updater verifies payload hashes, backs up every replaced file, applies the additive Core 17 / Analytics 9 schema migrations, validates Blue and configured Green compatibility schemas, lints delivered PHP/shell files, and installs the twice-daily MCA CRON block when `crontab` is available.

If `crontab` is not available, run `reset-install-mca-cron-v2.10.6.sh` through SSH where supported or add the printed command to the IONOS Cron Job Manager twice daily.

## MCA scheduler

The installed expression is:

```cron
17 0,12 * * * .../cron-mca-results-v2.10.6.sh
```

The source worker additionally enforces a 12-hour next-scan gate and serial >=1-second request spacing, so accidental extra invocations do not increase the intended scan cadence.

## Standalone/source deployment

For recovery or a complete code deployment, use the v2.10.6 SOURCE archive together with the unchanged v2.10.5.5/v2.10.6 assets-images archive. Protected local configuration and runtime/database data must not be overwritten.
