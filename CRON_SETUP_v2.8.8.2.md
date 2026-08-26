# Promote to King v2.8.8.2 CRON setup

v2.8.8.2 does **not** change the schedule introduced in v2.8.5.

- Club / Club Points worker: every 5 minutes.
- Tournament worker: every 10 minutes, staggered.
- Player / Member Points worker: every 30 minutes, staggered.
- League match monitoring: hourly, staggered.

ACAMR, Club Intelligence and the v2.8.8.2 startup hotfix add no CRON entries.

The supplied `reset-install-cron-v2.8.8.2.sh` takes no arguments. From the deployed site root it reads the existing Team Points and shared CRON tokens from protected production configuration, deliberately removes the complete current SSH-user crontab, and installs exactly the four schedules above.
