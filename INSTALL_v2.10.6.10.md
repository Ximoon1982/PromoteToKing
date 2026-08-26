# Install Promote to King v2.10.6.10

## Supported predecessor

Install the incremental package over **v2.10.6.9**.

```bash
rm -rf /tmp/p2k-v210610 && mkdir -p /tmp/p2k-v210610
unzip -q P2K_v2.10.6.9_to_v2.10.6.10_INCREMENTAL.zip -d /tmp/p2k-v210610
bash /tmp/p2k-v210610/update-v2.10.6.9-to-v2.10.6.10.sh
```

The updater selects a modern PHP CLI when PHP validation is available, preferring `/usr/bin/php8.5-cli` and falling back through supported PHP 8 executables before bare `php`.

## After installation

1. Hard-refresh the browser so the v2.10.6.10 cache generation is loaded.
2. In Match Creation Analyzer, compare **Registration matches — next 15 days** with **Registered matches by start date** for dates inside the 15-day window.
3. Open Administration → Scheduled Task Control → Tracked matches explorer. Matches whose known start is more than 24 hours ago should be unfollowed and have no next scheduled capture.
4. From the Dashboard, open both **Matches starting within 7 days** and **Priority calls**. The Match Assistant must open without a stale loading/preparation layer behind it.

No DB migration/reset/reseed and no CRON reinstallation are required.
