# Promote to King v2.8.4 CRON setup

v2.8.4 splits Team Points into two independent server workers so slow member-history work cannot delay Club Points.

## Recommended schedule

```cron
# BEGIN PROMOTE TO KING v2.8.4
*/5 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/server/team-points/public/cron-club.php?token=TEAM_POINTS_CRON_TOKEN'
7,37 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/server/team-points/public/cron-player.php?token=TEAM_POINTS_CRON_TOKEN'
17 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/api/track-upcoming-league-matches/?token=SHARED_CRON_TOKEN'
2-59/5 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://YOUR-DOMAIN/server/tournaments/public/cron.php?token=SHARED_CRON_TOKEN'
# END PROMOTE TO KING v2.8.4
```

- **Club Points**: every 5 minutes. Always performs fresh club-match discovery and prioritizes new/open/recently finished matches and urgent boards.
- **Player Points**: every 30 minutes. Roster, ratings, monthly archives and bounded historical reconciliation.
- Match monitoring: hourly.
- Tournaments: every 5 minutes, offset from Club Points.

`server/team-points/public/cron.php` is retained as a compatibility alias for the **Club Points** lane. Do not schedule both `cron.php` and `cron-club.php`; use `cron-club.php` for new installations.

Both Team Points workers share the same Core database, HTTP cache and Chess.com gateway. They use separate leases, so a Player Points pass cannot prevent a Club Points pass from starting. The shared gateway still serializes/throttles outbound Chess.com requests.
