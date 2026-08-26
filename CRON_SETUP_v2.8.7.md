# Promote to King v2.8.7 CRON setup

ACAMR accelerates member/point refresh while authenticated users are online, but it never replaces server scheduling. The v2.8.6 cadence remains unchanged.

```cron
# BEGIN PROMOTE TO KING v2.8.7
*/5 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/server/team-points/public/cron-club.php?token=TEAM_POINTS_CRON_TOKEN'
2-59/10 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/server/tournaments/public/cron.php?token=SHARED_CRON_TOKEN'
7,37 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/server/team-points/public/cron-player.php?token=TEAM_POINTS_CRON_TOKEN'
17 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/api/track-upcoming-league-matches/?token=SHARED_CRON_TOKEN'
# END PROMOTE TO KING v2.8.7
```

`reset-install-cron-v2.8.7.sh` is intentionally argument-less. It reads the existing production Team Points token from `server/team-points/config/config.local.php` and the shared token from `data/server-config.json`, then destructively replaces the current SSH user's complete crontab with these four jobs.
