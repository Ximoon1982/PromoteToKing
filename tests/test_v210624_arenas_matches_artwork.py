from pathlib import Path
import json
from PIL import Image

ROOT=Path(__file__).resolve().parents[1]
def read(rel): return (ROOT/rel).read_text(encoding='utf-8')

MATCH_KEYS=['first-match','matches-10','matches-50','matches-100','matches-250','matches-500','matches-1000']

def test_arena_public_endpoint_is_results_and_mira_backed_without_schema_change():
    endpoint=read('server/team-points/public/arenas-insights.php')
    service=read('server/team-points/src/LiveRanksService.php')
    assert 'publicArenasInsights' in service
    assert 'p2k_lr_arena_stats' in service and 'p2k_lr_files' in service
    assert 'p2k_lr_source_rows' in service and 'p2k_lr_attributions' in service
    assert 'canonical_username_key' in service
    assert 'p2k_lr_players' in service
    assert 'publicReadGenerationToken($club, false, true)' in endpoint
    assert 'ResponseCache' in endpoint
    assert 'games.csv' not in endpoint.lower()

def test_arena_metrics_cover_requested_first_release_scope():
    service=read('server/team-points/src/LiveRanksService.php')
    for marker in ['unique_players','victories','podiums','top10_finishes','best_finish','average_p2k_players',
                   'p2k_share_percent','best_percentile','cumulative_points','score_percent',
                   'longest_participation_streak','current_participation_streak']:
        assert marker in service
    assert '100 * max(0, $fieldSize - $bestRank) / max(1, $fieldSize - 1)' in service
    assert '100 * ($wins + 0.5 * $draws) / $games' in service

def test_arena_navigation_and_native_ui_are_integrated():
    ui=read('ui-v2.html')
    dash=read('assets/js/pages/dashboard-v2.js')
    insights=read('assets/js/pages/dashboard-insights.js')
    for marker in ['data-insights-subtab="arenas"','data-insights-panel="arenas"','arenasParticipationChart','arenasPlacementChart','arenasPercentileChart','arenasPointsChart','arenasResultsChart','arenasScoreChart','arenasLeadersTable','arenasDataTable']:
        assert marker in ui
    assert '["team", "members", "matches", "arenas", "opponents"]' in dash
    assert 'loadArenaInsights' in dash and 'loadArenaInsights' in insights
    assert 'server/team-points/public/arenas-insights.php' in insights
    assert 'openArenaDetail' in insights

def test_rank_chart_supports_inverted_axis_without_changing_default_contract():
    charts=read('assets/js/pages/dashboard-insights-charts.js')
    assert 'invertY = false' in charts
    assert 'yMin = null' in charts and 'yMax = null' in charts
    assert 'axisFormatter = null' in charts
    assert 'invertY ? margin.top + innerHeight * ratio' in charts
    insights=read('assets/js/pages/dashboard-insights.js')
    assert 'invertY: true' in insights
    assert 'axisFormatter: value => `#${Math.max(1, Math.round(value))}`' in insights

def test_matches_artwork_replaces_placeholders_and_adds_1000_milestone():
    catalog=read('server/team-points/src/AchievementCatalog.php')
    builder=read('server/team-points/src/AnalyticsBuilder.php')
    for key in MATCH_KEYS:
        assert f"assets/images/achievements/{key}.png" in catalog
        assert f"assets/images/achievements/thumbs/128/{key}.webp" in catalog
        assert f"self::placeholder('{key}')" not in catalog
    assert "self::item('matches-1000','Match Immortal'" in catalog
    assert 'foreach([10,50,100,250,500,1000] as $n)' in builder
    # First Step remains unrelated to the approved Matches artwork batch.
    assert "self::placeholder('first-point')" in catalog

def test_matches_artwork_dimensions_and_derivatives():
    manifest=json.loads(read('POST_v2.10.6.24_MATCHES_ARTWORK_INTEGRATION.json'))
    assert manifest['release']=='2.10.6.24'
    assert len(manifest['items'])==7
    by_key={row['key']:row for row in manifest['items']}
    assert set(by_key)==set(MATCH_KEYS)
    for key in MATCH_KEYS:
        master=ROOT/by_key[key]['master']; thumb=ROOT/by_key[key]['thumb_128']; mini=ROOT/by_key[key]['mini_64']; legacy=ROOT/by_key[key]['mini_legacy']
        with Image.open(master) as im: assert im.size==(640,640)
        with Image.open(thumb) as im: assert im.size==(128,128) and im.format=='WEBP'
        with Image.open(mini) as im: assert im.size==(64,64) and im.format=='WEBP'
        assert mini.read_bytes()==legacy.read_bytes()

def test_no_games_csv_only_arena_analytics_are_presented():
    ui=read('ui-v2.html').lower()
    insights=read('assets/js/pages/dashboard-insights.js').lower()
    for forbidden in ['arena openings','arena color performance','arena opponent rating','arena game duration','arena upset']:
        assert forbidden not in ui
        assert forbidden not in insights
