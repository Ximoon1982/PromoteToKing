# CRON setup — Promote to King v2.9.7

v2.9.7 retains four IONOS shell-dispatched tasks:

- Club Points: every 5 minutes
- Tournaments: every 10 minutes, staggered
- Member Points / Player lane: every 10 minutes, staggered
- Match tracking: hourly

Run the argumentless installer from the website root:

```bash
chmod +x reset-install-cron-v2.9.7.sh
./reset-install-cron-v2.9.7.sh
```

The dispatcher reads tokens only at runtime from protected configuration. Tokens are not written into crontab. The cURL shell watchdog remains 55 seconds; the v2.9.7 PHP endpoint is capped at 42 seconds and Player/combined worker segments at 30 seconds to leave transport margin.
