# Promote to King v2.8.9 CRON setup

The schedule is unchanged from v2.8.5-v2.8.8.x:

- Club lane: `*/5 * * * *`
- Tournament lane: `2-59/10 * * * *`
- Player lane: `7,37 * * * *`
- League monitoring: `17 * * * *`

ACAMR is browser-opportunistic and has **no CRON entry**.

The argument-less `reset-install-cron-v2.8.9.sh` can deliberately replace the current SSH user's complete crontab using the protected production configuration already on the server.
