# Promote to King v2.10.6.9 — Green authoritative live reads

v2.10.6.9 makes Green the authoritative public-data path after Green cutover. It removes the remaining bootstrap-era behavior where public pages could be routed to the Green databases while still consuming stale compatibility materializations that were waiting for GAB to finish.

## What changes

- **Dashboard Club Points are native Green.** When the effective public source is Green, the Dashboard club aggregate is computed from authoritative `p2k_g_matches` and `p2k_g_players` instead of waiting for `p2k_tp_club_totals` to catch up.
- **Signed-in player Team Points are native Green.** Player points, games and W/D/L are computed from Green point events/current Green identity data after cutover; the existing Green player-total materialization is used only for ranking position.
- **Green compatibility projection is live during GAB.** Green worker observations, browser observations, terminal match responses and terminal board responses project into the mature public `p2k_tp_*` contracts immediately. GAB is now historical completeness/reconciliation, not a freshness gate.
- **Browser accelerator observations now identify their changed object.** `match_detail` returns `match_id`, `board_detail` returns `match_id` + `board_no`, and player profile observations return `username`, allowing the compatibility projector to refresh the affected public object immediately.
- **Insights analytics continue rebuilding during GAB.** `p2k_an_*` and related public analytics materializations refresh on their normal throttle while GABCRF is still converging. Opponent Insights and heatmaps therefore use the current Green projection rather than a partial bootstrap snapshot.
- **Dashboard health follows Green after cutover.** Blue `team-points-club` / `team-points-player` task cadence is no longer reported as a production incident when Green is the effective public source. A Green Team Points runtime health line reports the current Green cycle/stage, worker freshness and public analytics freshness instead.
- **Scheduled Task Control shows Green provenance.** The Green cutover metrics explicitly show `GREEN NATIVE + LIVE` and the last Green public-analytics rebuild.

## Production behavior

- Existing Green public routing is preserved by the installer. If production is already on Green, it stays on Green.
- Blue data and Blue Team Points maintenance are not deleted. Under `Green reads · both maintained`, Blue remains the rollback source.
- GAB/GABCRF continues in the background after cutover and may still report reconciliation work. It no longer delays live Green public freshness.
- No public read is intentionally sourced from Blue while the effective public source is Green. Mature compatibility schemas remain in use where they are the stable API contract, but those tables are populated from Green and refreshed during convergence.

## No infrastructure migration

- Core schema unchanged: 17.
- Analytics schema unchanged: 9.
- No database reset.
- No reseed.
- No CRON definition/cadence change.
- No image change.
