# Install Promote to King v2.9.8

## Recommended update from v2.9.7

Place these files in the PromoteToKing root:

- `PromoteToKing_v2.9.7_to_v2.9.8_INCREMENTAL.zip`
- `update-v2.9.7-to-v2.9.8.sh`

Then run:

```bash
cd ~/PromoteToKing
chmod +x update-v2.9.7-to-v2.9.8.sh
./update-v2.9.7-to-v2.9.8.sh
```

The updater verifies the exact v2.9.7 source version and payload hashes, backs up replaced files and CRON state, preserves protected runtime/private state, applies the additive Core-11 migration through normal maintenance, validates v2.9.8 and rolls back release files on failure.

## Protected state

The incremental payload never contains or overwrites:

- `data/`
- `logs/`
- `server/team-points/config/config.local.php`
- `server/team-points/config/oauth.local.php`
- `data/server-config.json`

## Real OAuth configuration

If `server/team-points/config/oauth.local.php` already exists from the v2.9.7 OAuth POCs, leave it in place; the updater preserves it. Ensure its registered redirect URI matches the production callback route used by the Chess.com application.

For a fresh configuration:

```bash
chmod +x install-oauth-v2.9.8.sh
./install-oauth-v2.9.8.sh
```

The installer creates the protected file once with mode `0600`, defaults the callback to `https://www.promotetoking.org/auth/callback`, and refuses to overwrite an existing configuration.

Real login is activated with `?oauth=2`; simulated OAuth remains `?oauth=1`.

## Post-install acceptance

1. Runtime Diagnostics reports Package / Site config / Manifest = **2.9.8**, Core **11**, Analytics **6**.
2. Achievement catalogue remains **162**.
3. Real `?oauth=2` login displays the same authenticated avatar/profile UI; Log off ends the server session.
4. Logged-out and simulated OAuth requests remain serial; real OAuth requests use the server-side Bearer gateway.
5. Administration displays the rolling 10-minute OAuth Bearer throughput graph after authenticated API activity.
6. Members current month appears on the incomplete/future side of Monthly active members.
7. Team Insights shows Daily Club Points on a separate axis only through today, and Daily boards/current active boards on separate axes.
8. Opponent heatmap displays paired-rating coverage; coverage may increase as the normal CRON/worker queue authoritatively revalidates historical matches.
