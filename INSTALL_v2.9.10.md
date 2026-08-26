# Install Promote to King v2.9.10

## Upgrade from v2.9.9
Place `PromoteToKing_v2.9.9_to_v2.9.10_INCREMENTAL.zip` and `update-v2.9.9-to-v2.9.10.sh` in the production site root, make the updater executable, and run it without arguments.

The updater verifies the incremental manifest, backs up configuration/data/logs/crontab under `_backup`, preserves protected local/OAuth/shared-server configuration byte-for-byte, installs the payload, validates source/runtime contracts, and reinstalls the unchanged four-task CRON cadence using the v2.9.10 dispatcher.

Core schema 11 → 12 is additive and is applied automatically when the Team Points repository initializes against the production database. Analytics remains schema 6.

## Fresh install
Extract `PromoteToKing_Standalone_v2.9.10.zip` into the site root, restore/create the protected local configuration files, then run `reset-install-cron-v2.9.10.sh` after validating the deployment-specific CRON tokens.

Never copy `server/team-points/config/oauth.local.php` from a release archive; it is deployment-specific and intentionally excluded.
