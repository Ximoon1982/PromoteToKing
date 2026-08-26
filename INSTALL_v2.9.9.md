# Install Promote to King v2.9.9

## Recommended update from v2.9.8

Place these files in the PromoteToKing root:

- `PromoteToKing_v2.9.8_to_v2.9.9_INCREMENTAL.zip`
- `update-v2.9.8-to-v2.9.9.sh`

Then run:

```bash
cd ~/PromoteToKing
chmod +x update-v2.9.8-to-v2.9.9.sh
./update-v2.9.8-to-v2.9.9.sh
```

The updater verifies the exact v2.9.8 source version and incremental payload hashes, backs up every replaced file and the current CRON state, preserves protected runtime/private state, applies the code-only v2.9.9 delta, validates the installed release, installs/verifies the unchanged four-task CRON cadence under the v2.9.9 dispatcher name, and rolls back release files/CRON state if post-install validation fails.

## Protected state

The incremental payload never contains or overwrites:

- `data/`
- `logs/`
- `server/team-points/config/config.local.php`
- `server/team-points/config/oauth.local.php`
- `data/server-config.json`

Existing OAuth registration details therefore remain host-owned and unchanged.

## Real OAuth

No OAuth credential reinstallation is required when upgrading from v2.9.8. Existing `server/team-points/config/oauth.local.php` is preserved byte-for-byte.

For a fresh host only:

```bash
chmod +x install-oauth-v2.9.9.sh
./install-oauth-v2.9.9.sh
```

Real authentication is selected with `?oauth=2`. Simulated OAuth remains `?oauth=1` and intentionally uses serial API access.

## Post-install acceptance

1. Runtime Diagnostics reports Package / Site config / Manifest = **2.9.9**, Core **11**, Analytics **6**.
2. Achievement catalogue remains **162**.
3. Log in through `?oauth=2`; embedded tools must retain `oauth=2` rather than changing to `oauth=1`.
4. On CRON Control, start Client continuous refresh / speed fetch with due work available. The Administration throughput chart should be able to rise beyond the old ~5 req/s behavior while Chess.com remains healthy; exact throughput is intentionally adaptive and not guaranteed.
5. If Chess.com returns 429 or material transport/server pressure, the adaptive target must fall and later re-probe rather than maintaining an unsafe fixed rate.
6. Logged-out and `?oauth=1` calls remain serial.
7. Match analyzers, Challenge Assistant, Recruitment Demand Planner and Tournament Management should all use the same shared real-OAuth transport rather than local hard worker limits.
8. Live Ranks and opponent maintenance should use server-side OAuth batches when invoked from an authenticated admin session; CRON/CLI remains anonymous.
