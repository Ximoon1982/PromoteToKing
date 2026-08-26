> Current release: **v2.9.9**. For the exact v2.9.8 → v2.9.9 procedure, see `INSTALL_v2.9.9.md`.

# v2.9.7 installation note

For production updating from verified v2.9.5, use `update-v2.9.5-to-v2.9.7.sh` with `PromoteToKing_v2.9.5_to_v2.9.7_INCREMENTAL.zip`. See `INSTALL_v2.9.7.md`.

# Promote to King v2.8.8 installation / upgrade

For an existing v2.8.6 installation, follow **`INSTALL_v2.8.8.md`**, **`MIGRATION_v2.8.8.md`**, and **`CRON_SETUP_v2.8.8.md`**.

v2.8.8 is an in-place code upgrade. Core remains schema 5 and Analytics remains schema 5. Do not reset either database, rerun the fresh initializer, or reload the historical seed.

The supplied **argument-less** `reset-install-cron-v2.8.8.sh` reads the two existing production CRON secrets from protected configuration and can deliberately replace the complete current SSH user's crontab with the staggered v2.8.8 schedule.
