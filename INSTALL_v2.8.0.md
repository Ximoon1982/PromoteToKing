# Promote to King v2.8.0 — detailed fresh installation guide

v2.8.0 is a clean-break Team Points storage release. It must start with **two fresh empty MariaDB databases**. Do not reuse the v2.7.x Team Points database.

The website itself remains an in-place upgrade from v2.7.3; the Team Points data layer is initialized from the supplied v2.8 initializer.

## What you need

- `PromoteToKing_Standalone_v2.8.0.zip`
- `P2K_v2.8.0_Fresh_DB_Initializer_v1.0.0.zip`
- access to the IONOS Hosting control panel
- SFTP/SSH access to the website
- Python 3 on the PC that will run the initializer
- two new empty IONOS MariaDB databases

Expected Team Points snapshot after initialization:

- 4,473 current members
- 17,091 matches
- 15,997 finished matches
- 1,019 in-progress matches
- 74 registration matches
- 1 metadata-unknown match (`1983076`)
- 104,761 member/board rows
- 198,107 finished game events
- 1,507 void 0–0 finished matches
- 371,168 Club Points

---

# Phase 1 — preserve the current installation

## 1. Pause Team Points

Open:

`https://www.promotetoking.org/TaskControl.html?admin=1`

Pause **Team Points**. Leave it paused until the new Core and Analytics databases have been initialized and checked.

For the cleanest cutover, temporarily disable the Team Points host CRON entry as well. The match-monitoring and tournament tasks can remain enabled because their storage does not use the new Team Points Core schema.

## 2. Back up the old database

Keep a full backup of the current v2.7.x database. Do not delete the old database yet.

The old database is your rollback source until v2.8 has been verified.

## 3. Back up the current website

At minimum preserve:

- the current website files;
- `server/team-points/config/config.local.php`;
- `data/server-config.json`;
- `config/site-branding.js`;
- `data/tournaments/`;
- `data/match-tracking/`;
- `data/live-ranks/`.

A full copy of the web root is preferable.

On the current IONOS account the site root has been:

```sh
cd /kunden/homepages/43/d141198007/htdocs
```

You can make a server-side code backup, space permitting, for example:

```sh
cd /kunden/homepages/43/d141198007
cp -a htdocs "htdocs-before-v2.8.0-$(date +%Y%m%d-%H%M%S)"
```

If webspace is tight, download the site through SFTP instead of making a second server-side copy.

---

# Phase 2 — create the two fresh databases

## 4. Create the Core database in IONOS

In IONOS:

1. Log in.
2. Open **Menu > Hosting**.
3. Select the hosting contract if required.
4. In **Databases**, click **Create Database**. If IONOS shows **Manage**, open it and then choose **Create Database**.
5. Select **MariaDB**.
6. Give the database a note such as `P2K v2.8 Core`.
7. Set a strong password.
8. Save.
9. Record the exact database details IONOS displays:
   - host
   - database name
   - user name
   - password
   - port, normally 3306.

Do not create tables manually. The database must remain empty.

## 5. Create the Analytics database

Repeat the same process for a second fresh database, with a note such as:

`P2K v2.8 Analytics`

Again, record host, database name, user and password and leave the database completely empty.

A standard IONOS database currently has a 2 GB per-database quota. v2.8 defaults both capacity monitors to 2,147,483,648 bytes; change the configured quota if your specific databases have another limit.

---

# Phase 3 — upload v2.8.0

## 6. Extract the website ZIP locally

Extract:

`PromoteToKing_Standalone_v2.8.0.zip`

The archive contains the complete site, not a patch.

## 7. Upload the website

Upload the v2.8.0 contents to the existing web root.

Do **not** overwrite production secrets or data blindly. In particular, preserve:

- `data/server-config.json`
- `config/site-branding.js`
- `data/tournaments/archive.json`
- `data/tournaments/backups/`
- `data/match-tracking/`
- `data/live-ranks/`

The package intentionally does not contain `server/team-points/config/config.local.php`, so an existing file normally survives an overlay. It must nevertheless be edited in the next step because the v2.7 format only describes one database.

## 8. Create the v2.8 Team Points configuration

SSH to the webspace and go to the site:

```sh
cd /kunden/homepages/43/d141198007/htdocs
```

Create the new local config from the v2.8 template:

```sh
cp server/team-points/config/config.example.php \
   server/team-points/config/config.local.php
```

If you need the old tokens first, copy them from your backed-up v2.7 config before replacing it.

Edit:

```sh
nano server/team-points/config/config.local.php
```

