# Promote to King v2.8.0 CRON setup

v2.8.0 keeps the public CRON endpoint URLs and cadence from v2.7.x. The data layer changes to Core + Analytics databases and filesystem cache, but no additional Analytics CRON is required: Core collection is continuous; Analytics materializations refresh lazily from Core and during administration/initialization, while storage housekeeping is sampled from the Team Points CRON.

Recommended schedule:

```cron
# BEGIN PROMOTE TO KING v2.8.0
*/5 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://www.promotetoking.org/server/team-points/public/cron.php?token=TEAM_POINTS_CRON_TOKEN'
17 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://www.promotetoking.org/api/track-upcoming-league-matches/?token=SHARED_CRON_TOKEN'
2-59/5 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 'https://www.promotetoking.org/server/tournaments/public/cron.php?token=SHARED_CRON_TOKEN'
# END PROMOTE TO KING v2.8.0
```

- Team Points Core collection: every 5 minutes.
- Match monitoring: every hour at minute 17.
- Tournament worker: every 5 minutes, offset by two minutes.
- Analytics rebuilds: no separate CRON; they are rebuildable from Core and refresh lazily when stale.
- Storage housekeeping and capacity sampling: driven periodically from the Team Points CRON.

During a fresh v2.8.0 installation, keep Team Points paused until both fresh databases have been initialized and validated.
