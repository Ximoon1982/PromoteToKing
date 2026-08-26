from pathlib import Path
import json

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_release_contract():
    assert text('VERSION').strip() in {'2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    repo=text('server/team-points/src/Repository.php')
    assert any(f'private const CORE_SCHEMA_VERSION = {v};' in repo for v in [11,12,13,14,15])
    assert any(f'private const ANALYTICS_SCHEMA_VERSION = {v};' in repo for v in [6,7])
    assert 'core-migration-post-v2.9.7-observation-provenance.sql' in repo
    manifest=json.loads(text('site-manifest.json'))
    assert manifest['version'] in {'2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'} and manifest['releaseVersion']==manifest['version']
    assert manifest['v298Release']['coreSchema']==11
    assert manifest['v298Release']['analyticsSchema']==6

def test_members_current_month_is_future_side():
    js=text('assets/js/pages/dashboard-insights.js')
    assert 'previousMonthDate=new Date(Date.UTC(now.getUTCFullYear(),now.getUTCMonth()-1,1))' in js
    assert 'futureBoundary:{key:previousMonth,fraction:0' in js
    assert 'Current month · incomplete' in js

def test_team_insights_daily_points_and_active_boards():
    page=text('TeamInsights.html'); repo=text('server/team-points/src/Repository.php')
    assert 'Daily Club Points are shown on the right axis only through today and are never projected.' in page
    assert "name:'Daily Club Points'" in page and 'right:true' in page and '.filter(x=>!sp.today||x.date<=sp.today)' in page
    assert 'Daily boards and current active boards' in page and "name:'Current active boards'" in page
    assert "'activeBoards'=>max(0,$cumBoardsStarted-$cumBoardsFinished)" in repo
    assert "'dailyBoards'=>array_map" in repo and "'active'=>$r['activeBoards']" in repo

def test_opponent_heatmap_explains_authoritative_coverage():
    js=text('assets/js/pages/opponent-balance-analyzer.js'); repo=text('server/team-points/src/Repository.php')
    assert 'sync_match' in js and 'CRON/worker queue' in js
    assert 'paired_match_percent' in js and "'paired_rating_matches'" in repo and "'paired_match_percent'" in repo

def test_hall_search_achievement_count_uses_real_player_profile():
    php=text('server/team-points/public/hall-search.php')
    assert "$player=is_array($profile)?$profile:[];" in php
    assert "$profile['player']" not in php
    assert 'count(AchievementCatalog::all())' in php

def test_real_oauth_is_server_side_pkce_and_fake_oauth_stays_serial():
    real=text('assets/js/shared/real-oauth.js'); fake=text('assets/js/shared/simulated-oauth.js')
    api=text('assets/js/shared/api-client.js'); oauth=text('server/team-points/src/OAuthSession.php'); endpoint=text('server/team-points/public/oauth.php')
    assert '?oauth=2' in real and 'action=logout' in real and 'X-P2K-OAuth-CSRF' in real
    assert 'searchParams.set("action", "batch")' in api and 'oauth.php' in api and 'setOAuthBearerMode' in api
    assert 'code_challenge_method' in oauth and "'S256'" in oauth and "'state'" in oauth
    assert 'nonce' not in oauth.lower()
    assert "hash_equals('RS256'" in oauth and "hash_equals('https://oauth.chess.com'" in oauth
    assert 'session_write_close();' in oauth and 'curl_multi_init' in oauth and "'Authorization: Bearer '." in oauth
    assert 'api.chess.com/pub/' in oauth
    assert "if($action==='batch')" in endpoint and "if($action==='logout')" in endpoint
    assert 'apiConcurrent: false' in fake and 'setConcurrentMode?.(false)' in fake
    assert 'Concurrent Chess.com API access' not in fake

def test_admin_has_rolling_oauth_throughput_graph():
    ui=text('ui-v2.html'); js=text('assets/js/pages/dashboard-v2.js'); rt=text('server/team-points/src/RuntimeTelemetry.php'); endpoint=text('server/team-points/public/oauth.php')
    assert 'OAuth Bearer throughput · rolling 10 minutes' in ui
    assert 'adminApiThroughputChart' in ui and 'Average req/s' in ui and 'Peak req/s' in ui
    assert 'average_rps' in js and 'peak_rps' in js and 'minutes=10' in js
    assert 'chessApiThroughput' in rt and 'average_rps' in rt and 'peak_rps' in rt
    assert "Auth::requireAdmin()" in endpoint and "action==='throughput'" in endpoint

def test_oauth_installer_defaults_to_production_callback():
    sh=text('install-oauth-v2.9.8.sh'); example=text('server/team-points/config/oauth.local.example.php')
    assert '/auth/callback' in sh and 'openid profile' in sh
    assert '/auth/callback' in example and "'scope' => 'openid profile'" in example
