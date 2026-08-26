# Install Promote to King v2.10.6.12

Install over the authoritative **v2.10.6.11** release.

```bash
rm -rf /tmp/p2k-v210612 && mkdir -p /tmp/p2k-v210612
unzip -q P2K_v2.10.6.11_to_v2.10.6.12_INCREMENTAL.zip -d /tmp/p2k-v210612
bash /tmp/p2k-v210612/update-v2.10.6.11-to-v2.10.6.12.sh
```

If the web root is not the current directory, pass it explicitly as the first argument:

```bash
bash /tmp/p2k-v210612/update-v2.10.6.11-to-v2.10.6.12.sh /path/to/promotetoking.org
```

The updater validates the payload SHA-256 hashes, requires the installed source to report v2.10.6.11, creates a rollback backup of every replaced file, advances the release identity and validates the v2.10.6.12 Admin-shell markers after copying.

## Post-install checks

1. Hard-refresh the site once.
2. Authenticate as an administrator and switch the Dashboard view to **Admin**.
3. Confirm the six tabs: **Competitions / Members / Team / Opponents / Admin & maintenance / Misc**.
4. Confirm live cards show **status, freshness and source** metadata.
5. Open **Admin & maintenance → Scheduled Task Control** and confirm the existing Green accelerator control remains present.
6. Open **Misc → Lost & found tools** and confirm the existing administrator tools remain accessible.
7. Confirm public Dashboard, Hall of Fame and Insights behave as before.

No database migration/reset/reseed and no CRON reinstallation are required.
