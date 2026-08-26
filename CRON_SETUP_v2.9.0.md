# Promote to King v2.9.0 CRON

Run `./reset-install-cron-v2.9.0.sh` from the production root. The installed secretless schedule is:

- Club Points: every 5 minutes
- Tournaments: minute 2 then every 10 minutes
- Member Points: minute 4 then every 10 minutes
- Match tracking: minute 17 hourly

The dispatcher reads protected tokens at invocation time, uses a verified PHP CLI only, and retains a 55-second curl watchdog compatible with the IONOS web-request envelope. Member Points itself checkpoints bounded work before that envelope rather than increasing the HTTP timeout.
