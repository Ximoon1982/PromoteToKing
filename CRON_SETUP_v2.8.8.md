# Promote to King v2.8.8 CRON setup

The schedule is unchanged from v2.8.5/v2.8.6/v2.8.7:

- Club / Club Points: every 5 minutes.
- Tournament worker: every 10 minutes.
- Player / Member Points: every 30 minutes.
- League match monitoring: hourly.

v2.8.8 adds **no ACAMR, Intelligence, snapshot, anomaly or telemetry CRON entry**. ACAMR runs from eligible authenticated browser sessions. Daily Intelligence snapshots and the hourly-gated anomaly scan piggyback on the existing Club worker when execution budget remains.

To deliberately replace the complete current SSH user's crontab with the P2K schedule using the existing protected production tokens:

```bash
chmod +x reset-install-cron-v2.8.8.sh
./reset-install-cron-v2.8.8.sh
```
