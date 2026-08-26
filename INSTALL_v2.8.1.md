# Promote to King v2.8.1 — installation / upgrade guide

v2.8.1 Hotfix 2 is an **in-place convergence upgrade** for a working v2.8.x Core + Analytics deployment, including v2.8.0 Recovery Fix 6 + AuthBaseline, v2.8.1/Hotfix1, or RecoveryFix7.

**Do not create new databases. Do not run the v2.8.0 fresh initializer again. Do not reload the seed snapshot.**

The existing Core and Analytics databases are upgraded automatically to **schema revision 3**. The migration accepts schema revision 1 and either historical revision-2 branch.

## 1. Back up the current deployment

Back up the website files and both v2.8 databases before replacing code. Preserve at minimum:

- `server/team-points/config/config.local.php`
- `data/server-config.json`
- `config/site-branding.js`
- `data/tournaments/`
- `data/match-tracking/`
- `data/live-ranks/`
- `data/runtime-v280/`
- `data/archive-v280/`

The package contains a baseline `config/site-branding.js`, but production customizations should not be overwritten blindly.

## 2. Pause scheduled work briefly

Pause Team Points from the integrated Administration / Scheduled tasks page, or temporarily disable the external CRON while replacing files. Match monitoring and tournament jobs can also be paused for a completely quiet deployment, but their schemas are unchanged.

## 3. Upload v2.8.1 over v2.8.0

Extract the v2.8.1 ZIP locally and upload the website files over the current v2.8.0 installation.

Keep the existing production secrets/configuration. In particular, do not replace `server/team-points/config/config.local.php` with the example configuration.

## 4. Do not use the old initializer

The v2.8.0 initializer and its Recovery Fixes were only for creating the two fresh v2.8 databases.

For v2.8.1:

- no initializer is required;
- no CSV/TXT seed files are required;
- no Core data is deleted;
- no Analytics database is recreated.

## 5. Trigger the in-place schema upgrade

Open one of these pages while authenticated as administrator:

- `https://www.promotetoking.org/TeamPointsAdmin.html?admin=1`
- or the integrated Administration / Live ranks / Storage pages.

The Team Points API automatically calls `upgradeExistingSchema()` when either v2.8 database is below schema revision 3.

Alternatively, the normal Team Points CRON is migration-aware and can perform the same upgrade. Opening the admin page first is preferable because it gives you an immediate visible verification point.

The migration performs:

### Core → schema revision 3

Idempotently adds the combined v2.8.1 + RecoveryFix7 fields when missing:

- opportunistic member `daily_rating`, `chess960_rating`, and `rating_updated_at`;
- match-level `p2k_avg_rating` and `opponent_avg_rating`.

### Analytics → schema revision 3

Idempotently adds:

- materialized Daily/Chess960 ratings;
- `last_standard_game_at` and `last_chess960_game_at`;
- the two match average-rating fields;
- `first_place_count` for Live/MCA aggregates;
- `p2k_an_achievement_unlocks` for persistent achievement timestamps and member counts.

The migration also invalidates the old Analytics refresh marker so the combined projections are populated on the first post-upgrade Analytics read.

## 6. Verify schema versions

Open Team Points administration/status. Both data domains should report schema revision 3. No fresh initialization should run.

## 7. Verify administrator access

Log in normally. Administrator membership is now determined in this order:

1. verified OAuth admin role/claim when real OAuth supplies one;
2. the public Promote to King Chess.com club API administrator list;
3. `config/site-branding.js` `adminUsernames` only as an API-outage fallback;
4. explicit `?admin=1` remains an emergency/manual entry path.

MariaDB is not consulted to decide whether the administrator UI may open.

## 8. Verify Administration integration

From the main dashboard open Administration and check:

- Open Match Analyzer;
- Live ranks computation;
- Storage & capacity;
- Scheduled tasks/logs/diagnostics.

The integrated Live-ranks and Open Match Analyzer panels should expand to their content height instead of using a fixed scrolling iframe.

The dashboard System health block should include an overall health indicator and Storage capacity health.

## 9. Verify tracked-match history

Open a followed/upcoming match with tracking history and check the lineup-evolution graph:

- the final point is labelled **Current live lineup**;
- the x-axis uses real UTC time spacing;
- hover displays exact values;
- drag horizontally to zoom;
- double-click or Reset zoom restores the full range;
- opening a timeslot shows win-probability change versus the previous slot;
- Left/Right keyboard arrows navigate timeslots.

## 10. Verify Open Match Analyzer

Open Administration / Open Match Analyzer.

Confirm:

- it appears as integrated page content;
- the default Promote to King logo is not shown;
- selecting a match and point of view updates **Open standalone ↗**;
- the standalone URL contains the current `match` and selected `club` point of view.

## 11. Verify Insights

### Team

Check that duplicate KPI/metric blocks have been removed.

### Members

Charts should use the enlarged layout.

### Matches

Check:

- full-width chart cards;
- larger pie charts;
- no Closest-match highlight;
- Longest ongoing match appears;
- no persistent loading modal after a match profile opens.

### Opponents

Check:

- Most played opponents is Top 25 + Others, not a treemap;
- opponent modal replaces its loading state;
- monthly results are stacked W/D/L bars;
- drag zoom and hover work;
- win rate by time control appears;
- win rate by opponent-rating bracket appears where rating data exists.

Historical rating brackets are populated as authoritative match sheets are refreshed because the original v2.8 seed snapshot did not contain match-lineup rating values.

## 12. Verify unified player profiles

Open a ranked and an unranked player.

For an unranked Daily/Live category there must be no broken image placeholder.

Team Points progression should show:

- monthly points as bars;
- cumulative points as a line;
- hover details;
- drag zoom/reset.

## 13. Verify achievements

Open a player profile and the All achievements modal.

Check:

- achievement groups are folded by default;
- each group title shows `achieved / total`;
- earned achievements show a stored earned timestamp when one can be reconstructed;
- catalogue entries show how many members have earned each achievement.

Some MCA achievements whose historical source files do not preserve an exact crossing time use an explicit `first-recorded` timestamp precision. Tournament-period achievements use the stored tournament period.

## 14. Verify tournaments

Open the tournament page and verify:

- medalist rows are accented by their best medal;
- gold/silver/bronze header columns have colored medal markers.

## 15. Verify RecoveryFix7 integration

Check the additional integrated features:

- player stats opportunistically store Daily and Chess960 ratings without a separate crawler;
- member Analytics expose separate last finished Standard and Chess960 game dates;
- Match Recruitment Assistant uses stored category-specific ratings, excludes ineligible/opponent-club/already-registered players, and recruits strongest candidates first;
- Team Insights shows the current-registration future region and Low/Medium/High current-year Club Points projections;
- the recovered achievement artwork is used instead of the P2K-logo fallback.

## 16. Verify mobile tab width

On a phone-width viewport, open Insights, Hall of Fame and Administration. Their content should remain within the same outer width as the Promote to King header. Wide datasets scroll inside their table area, nested tabs scroll horizontally when needed, and the Dashboard desktop/mobile layout remains unchanged.

## 17. Resume scheduled work

Once the checks above pass, resume Team Points and restore the normal CRON schedule.

No CRON URL or cadence changes are required by v2.8.1.

## 18. Post-upgrade storage check

Open Administration / Storage & capacity. The v2.8 storage architecture is unchanged:

- Core DB = canonical compact facts;
- Analytics DB = rebuildable projections;
- filesystem = gzip API cache, logs and runtime files.

The schema-revision-3 additions are small and should not materially change the capacity forecast.
