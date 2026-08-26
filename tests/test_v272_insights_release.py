#!/usr/bin/env python3
import json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def check(v,m):
    if not v: raise AssertionError(m)
def main():
    core=(ROOT/'server/team-points/sql/core-schema.sql').read_text(); analytics=(ROOT/'server/team-points/sql/analytics-schema.sql').read_text(); repo=(ROOT/'server/team-points/src/Repository.php').read_text(); builder=(ROOT/'server/team-points/src/AnalyticsBuilder.php').read_text(); dashboard=(ROOT/'ui-v2.html').read_text(); js=(ROOT/'assets/js/pages/dashboard-v2.js').read_text(); css=(ROOT/'assets/css/dashboard-v2.css').read_text(); manifest=json.loads((ROOT/'site-manifest.json').read_text())
    check('p2k_tp_match_metadata' in core and 'start_time DATETIME NULL' in core and 'end_time DATETIME NULL' in core,'Core authoritative match dates missing')
    for table in ('p2k_an_match_facts','p2k_an_player_monthly','p2k_tp_insight_daily','p2k_an_opponent_stats'):
        check(table in analytics,f'Analytics materialization missing {table}')
    check('DATE(start_time)' in builder and 'DATE(end_time)' in builder and 'game_end_utc' in builder,'Analytics daily facts do not use authoritative timestamps')
    check("status='finished' AND is_void=0" in builder,'Void finished matches are not excluded from durable result totals')
    check('refreshAnalyticsForRead' in repo and 'AnalyticsBuilder' in repo and 'refreshIfDue' in builder,'Repository does not refresh rebuildable Analytics projections on a bounded cadence')
    for chart_id in ('matchesSizePie','matchesCategoryPie','matchesSizeDistribution','matchesDurationDistribution','matchesRulesDistribution','matchesTimeControlDistribution','opponentsTopChart','membersActivityAnalytics','membersRankAnalytics'):
        check(f'id="{chart_id}"' in dashboard,f'Dashboard visualization missing: {chart_id}')
    check('renderNativeLine' in js and 'nativeChartZoom' in js and 'p2k-chart-tooltip' in css,'Hover/zoom chart infrastructure missing')
    check('renderOpponentTopChart' in js and 'p2k-opponent-treemap' not in css,'Top-opponents chart replacement missing')
    expected='Promote to King vs ${opponent.name || opponentSlug}: ${number(summary.wins)}w / ${number(summary.draws)}d / ${number(summary.losses)}l, ${number(summary.ongoing)} ongoing.'
    check(expected in js and 'data-copy-opponent-result' in js,'Admin opponent result copy missing')
    check(manifest.get('version')=='2.8.1','Manifest release stale')
    print('v2.8.1 split-database Insights release tests passed.')
if __name__=='__main__': main()
