# Promote to King v2.7.1 — pause, remove and install CRON tasks

The endpoint paths and production tokens are unchanged. v2.7.1 uses short, durable scheduler ticks and does not require a self-scheduling PHP chain.

Replace `YOUR-DOMAIN`, `YOUR-PATH`, `TEAM_POINTS_CRON_TOKEN` and `CRON_TOKEN` before installation. Keep tokens private.

## 1. Pause tasks before deployment or clean seeding

Open:

```text
https://YOUR-DOMAIN/YOUR-PATH/TaskControl.html?admin=1
```

Select a task and press **Pause**. For Team Points, wait until the status is **Paused** before applying a clean seed. An already running queue item may finish and commit before the pause becomes effective.

Pausing in the website prevents work from starting, but it does not delete the host’s scheduled HTTP calls. Disable or remove the old scheduler entries as described below.

## 2. Inspect and back up the current crontab

When SSH/user crontab is available:

```sh
crontab -l > "$HOME/p2k-crontab-before-v2.7.1.txt"
crontab -l
```

When using the IONOS graphical scheduler, record each task’s URL and cadence before deleting it.

## 3. Remove old Promote to King entries

### Shell crontab

Edit:

```sh
crontab -e
```

Delete every old line calling any of these endpoints:

```text
/server/team-points/public/cron.php
/api/track-upcoming-league-matches/
/server/tournaments/public/cron.php
```

Also delete any obsolete wrapper or self-scheduling Team Points command. There must be only one recurring entry per endpoint.

A safe command-line alternative creates a filtered copy for review:

```sh
crontab -l | grep -v -E 'server/team-points/public/cron\.php|api/track-upcoming-league-matches|server/tournaments/public/cron\.php' > "$HOME/p2k-crontab-filtered.txt"
cat "$HOME/p2k-crontab-filtered.txt"
crontab "$HOME/p2k-crontab-filtered.txt"
```

### IONOS graphical scheduler

Open the hosting control panel’s scheduled-task/CRON section, disable or delete the three old Promote to King HTTP tasks, and verify no duplicate remains. If the interface only permits daily scheduling, use shell crontab or an external HTTPS scheduler for the five-minute/hourly calls.

## 4. Create a log directory

```sh
mkdir -p "$HOME/logs"
```

## 5. Install the v2.7.1 schedules

Open `crontab -e` and add one marked block:

```cron
# BEGIN PROMOTE TO KING v2.7.1
*/5 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/YOUR-PATH/server/team-points/public/cron.php?token=TEAM_POINTS_CRON_TOKEN' >>"$HOME/logs/p2k-team-points-cron.log" 2>&1
17 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/YOUR-PATH/api/track-upcoming-league-matches/?token=CRON_TOKEN' >>"$HOME/logs/p2k-match-monitoring-cron.log" 2>&1
2-59/5 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/YOUR-PATH/server/tournaments/public/cron.php?token=CRON_TOKEN' >>"$HOME/logs/p2k-tournaments-cron.log" 2>&1
# END PROMOTE TO KING v2.7.1
```

The two five-minute tasks are offset by two minutes to reduce simultaneous scheduler starts. All Chess.com consumers also share the server-side gateway lease/cache.

Token sources:

- Team Points: `app.cron_token` in `server/team-points/config/config.local.php`;
- match monitoring and tournaments: `cronToken` in `data/server-config.json`.

## 6. What each task does

### Team Points — every 5 minutes

Processes one bounded durable segment. Routine work uses the seeded incremental algorithm: roster reconciliation, club match-index changes, due match details, recent player monthly archives and board fallbacks. Full member-history and raw-ID scans are manual repair modes only.

### Match monitoring — every hour at minute 17

Acts as a scheduler tick. Each tracked match follows its own adaptive next-due time, so the endpoint does not reload every match each hour.

### Tournaments — every 5 minutes, offset at minutes 2, 7, 12…

Advances the resumable discovery, status and podium/medalist stages within the hosting execution limit.

## 7. Test endpoints manually

Run each once, without publishing the output or token:

```sh
curl --fail --show-error --max-time 55 'https://YOUR-DOMAIN/YOUR-PATH/server/team-points/public/cron.php?token=TEAM_POINTS_CRON_TOKEN'
curl --fail --show-error --max-time 55 'https://YOUR-DOMAIN/YOUR-PATH/api/track-upcoming-league-matches/?token=CRON_TOKEN'
curl --fail --show-error --max-time 55 'https://YOUR-DOMAIN/YOUR-PATH/server/tournaments/public/cron.php?token=CRON_TOKEN'
```

A paused task may legitimately report that execution was skipped because it is paused.

## 8. Verify installation

```sh
crontab -l
tail -n 50 "$HOME/logs/p2k-team-points-cron.log"
tail -n 50 "$HOME/logs/p2k-match-monitoring-cron.log"
tail -n 50 "$HOME/logs/p2k-tournaments-cron.log"
```

Then open Scheduled Tasks Control and confirm:

- Team Points expected cadence: 5 minutes;
- Match monitoring expected cadence: 1 hour;
- Tournaments expected cadence: 5 minutes;
- no duplicate/busy pattern caused by multiple host entries;
- Team Points displays `seeded_incremental_v1` and the latest seed state.

Resume paused tasks only after website upload, database migration and seed verification are complete.

## 9. Emergency stop

For an immediate stop:

1. pause the task in Scheduled Tasks Control;
2. disable/remove its host CRON entry;
3. wait for any currently running bounded invocation to finish;
4. verify no fresh run appears in Task Logs.

Do not revoke or expose tokens merely to stop scheduling. Rotate a token only if it may have leaked.
