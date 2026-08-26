# Promote to King v2.8.10 CRON setup

Standard schedule is unchanged:

- Club / Club Points: `*/5 * * * *`
- Tournament: `2-59/10 * * * *`
- Player / Member Points: `7,37 * * * *`
- Match Tracking / league monitoring: `17 * * * *`

Use the argument-less installer from the deployed site root:

```bash
chmod +x reset-install-cron-v2.8.10.sh cron-dispatch-v2.8.10.sh
./reset-install-cron-v2.8.10.sh
```

The installed crontab contains no secrets. Each job invokes `cron-dispatch-v2.8.10.sh`, which reads the current protected token immediately before the HTTP request and authenticates with `X-P2K-Cron-Token`.

The dispatcher stores protected invocation/result markers under the Team Points runtime `cron-shell` directory. Task Control uses these markers to distinguish daemon invocation failures from endpoint HTTP/auth/network failures.

If `data/server-config.json` is missing or contains a placeholder, the installer first attempts to recover the previous shared token from existing legacy Tournament/Match Tracking cron URLs. If recovery is impossible (for example on a clean install), it generates a new cryptographically random 256-bit shared token, writes a protected `data/server-config.json`, and continues automatically.

Tournament status maintenance now runs on **every** 10-minute Tournament CRON invocation. Discovery and podium work remain staged extras, so status freshness no longer degrades to an effective 30-minute cadence.
