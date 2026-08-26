# Promote to King v2.7.2 installation

## 1. Back up

Back up the website directory and MariaDB database. Preserve these production files during upload:

- `server/team-points/config/config.local.php`
- `data/server-config.json`
- tournament/live-rank data and any other generated data directories

## 2. Pause Team Points

Open `TaskControl.html?admin=1`, select Team Points and pause it. Wait for any current item to finish.

The CRON may remain installed because a paused task safely skips processing. Disabling it during the optional seed replacement provides a quieter maintenance window.

## 3. Upload the website

Upload the contents of `PromoteToKing_Standalone_v2.7.2` over v2.7.1 while preserving protected configuration and generated data.

Open once:

`server/team-points/public/public.php?action=team`

This runs the additive schema upgrade to schema 13. It adds match dimensions and the two Insights aggregate tables; it does not delete production data.

## 4. Populate historical match dimensions

The schema upgrade alone cannot reconstruct historic rules/time-control values that were not stored in schema 12.

For complete historic Standard/Chess960, time-control and league/friendly distributions, run the separate `P2K_TeamPoints_Seed_Importer_v1.2.0` package. It contains the same validated snapshot used for v2.7.1 plus the retained match dimensions.

1. Keep Team Points paused.
2. Run `VALIDATE_EMBEDDED_DATA.bat`.
3. Run `RUN_SEED_IMPORT.bat`.
4. Enter the exact `app.admin_token` from `server/team-points/config/config.local.php`.
5. Verify the visible token, length and SHA-256 fingerprint.
6. Type `UPLOAD`.
7. Review the staged counts.
8. Type `REPLACE-TEAM-POINTS-DATA`.

The server stages and validates all rows before the final worker-locked transaction. Production remains unchanged until Apply and rolls back on SQL failure.

## 5. Verify

Open UI v2 and check:

- Team Insights coverage dates and cumulative activity;
- 30-calendar-day rolling chart;
- match duration average/median and distributions;
- Standard/Chess960 and time-control charts;
- active-member and rank charts;
- opponent treemap and desktop table;
- an administrator opponent modal copy action.

Expected seed totals remain:

- 4,473 members;
- 17,091 matches;
- 104,761 member/board rows;
- 198,107 game events;
- 371,168 Club Points.

The first Team Insights request after schema/seed changes may rebuild the daily fact table once. Later requests reuse it until source-table timestamps change.

## 6. Resume

Resume Team Points from Scheduled Tasks Control. No CRON schedule change is required from v2.7.1.

## Rollback

Restore the pre-upgrade website and database backup together. Do not downgrade only the PHP files while leaving an unverified partially restored database.
