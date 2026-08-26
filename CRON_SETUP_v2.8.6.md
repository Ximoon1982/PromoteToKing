# Promote to King v2.8.6 CRON setup

The server scheduling model is unchanged from v2.8.5. v2.8.6 changes browser/public loading behavior, not synchronization cadence.

```cron
# BEGIN PROMOTE TO KING v2.8.6
*/5 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/server/team-points/public/cron-club.php?token=TEAM_POINTS_CRON_TOKEN'
2-59/10 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/server/tournaments/public/cron.php?token=SHARED_CRON_TOKEN'
7,37 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/server/team-points/public/cron-player.php?token=TEAM_POINTS_CRON_TOKEN'
17 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/api/track-upcoming-league-matches/?token=SHARED_CRON_TOKEN'
# END PROMOTE TO KING v2.8.6
```

- Club Points: every 5 minutes.
- Tournaments: every 10 minutes, offset at minute 2.
- Player Points: every 30 minutes at minutes 7 and 37.
- Match monitoring: hourly at minute 17.

`server/team-points/public/cron.php` remains a compatibility alias for the Club lane. Do not schedule it alongside `cron-club.php`.

`reset-install-cron-v2.8.6.sh` deliberately deletes the **entire crontab of the current SSH user** and installs only these four jobs. Preserve unrelated entries first if the account has any.