Fill **both** database blocks with the values IONOS assigned. The essential shape is:

```php
'databases' => [
    'core' => [
        'host' => 'CORE_DB_HOST',
        'port' => 3306,
        'name' => 'CORE_DB_NAME',
        'user' => 'CORE_DB_USER',
        'password' => 'CORE_DB_PASSWORD',
        'charset' => 'utf8mb4',
        'connect_timeout_seconds' => 5,
        'lock_timeout_seconds' => 5,
        'quota_bytes' => 2147483648,
    ],
    'analytics' => [
        'host' => 'ANALYTICS_DB_HOST',
        'port' => 3306,
        'name' => 'ANALYTICS_DB_NAME',
        'user' => 'ANALYTICS_DB_USER',
        'password' => 'ANALYTICS_DB_PASSWORD',
        'charset' => 'utf8mb4',
        'connect_timeout_seconds' => 5,
        'lock_timeout_seconds' => 5,
        'quota_bytes' => 2147483648,
    ],
],
```

Keep/re-enter your existing application secrets:

```php
'app' => [
    'club_slug' => 'promote-to-king',
    'admin_token' => 'YOUR_EXISTING_ADMIN_TOKEN',
    'cron_token' => 'YOUR_EXISTING_TEAM_POINTS_CRON_TOKEN',
    'init_token' => 'A_DEDICATED_V280_INITIALIZER_TOKEN',
    ...
],
```

Use a dedicated `init_token` with no leading/trailing spaces. The local initializer displays the value you enter in clear, along with its character count and SHA-256 fingerprint, to make signature problems diagnosable.

If `init_token` is left empty, the server uses `admin_token`, but an explicit initializer token is less ambiguous during installation.

Do not put the shared `data/server-config.json` CRON token in `app.cron_token`; those are separate tokens.

## 9. Prepare the protected filesystem directories

v2.8 creates these automatically, but creating the roots through SSH makes permission problems obvious before initialization:

```sh
cd /kunden/homepages/43/d141198007/htdocs
mkdir -p data/runtime-v280 data/archive-v280
chmod u+rwx data/runtime-v280 data/archive-v280
```

Do not run recursive `chmod` against IONOS-managed directories such as the hosting `logs` directory. v2.8 uses its own runtime tree.

Default runtime paths are:

```text
data/runtime-v280/cache/chesscom/
data/runtime-v280/logs/
data/runtime-v280/fresh-init/
data/runtime-v280/analytics/
data/archive-v280/
```

Each protected runtime directory receives an `.htaccess` deny file when created.

---

# Phase 4 — initialize the databases

## 10. Extract the initializer on your PC

Extract:

`P2K_v2.8.0_Fresh_DB_Initializer_v1.0.0.zip`

Do not upload this package to the website. It is a local administration utility.

It already embeds the six updated files you supplied:

- `FinishedMatches-P2K(3).csv`
- `ActiveMatches-P2K(2).csv`
- `parseddata(3).csv`
- `parsedmembers(2).csv`
- `allmembers(3).csv`
- `findings(2).txt`

You do not need to select or provide the files again.

## 11. Validate the embedded snapshot locally

On Windows, double-click or run:

```text
VALIDATE_EMBEDDED_DATA.bat
```

On Linux/macOS:

```sh
./validate_embedded_data.sh
```

This stage performs no network request and must report the expected counts listed at the top of this guide.

Do not proceed if local validation fails.

## 12. Run the fresh initializer

Windows:

```text
RUN_INITIALIZER.bat
```

Linux/macOS:

```sh
./run_initializer.sh
```

The utility asks for:

1. website root URL — press Enter for `https://www.promotetoking.org`;
2. the v2.8 `init_token` — it is shown in clear for verification;
3. confirmation `INITIALIZE` before any schema/data creation;
4. final confirmation `INITIALIZE-P2K-2.8.0` before Analytics is built and initialization is sealed.

The server first refuses to proceed if either configured database already contains tables.

The initializer then:

1. creates Core schema version 1;
2. creates Analytics schema version 1;
3. streams normalized members into Core;
4. streams normalized matches into Core;
5. streams compact boards/games into Core;
6. validates exact Core counts and 371,168 Club Points;
7. rebuilds Analytics from Core;
8. stores small initialization audit rows;
9. creates a paused incremental catch-up job;
10. leaves Team Points paused intentionally.

There are no SQL staging tables and no SQL HTTP-cache tables.

If a network interruption occurs before finalization, rerun with the same data; the uploaded Core rows are idempotent. If you start a completely new initializer run while an unfinished run is registered, the endpoint will refuse the conflicting run rather than mixing data.

