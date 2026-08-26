# Promote to King v2.8.5 CRON setup

v2.8.5 keeps the two independent Team Points workers and makes their freshness guarantees explicit. Club discovery is injected on every Club invocation; the lightweight roster refresh is independently injected every 30 minutes even if a long Player job is still active.

## Recommended schedule

```cron
# BEGIN PROMOTE TO KING v2.8.5
*/5 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/server/team-points/public/cron-club.php?token=TEAM_POINTS_CRON_TOKEN'
2-59/10 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/server/tournaments/public/cron.php?token=SHARED_CRON_TOKEN'
7,37 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/server/team-points/public/cron-player.php?token=TEAM_POINTS_CRON_TOKEN'
17 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/api/track-upcoming-league-matches/?token=SHARED_CRON_TOKEN'
# END PROMOTE TO KING v2.8.5
```

- **Club Points:** every 5 minutes. A new time-bucketed club-index discovery item is injected even when an older Club job is still running; urgent match/board work runs before Analytics maintenance.
- **Tournaments:** every 10 minutes, offset at minute 2. This reduces contention with the shared Chess.com gateway while remaining frequent enough for tournament status/podium maintenance.
- **Player Points:** every 30 minutes at minutes 7 and 37. A lightweight roster refresh is independently injected every 30 minutes; historical player/archive work remains lower priority.
- **Match monitoring:** hourly at minute 17.

`server/team-points/public/cron.php` remains a compatibility alias for the **Club Points** lane. Do not schedule both `cron.php` and `cron-club.php`.

## One-command SSH replacement

The release includes `reset-install-cron-v2.8.5.sh`. It deliberately deletes the **entire crontab of the current SSH user** and installs only the four entries above:

```bash
chmod +x reset-install-cron-v2.8.5.sh
./reset-install-cron-v2.8.5.sh 'TEAM_POINTS_CRON_TOKEN' 'SHARED_CRON_TOKEN' 'https://www.promotetoking.org'
```

The third argument is optional and defaults to `https://www.promotetoking.org`.

> Warning: because the requested script uses `crontab -r`, any unrelated CRON entries belonging to the same SSH user are removed too. Run `crontab -l` first if you need to preserve anything outside Promote to King.
