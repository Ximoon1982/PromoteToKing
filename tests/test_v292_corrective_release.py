from pathlib import Path
import json,re
from PIL import Image

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')

def test_v292_identity_and_additive_schema_migrations():
    assert text('VERSION').strip() in {'2.9.2','2.9.4','2.9.5','2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    cfg=text('assets/js/site-config.js'); repo=text('server/team-points/src/Repository.php')
    assert f'version: "{text('VERSION').strip()}"' in cfg and 'builtAt:' in cfg
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [9,10,11,12,13,14,15]) and any(f'ANALYTICS_SCHEMA_VERSION = {v}' in repo for v in [6,7])
    assert "core-migration-v2.9.2.sql" in repo and "analytics-migration-v2.9.2.sql" in repo
    assert 'rated_board_count' in text('server/team-points/sql/core-migration-v2.9.2.sql')
    assert 'rated_board_count' in text('server/team-points/sql/analytics-migration-v2.9.2.sql')

def test_v292_public_order_admin_history_and_integrated_tool_context():
    ui=text('ui-v2.html'); dash=text('assets/js/pages/dashboard-v2.js')
    assert ui.index('data-public-page="hall"') < ui.index('data-public-page="insights"')
    assert 'state.adminSubtab = navigation.adminSubtab || "upcoming"' in dash
    assert 'state.adminContext = navigation.adminContext || ""' in dash
    assert 'url.searchParams.set("adminContext", state.adminContext)' in dash
    assert 'current.searchParams.set("tab", state.adminContext)' in dash
    assert 'tool.adminTool ? integratedAdminHref' in dash

def test_v292_achievement_breadth_contract_remains_available_without_deleting_legacy_keys():
    cat=text('server/team-points/src/AchievementCatalog.php'); builder=text('server/team-points/src/AnalyticsBuilder.php')
    # v2.9.4 keeps the five requested 1/5/10/15/20 milestones under additive
    # identities while restoring every v2.9.1 catalogue key, including groups-all.
    for n in [1,5,10,15,20]:
        assert f"breadth-groups-{n}" in cat and f"breadth-groups-{n}" in builder
    assert "self::item('groups-all'" in cat and "'groups-all'" in builder

def test_v292_artwork_is_honest_normalized_and_has_no_generic_catalog_fallbacks():
    manifest=json.loads(text('assets/images/achievements/ARTWORK_PROVENANCE_v2.9.2.json'))
    assert manifest['summary']=={
        'catalog_items':129,'resolved_raster':58,'approved_recovered':0,
        'rejected_recovered_now_placeholders':7,'explicit_placeholders':64,
    }
    cat=text('server/team-points/src/AchievementCatalog.php')
    item_lines='\n'.join(line for line in cat.splitlines() if 'self::item(' in line)
    assert 'p2k-logo.jpg' not in item_lines
    entries={i['key']:i for i in manifest['items']}
    assert sum(i['status']=='approved-recovered' for i in entries.values())==0
    rejected={'first-match','first-point','matches-10','matches-50','matches-100','matches-250','matches-500'}
    assert {k for k,v in entries.items() if v['status']=='rejected-placeholder'}==rejected
    for key in rejected:
        assert entries[key]['placeholder'].endswith(f'/placeholders/{key}.svg')
        assert not (ROOT/f'assets/images/achievements/masters/{key}.png').exists()
    for key,info in entries.items():
        if info['status'] in {'approved-recovered','existing-resolved'}:
            master=ROOT/info['master']; mini=ROOT/info['mini_64']; thumb=ROOT/info['thumb_128']
            with Image.open(master) as im: assert im.size==(640,640), key
            with Image.open(mini) as im: assert im.size==(64,64), key
            with Image.open(thumb) as im: assert im.size==(128,128), key

def test_v292_chart_descriptions_month_boundary_and_exact_scroll_restore():
    team=text('TeamInsights.html'); insights=text('assets/js/pages/dashboard-insights.js'); maximize=text('assets/js/shared/chart-maximize.js')
    assert 'click a legend name to show or hide that series' in team.lower()
    assert 'futureBoundary:{key:previousMonth,fraction:0' in insights and 'current month →' in insights
    assert 'scrollX: window.scrollX' in maximize and 'scrollY: window.scrollY' in maximize
    assert 'window.scrollTo(scrollX, scrollY)' in maximize

