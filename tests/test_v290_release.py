from pathlib import Path
from PIL import Image

ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8')

def test_v290_identity_and_cron_contract():
    assert text('VERSION').strip() in {'2.9.0','2.9.1','2.9.2','2.9.4','2.9.5','2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    cfg=text('assets/js/site-config.js')
    assert any(f'version: "{v}"' in cfg for v in ['2.9.0','2.9.1','2.9.2','2.9.4','2.9.5','2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2']) and 'trafficAnalytics' in cfg and any(f'chart-maximize.js?v={v}' in cfg for v in ['2.9.0','2.9.1','2.9.2','2.9.4','2.9.5','2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'])
    dispatch=text('cron-dispatch-v2.9.0.sh'); install=text('reset-install-cron-v2.9.0.sh')
    assert '--max-time 55' in dispatch and 'PromoteToKing-Cron/2.9.0' in dispatch
    assert '/usr/bin/php8.5-cli' in dispatch and 'PHP_SAPI === "cli"' in dispatch
    assert 'cron-dispatch-v2.9.0.sh' in install and 'P2K_PHP_CLI=' in install
    assert 'Shared CRON token: repaired from protected Team Points configuration.' in install
    assert 'trafficAnalyticsSecret' in install

def test_v290_player_worker_is_bounded_and_retry_safe():
    w=text('server/team-points/src/Worker.php')
    assert 'player_match_entries_per_item' in w
    assert ('bounded_cached_player_match_slices_v290' in w or 'bounded_cached_player_match_slices_v297' in w)
    assert "['slice_chain']" in w and "'_queue_item_key'" in w
    assert 'player-slice:' in w and '$sliceChain' in w
    assert 'absoluteDeadlineAt' in w and 'hasOutboundRequestBudget' in w
    assert 'hasProcessingBudget' in w

def test_v290_historical_revalidation_is_bounded_low_priority_and_non_destructive():
    q=text('queue-history-revalidation-v2.9.0.php'); r=text('server/team-points/src/Repository.php')
    assert 'PER_SLOT=25' in q and 'SLOT_SECONDS=300' in q
    assert 'club-points-scoring-before.tsv' in q and 'historical_full_revalidation_v290' in q
    assert 'history-revalidate-v290:' in q and 'history-revalidate-v290:' in r
    assert 'p2k_tp_match_summaries' in q and 'sync_match' in q

def test_v290_achievement_families_and_placeholders():
    c=text('server/team-points/src/AchievementCatalog.php'); b=text('server/team-points/src/AnalyticsBuilder.php')
    for key in ['same-day-matches-2','same-day-matches-3','same-day-matches-4','same-day-matches-5',
                'concurrent-games-5','concurrent-games-10','concurrent-games-25','concurrent-games-50','concurrent-games-100',
                'groups-1','groups-5','groups-10','groups-15','groups-20']:
        assert key in c and (ROOT/f'assets/images/achievements/placeholders/{key}.svg').is_file()
    assert 'eligibleBreadthCategories' in c and 'achievement-breadth' in c
    assert 'same_day' in b.lower() or 'same-day' in b.lower()
    assert 'reconstructed-game-interval' in b
    assert 'AchievementCatalog::eligibleBreadthCategories()' in b
    assert 'p2k-logo.jpg' not in '\n'.join(line for line in c.splitlines() if 'self::item(' in line)
    assert 'assets/images/achievements/placeholders/' in c
    assert (ROOT/'assets/images/achievements/placeholders/generic.svg').is_file()

def test_v290_achievement_leaderboard_and_challenge_focus():
    a=text('TournamentAchievementBadgesDemo.html'); d=text('assets/js/pages/dashboard-v2.js')
    assert 'Highlights' in a and 'Leaderboard' in a and '<th>Achievements</th>' in a
    assert 'live_rank' in text('server/team-points/src/Repository.php')
    assert 'openAchievementCatalog' in d and 'focusKey' in d and 'data-challenge-achievement' in d
    assert 'Showing 12 of 4,473 matching players. Team Points retain half-point values.' not in a

def test_v290_cookieless_first_party_traffic_and_no_statcounter():
    t=text('server/shared/TrafficAnalytics.php'); js=text('assets/js/shared/traffic-analytics.js')
    all_active='\n'.join(p.read_text(encoding='utf-8',errors='ignore') for p in ROOT.rglob('*') if p.is_file() and 'tests' not in p.parts and 'data' not in p.parts and '_backup' not in p.parts and p.suffix.lower() in {'.html','.htm','.js','.php','.md','.json'})
    assert 'statcounter' not in all_active.lower()
    assert 'raw IP addresses and full referrer URLs are never persisted' in t
    assert 'PSEUDONYM_RETENTION_SECONDS = 86400' in t and 'SESSION_IDLE_SECONDS = 1800' in t
    assert 'HTTP_SEC_GPC' in t and 'HTTP_DNT' in t and 'isBot' in t
    assert 'estimated_unique_visitor_days' in t and 'latest_daily_unique_visitors' in t
    bad=(js+t).lower()
    assert 'localstorage.setitem' not in bad and 'sessionstorage.setitem' not in bad and 'document.cookie' not in bad

def test_v290_club_intelligence_traffic_and_sorting():
    h=text('ClubIntelligence.html'); js=text('assets/js/pages/club-intelligence.js')
    assert 'Traffic / Visitors' in h and 'renderTraffic' in js
    assert 'installTableSorting' in js and 'aria-sort' in js
    assert 'latest_daily_unique_visitors' in js and 'Unique visitor-days' in js

def test_v290_runtime_diagnostics_restores_versions_models_and_cron():
    h=text('InsightsHealth.html'); js=text('assets/js/pages/runtime-diagnostics.js'); api=text('api/router.php')
    assert 'Runtime Diagnostics' in h and 'Copy sanitized snapshot' in h
    assert 'modelVersions' in js and 'assetVersions' in js and 'contexts' in js
    for s in ['package_version','manifest_version','site_config_version','core_schema','analytics_schema']:
        assert s in api
    for key in ['team-points-club','tournaments','team-points-player','match-tracking']:
        assert key in api

def test_v290_chart_requirements_and_visibility_controls():
    team=text('TeamInsights.html'); dash=text('assets/js/pages/dashboard-insights.js'); maximize=text('assets/js/shared/chart-maximize.js')
    assert 'hiddenSeries' in team and 'boardsLegend' in team and 'renderSeriesLegend' in team
    assert 'showValues:true' in team and 'boardSizeStats' in team
    assert 'futureFrom' in team and 'Club Points by month' in team
    assert 'p2k-global-chart-modal' in maximize and 'placeholder.replaceWith(target)' in maximize
    assert 'canvas' not in maximize.lower() and 'bitmap' not in maximize.lower()
    assert 'showValues:true' in dash  # Daily-rank distribution

def test_v290_live_rook_has_safe_bottom_margin():
    for rel in ['assets/images/live-ranks/04_Live_Rook_2500_points.png','assets/images/live-ranks/thumbs/640/04_Live_Rook_2500_points.webp']:
        im=Image.open(ROOT/rel).convert('RGB')
        px=im.load(); w,h=im.size
        # Bottom 8 rows must be essentially empty/dark, preventing modal clipping.
        assert max(max(px[x,y]) for y in range(max(0,h-8),h) for x in range(w)) < 20

def test_v290_no_loss_pluralization_regression():
    active='\n'.join(text(p.relative_to(ROOT)) for p in ROOT.rglob('*.php') if 'tests' not in p.parts)
    assert 'losss' not in active.lower()
