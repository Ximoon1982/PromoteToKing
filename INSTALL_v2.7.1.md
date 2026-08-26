# Promote to King v2.7.1 — installation, clean Team Points seed and upgrade

This release upgrades the v2.7.0 standalone baseline. It adds a validated clean Team Points import, seeded incremental synchronization and explicit repair modes while retaining existing URLs and production tokens.

## 1. Back up production

Before uploading or importing, save:

- a complete MariaDB/MySQL export;
- the hosted website folder;
- `server/team-points/config/config.local.php`;
- `data/server-config.json`;
- `data/tournaments/archive.json`;
- the current CRON list or IONOS scheduled-task screenshots.

Never overwrite `config.local.php` with `config.example.php`.

## 2. Upload the standalone website

Upload the complete `PromoteToKing_Standalone_v2.7.1` contents over the existing website while preserving hidden `.htaccess` files and the protected production files listed above.

PHP must be able to write to:

```text
data/tournaments/
data/tournaments/locks/
data/tournaments/backups/
data/live-ranks/uploads/
logs/scheduled-tasks/
```

Open once:

```text
https://YOUR-DOMAIN/YOUR-PATH/server/team-points/public/public.php?action=team
```

Then sign in as an administrator and open:

```text
https://YOUR-DOMAIN/YOUR-PATH/TaskControl.html?admin=1
```

The first database-backed request upgrades Team Points to schema **12** and creates the seed-staging tables and incremental match-detail scheduling fields. The migration is additive; the current data is not deleted by the website upload.

## 3. Pause the old Team Points process

In **Administration → Scheduled Tasks Control**:

1. select **Team Points**;
2. press **Pause**;
3. wait until the task shows **Paused** rather than **Running**;
4. do not run a manual Team Points update during the import.

Also disable or delete the existing Team Points CRON before the final seed replacement. Instructions are in `CRON_SETUP_v2.7.1.md`.

Tournament and match-monitoring tasks do not need to be paused for the Team Points seed, although pausing all three during deployment gives the quietest upgrade window.

## 4. Validate the embedded updated snapshot locally

Use the separate `P2K_TeamPoints_Seed_Importer_v1.1.0.zip` package.

The six updated files supplied on 6 August 2026 are checksum-verified and embedded inside the `.pyz` executable. No source files or source folder are required at runtime.

Run:

```text
VALIDATE_EMBEDDED_DATA.bat
```

The validation checks:

- all embedded raw and compressed SHA-256 values;
- 4,473 current members and 17,091 authoritative discovered match IDs;
- 15,997 finished, 1,019 in-progress, 74 registration and one unknown match;
- duplicate or conflicting members, matches and boards;
- valid match and board references;
- timestamp order and result consistency;
- finished-match authority and 0–0 void matches;
- complete, one-game and zero-game board coverage;
- 371,168 recomputed club competition points;
- known pending-placeholder normalization.

The findings-only match `1983076` is intentionally stored as `unknown` and placed in the post-seed detail-repair queue. No metadata is invented.

The tool writes `p2k_seed_validation_report.json` beside itself. No server or database request is made in validation-only mode.

## 5. Stage and atomically replace Team Points data

Run:

```text
RUN_SEED_IMPORT.bat
```

The tool asks only for:

1. the website root URL, using HTTPS;
2. the existing Team Points administrator token, hidden while typed;
3. the two exact safety confirmations below.

It does not ask for CSV files or a folder.

```text
UPLOAD
REPLACE-TEAM-POINTS-DATA
```

Before the second confirmation, all normalized rows are only in staging tables. Production Team Points data remains untouched.

On Apply, the server:

- verifies the signed upload, nonce, embedded-source manifest and staged counts;
- verifies Team Points is paused;
- obtains the Team Points worker lock;
- replaces Team Points domain rows in one database transaction;
- removes stale Team Points jobs, queue items, scheduler leases and old Team Points task-run/log history;
- stores complete game events and durable board states;
- records 0–0 finished matches as void;
- rebuilds immutable match summaries and the club total;
- creates one paused incremental catch-up job containing the club index, roster and findings-only detail repair;
- preserves tournament, Live-rank/MCA, shared-gateway configuration and the other scheduled-task domains.

A failed validation or failed transaction leaves the previous production data intact. The tool writes `p2k_seed_apply_result.json` after a successful replacement.

## 6. Inspect before resuming

While Team Points remains paused, verify:

- seed status and snapshot time in Scheduled Tasks Control;
- members, matches, finished matches, boards and game-event counts;
- club total against the local validation report;
- several known member point totals;
- Hall of Fame Daily ranks and achievements;
- Insights → Matches and Opponents.

The imported CSV snapshot is authoritative up to its snapshot date. The first resumed incremental job catches up new roster, match and game changes.

## 7. Install the v2.7.1 CRON entries

Follow `CRON_SETUP_v2.7.1.md`. The expected scheduler ticks are:

- Team Points: every 5 minutes;
- Match monitoring: every hour;
- Tournament maintenance: every 5 minutes.

After the new entries are installed, resume Team Points in Scheduled Tasks Control. The routine algorithm is now:

```text
club roster once → club match index → changed/due match details
→ current/previous monthly player archives for unresolved participants
→ board endpoints only for unresolved fallbacks
```

Routine refreshes do **not** scan every member’s complete team-match history and do **not** scan global match IDs.

## 8. Repair modes

Scheduled Tasks Control exposes three Team Points actions:

- **Incremental refresh** — normal operation;
- **Full member-history repair** — explicitly queues `/player/{username}/matches` work for recovery;
- **Raw match-ID repair** — explicitly scans only the entered numeric range.

The two repair modes are intentionally manual and display confirmations. Do not schedule them as recurring CRON work.

## 9. Rollback

If validation after replacement fails:

1. pause Team Points and disable its CRON;
2. restore the pre-upgrade database export;
3. restore the previous hosted website folder if required;
4. restore the prior CRON entries;
5. inspect the seed validation/apply JSON files before trying again.

Keep the database backup until at least one complete incremental catch-up and one manual audit have succeeded.
