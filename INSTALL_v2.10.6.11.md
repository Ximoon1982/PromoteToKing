# Install Promote to King v2.10.6.11

Install over **v2.10.6.10**.

```bash
rm -rf /tmp/p2k-v210611 && mkdir -p /tmp/p2k-v210611
unzip -q P2K_v2.10.6.10_to_v2.10.6.11_INCREMENTAL.zip -d /tmp/p2k-v210611
bash /tmp/p2k-v210611/update-v2.10.6.10-to-v2.10.6.11.sh
```

The updater prefers `/usr/bin/php8.5-cli`, then supported PHP 8.x CLIs, and validates the payload before and after installation.

## Post-install checks

1. Hard-refresh the site once to discard the older embedded analyzer/assistant bundles.
2. Dashboard should show `GREEN · live database` and its Team Points totals should match the current Green database state.
3. Compare Dashboard Club Points and match-state counts with Administration → Scheduled Task Control → Green / migration comparison. They should derive from the same live Green Core state.
4. In Match Creation Analyzer, the next-15-days graph and the registered-by-start-date table must agree by date.
5. Open Dashboard `Matches starting within 7 days` and `Priority calls`; the preparation layer must disappear only when the full assistant is actually ready.

No database migration/reset/reseed and no CRON reinstallation are required.
