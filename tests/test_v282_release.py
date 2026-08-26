from pathlib import Path
import re
from PIL import Image

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_01_02_admin_metrics_and_recent_matches():
    dash=text('assets/js/pages/dashboard-v2.js'); repo=text('server/team-points/src/Repository.php'); ui=text('ui-v2.html')
    assert 'recent-matches.php?hours=24' in dash and 'publicRecentMatches' in repo
    assert 'first_discovered_at DESC,match_id DESC' in repo
    for kind in ['underfilled','starts48','league','exceptions','new24']:
        assert f'data-admin-metric="{kind}"' in dash
    assert 'openAdminMetricModal' in dash and 'data-admin-match-detail' in dash

def test_03_priority_forecast_compact_and_minimum_rule():
    finder=text('assets/js/pages/find-match.js'); dash=text('assets/js/pages/dashboard-v2.js'); css=text('assets/css/dashboard-v2.css')
    assert 'const winProbability = belowMinimum ? 0 : dashboardMatchWinProbability(entry);' in finder
    assert 'dashboard-admin-priority-item-actions' in dash and 'actions.append(probability,action)' in dash
    for tone in ['is-good','is-warn','is-bad']: assert f'.dashboard-admin-priority-probability.{tone}' in css

def test_04_mca_health_link():
    dash=text('assets/js/pages/dashboard-v2.js')
    assert 'name: "MCA data import"' in dash and 'Last completed import' in dash and 'integratedAdminHref("live-ranks")' in dash

def test_05_06_dashboard_navigation():
    ui=text('ui-v2.html'); dash=text('assets/js/pages/dashboard-v2.js'); finder=text('assets/js/pages/find-match.js')
    assert 'Matches starting within 7 days' in ui and 'data-assistant-filter="next7"' in ui and 'data-assistant-filter="priority"' in ui
    assert 'openMatchAssistantWithFilter' in dash and 'p2k-dashboard-apply-filter' in dash and 'dashboardPresetFilter' in finder
    assert 'data-team-insight="members"' in ui and 'data-team-insight="team"' in ui and 'data-team-insight="matches"' in ui
    assert 'data-team-page="hall-live"' in ui

def test_07_profile_link_both_modes():
    ui=text('ui-v2.html'); dash=text('assets/js/pages/dashboard-v2.js')
    assert 'id="openUnifiedProfile"' in ui and '>profile →</button>' in ui
    assert 'openUnifiedProfileDaily' not in ui and 'openUnifiedProfileLive' not in ui
    assert 'openUnifiedPlayerProfile(state.session.username)' in dash

def test_08_09_images_resized_and_orphans_wired():
    pngs=[]
    for p in (ROOT/'assets/images').rglob('*.png'):
        with Image.open(p) as im:
            if im.size==(640,640): pngs.append(p)
            assert im.size!=(1254,1254), f'legacy 1254 image remains: {p}'
    assert len(pngs)>=56
    catalog=text('server/team-points/src/AchievementCatalog.php')
    # Rank-based achievements now deliberately use the same framed rank art as the rank ladders.
    for path in [
        'assets/images/ranks/06_King_250_points.png',
        'assets/images/ranks/08_Silver_King_1000_points.png',
        'assets/images/live-ranks/01_Live_Pawn_50_points.png',
    ]:
        assert path in catalog
    paths=set(re.findall(r"'((?:assets/)[^']+\.(?:png|webp|jpg))'",catalog))
    assert not [x for x in paths if not (ROOT/x).is_file()]

def test_10_lineup_history_layout_no_zoom():
    js=text('assets/js/shared/match-history-ui.js'); css=text('assets/css/match-history.css')
    assert 'p2k-history-winrate' in css and 'grid-column:1/-1' in css
    assert 'p2k-history-details-columns' in css
    assert 'data-p2k-history-previous' in js and 'data-p2k-history-next' in js
    assert 'bindZoom(host' not in js
    assert 'Reset zoom' not in js and 'Drag across the chart to zoom' not in js

def test_11_live_ranks_integrated_border_removed():
    css=text('assets/css/admin-embedded.css')
    assert 'html.p2k-embedded .p2k-tp [data-panel="live-ranks"]>.p2k-tp-card' in css and 'border:0' in css

def test_12_match_monitoring_explorer():
    page=text('TaskControl.html'); js=text('assets/js/pages/task-control.js')
    for marker in ['Tracked matches explorer','trackedMatchInput','Follow and record','Record data now','trackedStatus','trackedFollowState','trackedSort','trackedRemoveFinished','trackedHistoryModal']:
        assert marker in page
    for marker in ['addTracked','recordTrackedNow','toggleTracked','openTrackedHistory','removeFinishedTrackedData','api/tracked-match-data/']:
        assert marker in js

def test_13_open_match_standalone_link():
    ui=text('ui-v2.html'); analyzer=text('AnalyzeMatch.html')
    assert 'Open Match Analyzer standalone ↗' in ui
    assert 'id="p2kStandaloneLink"' in analyzer and 'Open standalone ↗' in analyzer

