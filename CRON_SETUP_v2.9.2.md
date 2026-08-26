# Promote to King v2.9.2 — CRON setup

v2.9.2 keeps the current four-lane schedule and the 55-second dispatcher watchdog:

- Club lane: every 5 minutes.
- Tournament lane: every 10 minutes, staggered.
- Player lane: every 10 minutes, staggered.
- Match Tracking: hourly.

For IONOS, install/reinstall the complete schedule from the site root with:

```bash
chmod +x reset-install-cron-v2.9.2.sh cron-dispatch-v2.9.2.sh
./reset-install-cron-v2.9.2.sh
```

The installer removes older Promote to King versioned dispatcher entries, preserves unrelated CRON entries, verifies a real PHP CLI (`PHP_SAPI === "cli"`), prefers `/usr/bin/php8.5-cli` where available, repairs the shared CRON token from protected Team Points configuration when necessary, and verifies that exactly four v2.9.2 dispatcher entries were installed.

No CRON token is placed on the command line or written into the crontab.
