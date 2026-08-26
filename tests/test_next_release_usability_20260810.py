from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]

def text(path):
    return (ROOT/path).read_text(encoding='utf-8')

def test_hall_search_results_forced_full_width():
    css=text('assets/css/dashboard-v2.css')
    assert '#hallOfFamePage{grid-template-columns:minmax(0,1fr)!important' in css
    assert '#hallOfFamePage>*,#hallUnifiedResults,.dashboard-hall-unified-search' in css
    assert 'width:100%!important' in css

def test_team_depth_excludes_unrated_bands_and_has_metrics():
    php=text('server/team-points/src/ClubIntelligenceService.php')
    js=text('assets/js/pages/club-intelligence.js')
    css=text('assets/css/club-intelligence.css')
    assert "if($rating===null || $rating<=0){$stats['unrated']++;continue;}" in php
    assert 'teamDepthReport' in php and 'rating_coverage_percent' in php
    assert 'Unrated members are counted in the metrics but excluded from the rating-axis plot.' in js
    for token in ['ci-depth-metrics','is-active','is-available','is-overloaded']:
        assert token in css

def test_team_insights_member_activity_multi_filter_and_paging():
    html=text('ui-v2.html')
    js=text('assets/js/pages/dashboard-insights.js')
    endpoint=text('server/team-points/public/members-insights.php')
    repo=text('server/team-points/src/Repository.php')
    assert 'id="membersActivityStatusFilter"' in html
    for status in ['active','cooling','inactive','dormant','unknown']:
        assert f'value="{status}"' in html
    assert 'id="membersTablePager"' in html
    assert 'url.searchParams.set("page_size", "25")' in js
    assert 'activity_status' in js and 'activity_status' in endpoint
    assert 'memberActivityStatusList' in repo and 'memberActivityStatus' in repo
    assert "days<=30?'active':($days<=90?'cooling':($days<=180?'inactive':'dormant'))" in repo
    assert 'activity_status' in repo
    assert 'p2k-member-activity-status' in js

def test_club_intelligence_opponent_avatar_precedes_name():
    js=text('assets/js/pages/club-intelligence.js')
    m=re.search(r'function renderOpp\(d\).*?function ', js, re.S)
    block=m.group(0) if m else js[js.index('function renderOpp'):]
    img=block.find('<img src=')
    anchor=block.find('<a href=')
    assert img != -1 and anchor != -1 and img < anchor

def test_achievement_catalogues_have_name_search():
    dash=text('assets/js/pages/dashboard-v2.js')
    standalone=text('TournamentAchievementBadgesDemo.html')
    assert 'Search achievement names' in dash
    assert 'data-achievement-search' in dash
    assert 'data-achievement-name' in dash
    assert 'Search achievement names' in standalone
    assert 'id="catalogSearch"' in standalone
    assert 'data-achievement-name' in standalone

def test_rank_based_achievement_art_and_names_are_aligned():
    catalog=text('server/team-points/src/AchievementCatalog.php')
    assert "self::item('daily-king','King Daily Rank'" in catalog
    assert "assets/images/ranks/06_King_250_points.png" in catalog
    assert "self::item('daily-silver-king','Silver King Daily Rank'" in catalog
    assert "assets/images/ranks/08_Silver_King_1000_points.png" in catalog
    assert "self::item('live-rank-pawn','Live Pawn'" in catalog
    assert "assets/images/live-ranks/01_Live_Pawn_50_points.png" in catalog