def test_14_tournament_medals_and_exclusions():
    page=text('Tournaments.html'); admin=text('TournamentManagement.html'); browse=text('server/tournaments/public/browse.php')
    for cls in ['medal-count-cell medal-gold','medal-count-cell medal-silver','medal-count-cell medal-bronze']:
        assert cls in page
    assert 'rank-medal-gold' not in page and 'rank-medal-silver' not in page and 'rank-medal-bronze' not in page
    assert '$excluded' in browse and "!isset($excluded[strtolower($name)])" in browse
    assert 'id="accounts" type="checkbox" checked' in admin and 'validateAccounts' in admin and 'fair_play' in admin and 'abuse' in admin

def test_15_16_insight_width_and_spill():
    ui=text('ui-v2.html'); css=text('assets/css/dashboard-v2.css')
    assert 'id="membersActivityAnalytics"' in ui and 'id="membersRankAnalytics"' in ui
    assert ui.count('class="p2k-insight-card is-full-width"')>=2
    assert '.p2k-insights-grid>.p2k-insight-card.is-full-width{grid-column:1/-1}' in css
    assert '#matchesTrendChart{overflow:hidden}' in css and '#matchesTrendChart svg{overflow:hidden!important}' in css

def test_17_18_match_chart_readability():
    ui=text('ui-v2.html'); insights=text('assets/js/pages/dashboard-insights.js'); charts=text('assets/js/pages/dashboard-insights-charts.js')
    assert 'matchesSizePie' not in ui
    assert 'renderNativeStackedBars("matchesSizeDistribution"' in insights and 'showTotals: true' in insights
    assert 'p2k-chart-value' in charts
    assert 'renderDurationDistribution' in insights and 'Average' in insights and 'Median' in insights and 'p2k-chart-reference' in insights


def test_19_20_21_opponent_insights():
    insights=text('assets/js/pages/dashboard-insights.js'); charts=text('assets/js/pages/dashboard-insights-charts.js'); repo=text('server/team-points/src/Repository.php'); css=text('assets/css/dashboard-v2.css')
    assert 'slice(0,15)' in charts and 'Others' not in charts[charts.index('function renderOpponentTopChart'):]
    assert 'Win rate by max rating' in insights and 'max_rating_rates' in insights
    assert 'metadata.max_rating' in repo and "'max_rating_rates'=>array_values($maxRatingRates)" in repo
    assert '.p2k-opponents-table{font-size:13px}' in css


def test_22_23_profile_achievement_and_dual_scale():
    dash=text('assets/js/pages/dashboard-v2.js'); repo=text('server/team-points/src/Repository.php')
    assert 'club_current_member_count' in repo and 'earned_current_member_count' in repo
    assert 'club member${currentCount === 1 ? "" : "s"}' in dash and '% of current club members.' in dash
    charts=text('assets/js/pages/dashboard-insights-charts.js'); fn=charts[charts.index('function renderNativeBarLine'):charts.index('function renderOpponentTopChart')]
    assert 'barMax' in fn and 'lineMax' in fn and 'leftTitle' in fn and 'rightTitle' in fn

def test_24_hall_search_unified_card_profile_first():
    hall=text('assets/js/pages/dashboard-hall.js')
    block=hall[hall.index('async function searchHallUnified'):hall.index('async function loadHall')]
    assert 'p2k-hall-result-unified' in block and 'p2k-hall-unified-grid' in block
    assert 'Open profile' in block and 'openUnifiedPlayerProfile(queryName)' in block
    assert block.index('actions.appendChild(profileButton)') < block.index('hallResultCard("Daily ranks"')
    for marker in ['hallResultCard("Daily ranks"','hallResultCard("Live ranks"','hallResultCard("Tournaments"','hallResultCard("Achievements"']: assert marker in block


def test_schema4_and_release_identity():
    repo=text('server/team-points/src/Repository.php')
    assert text('VERSION').strip() in {'2.8.5','2.8.6','2.8.7','2.8.8','2.8.8.1', '2.8.8.2', '2.8.8.3','2.8.9','2.8.10','2.8.11','2.9.0','2.9.1','2.9.2','2.9.4','2.9.5','2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [6,7,8,9,10,11,12,13,14,15]) and any(f'ANALYTICS_SCHEMA_VERSION = {v}' in repo for v in [5,6,7])
    assert (ROOT/'server/team-points/sql/core-migration-v2.8.2.sql').is_file()
    assert (ROOT/'server/team-points/sql/analytics-migration-v2.8.3.sql').is_file()
    core=text('server/team-points/sql/core-migration-v2.8.2.sql')
    assert 'max_rating' in core and 'first_discovered_at' in core and 'VALUES(4)' in core

def test_analytics_match_facts_insert_has_matching_column_value_counts():
    builder=text('server/team-points/src/AnalyticsBuilder.php')
    block=builder[builder.index('INSERT INTO p2k_an_match_facts'):builder.index('private function rebuildPlayerMonthly')]
    m=re.search(r'INSERT INTO p2k_an_match_facts\((.*?)\)\s*VALUES\((.*?)\)', block, re.S)
    assert m
    columns=[part.strip() for part in m.group(1).split(',')]
    values=[part.strip() for part in m.group(2).split(',')]
    assert len(columns)==26
    assert len(values)==26
    assert values.count('?')==26
    execute=re.search(r'\$ins->execute\(\[(.*?)\]\);', block, re.S)
    assert execute
    bound=re.findall(r"\$r\['[^']+'\]|\(int\)\$r\['[^']+'\]", execute.group(1))
    assert len(bound)==26
