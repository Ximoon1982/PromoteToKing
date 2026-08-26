# Install Promote to King v2.9.7

## Recommended production update

Production should currently be on the verified v2.9.5 baseline. Place these two files in the PromoteToKing root:

- `PromoteToKing_v2.9.5_to_v2.9.7_INCREMENTAL.zip`
- `update-v2.9.5-to-v2.9.7.sh`

Then run:

```bash
cd ~/PromoteToKing
chmod +x update-v2.9.5-to-v2.9.7.sh
./update-v2.9.5-to-v2.9.7.sh
```

The updater is argumentless. It validates the source VERSION and payload hashes before changing production, backs up every replaced file plus protected configuration and CRON state under `_backup`, applies only the verified v2.9.5→v2.9.7 delta, validates the result, and rolls back if post-install validation fails.

## Protected state

The incremental payload never contains or overwrites:

- `data/`
- `logs/`
- `server/team-points/config/config.local.php`
- `server/team-points/config/oauth.local.php`
- `data/server-config.json`

The updater backs up protected configuration where present. It does not recursively chmod the website or mutate existing data/log trees.

## OAuth POC configuration

After the normal release update, run the separate OAuth installer only if you have Chess.com-issued OAuth registration details:

```bash
cd ~/PromoteToKing
chmod +x install-oauth-poc-v2.9.7.sh
./install-oauth-poc-v2.9.7.sh
```

It asks for the approved application name, client ID and exact redirect URL, then creates:

`server/team-points/config/oauth.local.php`

with mode `0600`. If the file already exists, the installer leaves it untouched. Test the isolated POC at `OAuthTest.php?oauth=2`.

## Post-install acceptance

1. Runtime Diagnostics must report Package / Site config / Manifest = **2.9.7**, Core 10, Analytics 6.
2. Achievement catalogue must remain **162**.
3. Task Control → Member Points must show real per-type queue rows.
4. After one normal Player CRON cycle with both classes due, both `sync_player` and Stats/Profile must show activity; Player Matches freshness should begin moving above 0% as authoritative scans complete.
5. Confirm the Live Rook frame is fully visible and player achievement group headers show progress bars.

### v2.9.5 false-completion recovery

v2.9.7 does not reset or rewrite the durable queue. On Player-worker startup it detects current members whose earlier `sync_player` row is already `done` while `player_matches_checked_at` is still NULL and no Player continuation is active. It enqueues a distinct priority `sync_player` repair row. Existing historical queue rows remain intact for diagnostics.

