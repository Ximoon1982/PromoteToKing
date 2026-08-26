# Promote to King v2.9.22.1 migration

v2.9.22.1 is a static/client reconstruction-throughput hotfix over the exact frozen v2.9.22 release.

- Core 15 unchanged.
- Analytics 7 unchanged.
- No SQL migration, reset, reseed, or reconstruction-table conversion.
- Existing staged Fresh Points Reconstruction runs remain valid.
- Existing v2.9.22 CRON dispatcher, OAuth installer, MIAC seed installer and weekly backup scripts remain the operational versions.
- The updater preserves protected `config.local.php`, `oauth.local.php` and `data/server-config.json` byte-for-byte.
