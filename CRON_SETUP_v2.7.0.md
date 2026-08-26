# IONOS CRON setup — Promote to King v2.7.0

Tournament maintenance is resumable and designed for IONOS Web Hosting. Each invocation has a 45-second application deadline and performs one of three stages: discovery, status refresh, or podium/medalist completion.

Recommended schedule: every 5 minutes.

```cron
*/5 * * * * /usr/bin/curl --max-time 55 --fail --silent --show-error "https://www.promotetoking.org/server/tournaments/public/cron.php?token=REPLACE_WITH_CRON_TOKEN" >> "$HOME/logs/p2k-tournaments-cron.log" 2>&1
```

Create the log directory first if needed:

```sh
mkdir -p "$HOME/logs"
```

Use `crontab -e` to edit the user crontab, then verify with `crontab -l`. Keep the token private.
