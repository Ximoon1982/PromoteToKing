# Promote to King v2.9.2 — installation / upgrade

## Recommended production upgrade from v2.9.1

Upload these two files into the production site root (`~/PromoteToKing`):

- `PromoteToKing_v2.9.1_to_v2.9.2_INCREMENTAL.zip`
- `update-v2.9.1-to-v2.9.2.sh`

Then run:

```bash
cd ~/PromoteToKing
chmod +x update-v2.9.1-to-v2.9.2.sh
./update-v2.9.1-to-v2.9.2.sh
```

The updater is argumentless. Before replacing anything it verifies the incremental payload SHA-256 manifest and creates a timestamped `_backup` containing configuration, the complete `data` tree, known log directories, the previous crontab, and every file that will be replaced. It then applies only the verified payload, validates version/PHP/shell markers, performs an optional root HTTP check, installs/verifies the four v2.9.2 CRON entries, and retains the backup. A post-apply validation failure triggers best-effort rollback of replaced/new files and the previous crontab.

## Full standalone

`PromoteToKing_Standalone_v2.9.2.zip` is the root-flat standalone package.

It deliberately excludes mutable production secrets and runtime state such as `data/server-config.json`, Team Points `config.local.php`, runtime databases, caches, logs and `_backup` contents. Example/default configuration and protected empty data directories are retained.

## Database

Do **not** reset or re-seed the database.

v2.9.2 upgrades in place:

- Core schema 8 → 9: adds nullable `rated_board_count` to `p2k_tp_match_metadata`.
- Analytics schema 5 → 6: adds nullable `rated_board_count` to `p2k_an_match_facts`.

The existing repository schema-upgrade path applies the additive migrations idempotently. Historical match-rating rows without paired-board provenance are intentionally excluded from the Opponent Balance Analyzer until authoritative match revalidation repopulates them.

## CRON

The updater installs the v2.9.2 dispatcher automatically. To reinstall it separately, run:

```bash
cd ~/PromoteToKing
chmod +x reset-install-cron-v2.9.2.sh cron-dispatch-v2.9.2.sh
./reset-install-cron-v2.9.2.sh
```

See `CRON_SETUP_v2.9.2.md`.
