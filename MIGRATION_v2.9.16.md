# Promote to King v2.9.16 migration

v2.9.16 is an in-place application/derived-data release over canonical v2.9.15.

- Core schema remains **13**.
- Analytics schema remains **6**.
- No database reset or reseed is required.
- OICR changes the Analytics logic watermark so the existing normal Analytics rebuild path refreshes derived opponent result/coverage materializations.
- PMAF uses bounded protected runtime state and the existing durable queue; it adds no SQL table.
- External CRON cadence remains unchanged at four tasks.
- Production `config.local.php`, `oauth.local.php`, `data/server-config.json`, runtime caches, tournament state and match-tracking state must be preserved.