def test_v292_match_profile_health_and_seven_day_underfilled_warning():
    insights=text('assets/js/pages/dashboard-insights.js'); dash=text('assets/js/pages/dashboard-v2.js'); find=text('assets/js/pages/find-match.js')
    assert 'https://www.chess.com/club/matches/${id}' in insights
    assert 'const marks = { healthy: "●", warning: "●", paused: "●", critical: "●", failed: "●" }' in dash
    assert 'const belowMinimumWarning = belowMinimum && startsWithin7Days' in find
    assert 'needsRecruitment = Boolean(entry?.unlocked) && (belowMinimumWarning || (league && recruits > 0))' in find
    assert 'rows.filter(row => row.belowMinimumWarning).length' in find

def test_v292_opponent_heatmaps_are_one_aggregate_all_matches_set_by_default():
    js=text('assets/js/pages/opponent-balance-analyzer.js')
    assert 'Opponent Balance Analyzer · all matches' in js
    assert 'Included opponents' in js
    assert 'top: "all"' in js
    assert 'color: "log"' in js and 'value="linear"' in js
    assert 'id="ciBalanceSize"' in js and 'id="ciBalanceStrength"' in js
    assert 'heat(root.querySelector("#ciBalanceSize"), rows, 1)' in js and 'heat(root.querySelector("#ciBalanceStrength"), rows, 2)' in js
    assert 'rated_coverage_percent' in js
    assert 'All opponents' in js and 'Top 10' in js and 'Top 100' in js

def test_v292_opponent_strength_uses_same_paired_board_positions_and_coverage():
    repo=text('server/team-points/src/Repository.php'); svc=text('server/team-points/src/ClubIntelligenceService.php')
    assert 'pairedTeamAverageRatings' in repo and 'array_intersect(array_keys($ours), array_keys($theirs))' in repo
    assert "'count' => $count" in repo and 'rated_board_count' in repo
    assert "rating_source'=>'paired_board_positions'" in svc
    assert 'rated_coverage_percent' in svc and 'rated_boards' in svc and 'historical rows without paired-board provenance are omitted' in svc

def test_v292_match_creation_bar_acquisition_is_targeted_and_cache_aware():
    analyzer=text('assets/js/pages/match-creation-analyzer.js'); charts=text('assets/js/pages/match-creation-charts.js')
    assert 'chartBucketSourceRecords(scope, dateKey)' in analyzer
    assert 'loadTargetedChartDetails(scope, dateKey' in analyzer
    assert 'record.detailState !== "loaded" || record.boards === null' in analyzer
    assert 'P2K_MATCH_CREATION_TARGETED_DETAIL' in analyzer and 'P2K_MATCH_CREATION_TARGETED_DETAIL.load(scope, dateKey' in charts
    assert 'archive' not in analyzer[analyzer.index('async function loadTargetedChartDetails'):analyzer.index('window.P2K_MATCH_CREATION_TARGETED_DETAIL')].lower()

def test_v292_identical_history_runs_keep_first_and_last_only():
    hist=text('assets/js/shared/match-history-ui.js')
    assert 'omitUnchangedLineups' in hist
    assert 'let start = 0' in hist and 'const last = index - 1' in hist
    assert 'kept.push(points[start])' in hist and 'if (last > start) kept.push(points[last])' in hist

def test_v292_work_report_separates_durable_queue_states():
    api=text('server/control/public/api.php'); repo=text('server/team-points/src/Repository.php')
    for phrase in ['durable queue items committed','remaining/backlog','currently pending','claimed/running','waiting for retry','failed']:
        assert phrase in api
    for key in ['total','committed','remaining_backlog','currently_pending','claimed_running','retry_waiting']:
        assert f"['queue']['{key}']" in repo

def test_v292_cron_scripts_remain_real_cli_argumentless_contract():
    dispatch=text('cron-dispatch-v2.9.2.sh'); installer=text('reset-install-cron-v2.9.2.sh')
    assert '--max-time 55' in dispatch and 'PromoteToKing-Cron/2.9.2' in dispatch
    assert '/usr/bin/php8.5-cli' in dispatch and 'PHP_SAPI === "cli"' in dispatch
    assert 'cron-dispatch-v2.9.2.sh' in installer and '# BEGIN PROMOTE TO KING v2.9.2' in installer
