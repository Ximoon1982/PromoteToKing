from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')

def test_release_identity_and_hotfix_baseline():
    assert text('VERSION').strip() in {'2.8.5','2.8.6','2.8.7','2.8.8','2.8.8.1', '2.8.8.2', '2.8.8.3','2.8.9','2.8.10','2.8.11','2.9.0','2.9.1','2.9.2','2.9.4','2.9.5','2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    repo=text('server/team-points/src/Repository.php')
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [6,7,8,9,10,11,12,13,14,15])
    assert any(f'ANALYTICS_SCHEMA_VERSION = {v}' in repo for v in [5,6,7])
    builder=text('server/team-points/src/AnalyticsBuilder.php')
    assert 'LOCK_EX | LOCK_NB' in builder and "'refresh_deferred'=>true" in builder
    assert 'refreshAchievementsIfDue' in builder

def test_1_achievement_player_cards_show_achievement_count():
    page=text('TournamentAchievementBadgesDemo.html')
    assert 'Achievements achieved' in page
    fragment=page[page.index('function render(){'):page.index('async function fetchAvatars')]
    assert 'achievement_count' in fragment

def test_2_achievement_details_store_date_and_event_source():
    schema=text('server/team-points/sql/analytics-schema.sql')
    migration=text('server/team-points/sql/analytics-migration-v2.8.3.sql')
    builder=text('server/team-points/src/AnalyticsBuilder.php')
    dash=text('assets/js/pages/dashboard-v2.js')
    for field in ('source_type','source_name','source_url'):
        assert field in schema and field in migration and field in builder
    assert 'Achievement date' in dash and 'Triggered by' in dash
    assert 'source_name' in dash and 'source_url' in dash

def test_3_integrated_medalist_modal_tracks_parent_viewport():
    page=text('Tournaments.html')
    assert 'function positionModal()' in page
    assert 'window.frameElement' in page and 'window.parent.innerHeight' in page
    assert "window.parent.addEventListener('scroll',positionModal" in page

def test_4_tournament_achievements_use_finish_time():
    service=text('server/tournaments/src/TournamentService.php')
    builder=text('server/team-points/src/AnalyticsBuilder.php')
    dash=text('assets/js/pages/dashboard-v2.js')
    standalone=text('TournamentAchievementBadgesDemo.html')
    assert "finish_time" in service and "'finishAt'" in service
    assert "tournament-finish" in builder and "finishAt" in builder
    assert 'tournament?.finishAt' in dash and 't.finishAt' in standalone

def test_5_linked_dashboard_cards_share_priority_hover():
    css=text('assets/css/dashboard-v2.css')
    assert '.dashboard-metric[data-team-insight]:hover' in css
    assert '.dashboard-metric[data-team-page]:hover' in css
    assert '.dashboard-call-card[type="button"]:hover' in css

def test_6_points_projection_uses_three_recent_30_day_blocks():
    repo=text('server/team-points/src/Repository.php')
    block=repo[repo.index('private function clubPointsForecast'):repo.index('/** Public aggregate data used by the Team Insights dashboard.')]
    assert "array_slice($daily,0,30)" in block
    assert "array_slice($daily,30,30)" in block
    assert "array_slice($daily,60,30)" in block
    assert 'terminalAdjustment' in block and '0.08' in block
    assert 'confidence_band_percent' in block

def test_7_most_played_opponents_is_compact_top15():
    dash=text('assets/js/pages/dashboard-v2.js'); charts=text('assets/js/pages/dashboard-insights-charts.js')
    block=charts[charts.index('function renderOpponentTopChart'):]
    assert 'slice(0,15)' in block
    assert ('rowHeight=36' in block or 'rowHeight=42' in block) and ('left:270' in block or 'left:330' in block) and 'row.icon' in block
    ui=text('ui-v2.html')
    assert 'Top 15 opponents' in ui

def test_8_monthly_match_activity_is_contained():
    css=text('assets/css/data-table.css')+text('assets/css/dashboard-v2.css')
    ui=text('ui-v2.html')
    assert '.p2k-mini-chart{height:auto;min-height:230px' in css
    assert 'p2k-mini-chart-native' in ui
    assert '#matchesTrendChart' in css and 'overflow:hidden' in css

def test_9_monthly_average_boards_graph():
    repo=text('server/team-points/src/Repository.php'); ui=text('ui-v2.html'); insights=text('assets/js/pages/dashboard-insights.js')
    assert 'AS average_boards' in repo
    assert 'matchesAverageBoardsChart' in ui and 'Average boards per match' in ui
    assert 'renderNativeLine("matchesAverageBoardsChart"' in insights


def test_10_monthly_active_members_marks_future():
    insights=text('assets/js/pages/dashboard-insights.js'); standalone=text('TeamInsights.html')
    assert 'futureBoundary' in insights and ('Today · future →' in insights or 'current month →' in insights)
    assert 'active_members:null' in insights
    assert 'futureMonth' in standalone and 'Today · future →' in standalone

