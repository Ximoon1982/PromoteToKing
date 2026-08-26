from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_release_version_and_migration():
    assert text('VERSION').strip() in {'2.8.5','2.8.6','2.8.7','2.8.8','2.8.8.1', '2.8.8.2', '2.8.8.3','2.8.9','2.8.10','2.8.11','2.9.0','2.9.1','2.9.2','2.9.4','2.9.5','2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    repo=text('server/team-points/src/Repository.php')
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [6,7,8,9,10,11,12,13,14,15]) and any(f'ANALYTICS_SCHEMA_VERSION = {v}' in repo for v in [5,6,7])
    assert 'core-migration-v2.8.1-hotfix2.sql' in repo and 'analytics-migration-v2.8.1-hotfix2.sql' in repo and 'core-migration-v2.8.2.sql' in repo and 'analytics-migration-v2.8.3.sql' in repo
    assert 'p2k_an_achievement_unlocks' in text('server/team-points/sql/analytics-schema.sql')
    assert 'first_place_count' in text('server/team-points/sql/analytics-schema.sql') and 'first_place_count' in text('server/team-points/src/LiveRanksService.php')
    assert 'opponent_avg_rating' in text('server/team-points/sql/core-schema.sql')
    assert 'upgradeExistingSchema' in text('server/team-points/public/cron.php')

def test_admin_api_primary_fallback():
    dash=text('assets/js/pages/dashboard-v2.js'); guard=text('assets/js/shared/admin-page-guard.js')
    for src in (dash,guard):
        assert 'clubAdminUsernames' in src
        assert 'api.chess.com/pub/club' in src or 'clubProfileAPI' in src
        assert 'configuredAdminUsernames' in src
        assert 'MariaDB' in src or 'database' in src.lower()
    block=dash[dash.index('async function verifyAdmin'):dash.index('function adminPanelMarkup')]
    assert 'P2K_TEAM_POINTS_CLIENT' not in block
    assert 'if (fallbackAllowed)' in block and block.index('fallbackAllowed') < block.index('loadJSON(clubProfileAPI')

def test_match_history_live_zoom_keyboard():
    js=text('assets/js/shared/match-history-ui.js')
    for marker in ['isLive','ArrowLeft','ArrowRight','axisTimestamp','percentage points vs previous timeslot']:
        assert marker in js

def test_integrated_admin_and_analyzer():
    ui=text('ui-v2.html'); dash=text('assets/js/pages/dashboard-v2.js'); analyzer=text('AnalyzeMatch.html')
    assert 'data-admin-panel="storage"' in ui and 'tab=storage' in ui
    assert 'data-admin-panel="live-ranks"' in ui and 'TeamPointsAdmin.html?embedded=1&tab=live-ranks' in ui
    assert 'adminOverallHealth' in dash and 'Storage capacity' in dash
    assert 'p2kStandaloneLink' in analyzer and 'p2k-standalone-action' in analyzer and 'id="p2kSelectedTeamLogo"' in analyzer and 'id="p2kLogo"' not in analyzer

def test_insight_visuals_and_modal_replacement():
    dash=text('assets/js/pages/dashboard-v2.js'); ui=text('ui-v2.html'); insights=text('assets/js/pages/dashboard-insights.js'); charts=text('assets/js/pages/dashboard-insights-charts.js')
    assert 'renderOpponentTopChart' in insights and 'opponentsTopChart' in ui
    assert 'renderOpponentTreemap' not in insights
    assert 'opponentResultsTrend' in insights and 'time_controls' in insights and 'rating_brackets' in insights
    assert 'Opponent intelligence' in insights and 'Database match profile' in insights and 'longest_ongoing' in insights
    assert 'p2k-insights-grid-full' in ui and 'p2k-insights-grid-large' in ui


def test_player_profile_and_achievements():
    dash=text('assets/js/pages/dashboard-v2.js'); repo=text('server/team-points/src/Repository.php'); builder=text('server/team-points/src/AnalyticsBuilder.php')
    assert 'renderNativeBarLine' in dash and 'cumulative' in dash
    assert 'dailyImage?`<img' in dash and 'liveImage?`<img' in dash
    assert '<details class="p2k-achievement-family"' in dash
    assert 'p2k-achievement-group-static' in dash
    assert 'achievedCount' in dash
    assert 'publicAchievementCatalog' in repo and 'earned_at' in repo and 'earned_member_count' in repo
    assert 'rebuildAchievements' in builder and 'membership-threshold' in builder and 'game-time' in builder
    assert 'mca-win-5' in builder and 'mca-win-10' in builder

def test_tournament_medals():
    html=text('Tournaments.html')
    assert 'medal-count-cell medal-gold' in html and 'medal-count-cell medal-silver' in html and 'medal-count-cell medal-bronze' in html
    assert 'rank-medal-gold' not in html and 'medal-head' in html
