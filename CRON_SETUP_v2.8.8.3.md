# Promote to King v2.8.8.3 CRON setup

v2.8.8.3 keeps the schedule introduced in v2.8.5:

- Club / Club Points worker: every 5 minutes.
- Tournament worker: every 10 minutes, staggered.
- Player / Member Points worker: every 30 minutes, staggered.
- League match monitoring: hourly, staggered.

ACAMR is disabled and adds no CRON entry. Club Intelligence maintenance continues to piggyback on the existing server workers.

`reset-install-cron-v2.8.8.3.sh` takes no arguments, reads the existing protected production tokens, deliberately replaces the current SSH-user crontab, and installs exactly the four schedules above.
