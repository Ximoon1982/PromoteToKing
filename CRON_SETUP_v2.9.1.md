# Promote to King v2.9.1 — CRON setup

From the production root:

```bash
cd ~/PromoteToKing
chmod +x reset-install-cron-v2.9.1.sh cron-dispatch-v2.9.1.sh
./reset-install-cron-v2.9.1.sh
```

The installer discovers a working versioned PHP CLI and verifies `PHP_SAPI === "cli"` before modifying crontab. It also validates/repairs the shared CRON token from the protected Team Points configuration where necessary and preserves the separate Traffic analytics secret.

Installed cadence:

- Club Points: every 5 minutes
- Tournaments: every 10 minutes, offset at minute 2
- Player Points: every 10 minutes, offset at minute 4
- Match tracking: hourly at minute 17

The dispatcher keeps the 55-second `curl` watchdog used for IONOS-safe worker execution.
