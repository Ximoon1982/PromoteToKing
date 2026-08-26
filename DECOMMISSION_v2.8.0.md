# v2.8.0 post-cutover decommission checklist

Do not delete old data until the v2.8.0 website, Core DB and Analytics DB have been validated and at least one normal Team Points CRON cycle has completed successfully.

## Safe to remove after successful cutover

### Old database

Once rollback is no longer required, the complete old v2.7.x Team Points database is no longer referenced by v2.8.0. It can be deleted from IONOS after taking/retaining a final backup.

### Emergency/one-time server scripts left by older deployments

Delete these **if they exist on the server and are not part of another retained rollback directory**:

- `server/team-points/public/p2k-delete-only-cleanup.php`
- `server/team-points/public/one-time-import.php`
- any pre-v2.8 temporary seed-import endpoint copied under another name
- `tools/P2K_TeamPoints_Seed_Importer/` from v2.7.x deployments

The v2.8.0 package itself contains `server/team-points/public/seed-import.php` only as a deliberate HTTP-410 retirement stub. Leave that packaged stub in place.

### Old local importer copies in the web root

Any manually uploaded copies of these can be removed from the web server after v2.8 initialization:

- `FinishedMatches-P2K*.csv`
- `ActiveMatches-P2K*.csv`
- `parseddata*.csv`
- `parsedmembers*.csv`
- `allmembers*.csv`
- `findings*.txt`
- old importer ZIP/PYZ files

The v2.8 initializer carries its own checksum-verified compressed snapshot locally; these source CSV/TXT files are not required in the production web root.

### Disposable cache/log content

Pre-v2.8 API cache files can be removed once no rollback deployment uses them. v2.8 creates its own `data/runtime-v280/` cache tree.

Old application logs may be archived or deleted according to your retention policy.

## Keep / preserve

Do **not** delete these just because Team Points moved databases:

- `data/server-config.json` — shared site/CRON configuration.
- `config/site-branding.js` — site/OAuth/admin branding configuration.
- `data/tournaments/archive.json` and `data/tournaments/backups/` — tournament history remains filesystem-backed.
- `data/match-tracking/` — tracked-match snapshots/history.
- `data/live-ranks/` — Live Rank CSV source storage and backups; the new Analytics DB begins fresh, so retain/re-upload the source CSV pack as needed.
- achievement/rank image assets.
- the v2.8 `server/team-points/config/config.local.php`.
- `data/runtime-v280/` — this is the new protected runtime/cache tree.
- `data/archive-v280/` if you use the optional archive path.

## CRON wrapper

The endpoint URLs and cadence are unchanged. An existing SSH wrapper created for v2.7.1/v2.7.2 can continue to call the same endpoints if its tokens are still correct. You may rename/recreate it for clarity, but this is not technically required.
