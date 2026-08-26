# Team Points installation and operation — v2.7.3

Team Points is a server-owned, MariaDB-backed collection process. The browser never receives database credentials and no browser tab must remain open for scheduled processing.

## Production requirements

- PHP 8.1+ with PDO MySQL.
- MariaDB/MySQL using InnoDB and `utf8mb4`.
- HTTPS and outbound HTTPS access to `api.chess.com`.
- SSH/user crontab or another scheduler capable of calling an HTTPS URL every five minutes.

## Configuration

Copy:

```text
server/team-points/config/config.example.php
```

to:

```text
server/team-points/config/config.local.php
```

Configure the database, `admin_token`, `cron_token`, `allowed_origin`, and a recognizable User-Agent. The recommended timeout contract is:

```php
'worker_max_seconds' => 35,
'worker_segment_seconds' => 35,
'cron_endpoint_max_seconds' => 48,
'cron_expected_interval_seconds' => 300,
'cron_continuous_enabled' => false,
'request_timeout_seconds' => 15,
```

The endpoint deliberately stops before the hosting provider's approximately 60-second ceiling. One invocation may complete only part of the durable queue; the next invocation resumes it.

## Automatic database initialization

When a verified club administrator logs in, `server/team-points/public/session.php` automatically:

1. connects to MariaDB;
2. installs or additively upgrades schema v13;
3. initializes the shared Chess.com gateway and unified task registry;
4. verifies administrator status through the gateway;
5. creates a short-lived HttpOnly same-origin session and CSRF token.

A recent verified club profile may be used during a temporary Chess.com outage, within the configured administrator stale-cache window. A username alone is never accepted as proof of administrator status.

## Authoritative Insights dimensions

Schema 13 permanently stores match start/end times, rules, time control and league classification. It also materializes daily Team Insights facts in `p2k_tp_insight_daily`. Re-run the embedded v1.2.0 Seed Importer after upgrading from v2.7.1 so historical graphs do not depend on ingestion timestamps and all stored dimensions are populated.

## Scheduled execution

Install the existing compatible endpoint every five minutes:

```cron
*/5 * * * * /usr/bin/curl --fail --silent --show-error --max-time 55 "https://YOUR-DOMAIN/server/team-points/public/cron.php?token=YOUR_PRIVATE_CRON_TOKEN" >/dev/null 2>>"$HOME/p2k-team-points-cron-errors.log"
```

The URL and token contract are unchanged from earlier releases. Internally, the endpoint now:

- enters the unified task registry;
- obtains the same-task execution lock;
- runs one Worker segment of at most 35 seconds;
- commits each queue item independently;
- returns within the 48-second endpoint target;
- relies on the next external five-minute invocation rather than a self-scheduled PHP chain.

## Administration controls

Open `TaskControl.html` from Administration. The Team Points task supports:

- **Start / queue** — creates or activates the durable job;
- **Resume** — clears safe-pause state;
- **Pause** — requests a safe pause after the current item commits;
- **Refresh** — reloads status without executing work.

`TeamPointsAdmin.html` remains available. Its legacy **Run one segment now** action performs one bounded compatibility segment; it no longer starts an endless browser loop.

## Queue stages

Normal seeded incremental work uses:

1. `sync_members` — reconciles the current roster from one club-members response without queuing every member history.
2. `sync_club_matches` — reads the club match index and queues only new, changed or due details.
3. `sync_match` — stores match metadata, participants and board references.
4. `sync_player_archive` — reads current/previous monthly archives for unresolved recent participants.
5. `sync_board` — resolves remaining boards and skips the API request when two finished events are already stored.

Explicit repair actions may additionally queue `sync_player` for complete player match histories or `discover_match_ids` for a bounded numeric range. Those stages are not part of recurring CRON work. Raw-ID discovery checkpoints the exact next ID before the Worker deadline.

## Shared Chess.com gateway

All production Team Points calls pass through `server/shared/SharedChessGateway.php`, which provides:

- one MariaDB response cache shared with tournaments and league tracking;
- ETag/Last-Modified revalidation;
- a global outbound request lock and delay;
- retry/backoff support;
- known-valid health probes;
- abnormal false-404 protection;
- optional stale-cache use only where explicitly permitted.

## Local simulation

Run:

```bash
python3 serve_team_points_local.py --reset
```

Open the printed local URL, log in as `Ximoon` or `Promoter`, and use the Administration controls. The simulator preserves the legacy API paths and durable queue semantics but uses SQLite and deterministic fake data.

## Security

- Do not publish `config.local.php`, database credentials, admin token or CRON token.
- Browser administration uses a secured session plus CSRF; the legacy `X-P2K-Admin-Token` remains only for compatible integrations.
- SQL values use prepared statements.
- Configuration, source and SQL directories are denied by `.htaccess`.

## v2.7.1 clean initialization

For a complete replacement from the embedded updated snapshot, use the separate `P2K_TeamPoints_Seed_Importer_v1.2.0.zip` tool and follow `INSTALL_v2.7.1.md`. Pause Team Points and disable its host CRON before Apply. The website upload alone is additive and does not delete current data; only the tool's exact `REPLACE-TEAM-POINTS-DATA` confirmation starts the locked transaction.