---

# Phase 5 — verify before resuming

## 13. Verify basic Team Points data

Open:

`https://www.promotetoking.org/TeamPointsAdmin.html?admin=1`

Check the database-backed status. Expected snapshot values include:

```text
Members           4,473
Matches          17,091
Finished         15,997
In progress       1,019
Registration         74
Game events     198,107
Club Points     371,168
```

The one findings-only match `1983076` should be queued for an authoritative metadata refresh rather than silently omitted.

## 14. Verify Storage & capacity

Open:

`https://www.promotetoking.org/TeamPointsAdmin.html?admin=1&tab=storage`

Confirm:

- Core DB is available;
- Analytics DB is available;
- both DB lights are green;
- each DB shows the expected configured quota;
- filesystem cache shows a separate quota (512 MiB by default);
- the first storage sample is recorded.

Immediately after a fresh install there is not enough history for a real monthly forecast. The page should say it is collecting a baseline. During the first month it may show a labelled daily-bootstrap estimate. After at least two calendar months, projections use the monthly series.

## 15. Verify the public/Insights views

Check at least:

- Dashboard Team Points total;
- Hall of Fame / Daily ranks;
- Team Insights;
- Members Insights;
- Matches Insights;
- Opponents Insights;
- a known player profile;
- a known match detail.

All Team Points analytical values should now be served from Analytics projections derived from Core.

## 16. Live Ranks after a fresh Analytics DB

Live Rank tables intentionally start fresh because they are Analytics data and are not part of the six Team Points snapshot files.

Preserve `data/live-ranks/` from the old site. In **Team Points > Live ranks**, re-upload/process the retained Live Rank CSV pack if the new Analytics DB has no Live Rank rows yet.

Do not delete the original Live Rank CSV/source directory until the new Analytics results have been checked.

## 17. Tournament and tracked-match data

These remain filesystem-backed and should already be present if you preserved:

- `data/tournaments/`
- `data/match-tracking/`

Verify the Tournament and tracking administration screens before removing any old website backup.

---

# Phase 6 — resume scheduled processing

## 18. Restore/verify CRON

v2.8 keeps the same endpoint URLs and cadence as v2.7.x:

```cron
*/5 * * * * .../server/team-points/public/cron.php?token=TEAM_POINTS_CRON_TOKEN
17 * * * * .../api/track-upcoming-league-matches/?token=SHARED_CRON_TOKEN
2-59/5 * * * * .../server/tournaments/public/cron.php?token=SHARED_CRON_TOKEN
```

If your existing SSH CRON wrapper already calls these URLs with the correct tokens, it can remain in use.

See `CRON_SETUP_v2.8.0.md` for the canonical schedule.

## 19. Resume Team Points

Return to:

`https://www.promotetoking.org/TaskControl.html?admin=1`

Resume Team Points.

The first catch-up job will refresh the club match index, roster and the unknown metadata row(s), then routine incremental processing continues.

## 20. Watch the first cycles

Check:

- Team Points job/process logs;
- Scheduled Task Control;
- Storage & capacity;
- filesystem cache growth.

The API cache should remain bounded by `cache_max_bytes`; it should not consume either MariaDB quota.

---

# Phase 7 — retire v2.7.x data

Only after the new system has been verified and at least one normal Team Points cycle has completed:

1. retain a final old database backup;
2. remove old one-time/emergency scripts listed in `DECOMMISSION_v2.8.0.md`;
3. remove old importer CSV/ZIP/PYZ copies from the web root if any;
4. keep tournament, match-tracking and Live Rank source data;
5. when you no longer require rollback, delete the old v2.7.x Team Points database from IONOS.

The old database is not referenced by v2.8.0 once `config.local.php` points Core and Analytics at the new databases.

---

# Recovery rules

## Core unavailable

Administrator page access still works. Core-backed features show data errors. Do not rebuild Core from Analytics: Core is authoritative.

Restore Core from backup or, for a brand-new installation before any new data matters, recreate both fresh databases and rerun the initializer.

## Analytics unavailable

Core collection remains authoritative. Analytics is rebuildable from Core. Do not reacquire historical Chess.com data just because Analytics was lost.

## Filesystem cache deleted

No durable data is lost. The cache repopulates from Chess.com as requests occur.

## Filesystem cache approaches its cap

The cache automatically purges expired data and evicts old files. The Storage & capacity tab shows the separate cache budget.

## A database reaches 80%

The corresponding Storage & capacity light turns red. Investigate growth before it approaches IONOS's hard quota. Core and Analytics are monitored independently.
