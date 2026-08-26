# Promote to King v2.9.5 CRON setup

The production schedule remains four tasks:
- Club Points: every 5 minutes;
- tournaments: every 10 minutes, staggered;
- Player Points: every 10 minutes, with roster freshness internally bounded to ~30 minutes;
- match tracking: hourly.

Use `reset-install-cron-v2.9.5.sh` for a standalone CRON reinstall. v2.9.5 adds no fifth CRON job: opponent-country/profile hydration is a bounded low-priority item inside the existing Club lane.
