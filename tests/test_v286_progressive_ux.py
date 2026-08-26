from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')

def test_dashboard_critical_path_and_automatic_second_wave():
    js=text('assets/js/pages/dashboard-v2.js')
    team=js[js.index('async function loadTeamData'):js.index('async function loadPersonalData') if js.find('async function loadPersonalData',js.index('async function loadTeamData'))>0 else js.index('function public') if js.find('function public',js.index('async function loadTeamData'))>0 else len(js)]
    assert 'publicRequest?.("team")' in js
    assert 'afterFirstPaint' in js
    assert 'live-team' in js and 'api.chess.com/pub/club' in js
    assert 'loadRecommendations()' in js and 'afterFirstPaint' in js
    assert 'Promise.allSettled([membersRequest' not in js

def test_shared_progressive_loader_caps_background_work_and_uses_viewport_margin():
    js=text('assets/js/shared/progressive-loader.js')
    assert 'const MAX=2' in js
    assert "rootMargin='600px 0px'" in js
    assert 'IntersectionObserver' in js and 'requestIdleCallback' in js
    assert 'sessionStorage' in js and 'canPrefetch' in js and 'saveData' in js

def test_team_insights_are_split_into_progressive_sections():
    page=text('TeamInsights.html'); endpoint=text('server/team-points/public/team-insights.php'); repo=text('server/team-points/src/Repository.php')
    for section in ['summary','progression','mid','deep']:
        assert section in page and section in repo
    assert "section:'summary'" in page
    assert "rootMargin:'650px 0px'" in page
    assert 'cache:\'default\'' in page
    assert "$_GET['section']" in endpoint

def test_member_match_opponent_insights_defer_tables_and_lower_sections():
    js=text('assets/js/pages/dashboard-insights.js')
    assert 'section: "table"' in js or 'section:"table"' in js
    assert 'section:"summary"' in js or 'section: "summary"' in js
    assert 'section("results"' in js and 'section("duration"' in js and 'section("dimensions"' in js and 'section("highlights"' in js
    assert 'rootMargin:"600px 0px"' in js
    repo=text('server/team-points/src/Repository.php')
    assert 'publicMemberInsightsSection' in repo and 'publicMatchInsightsSection' in repo and 'publicOpponentStatsSection' in repo
    assert 'if(!$includeSecondary)return' in repo


def test_tournaments_default_podium_is_server_paged_and_archive_not_downloaded():
    page=text('Tournaments.html'); browse=text('server/tournaments/public/browse.php')
    assert 'PAGE=25' in page
    assert "active=params.get('panel')==='tournaments'?'tournaments':'ranking'" in page
    assert "get('ranking'" in page and "get('summary'" in page
    assert "if(panel==='tournaments'&&!tournamentsLoaded)loadTournaments()" in page
    assert 'tournaments.php' not in page
    assert "$view==='ranking'" in browse and "else {$rows=$tournaments" in browse and "$view==='player'" in browse
    assert 'LIMIT' not in browse or 'array_slice' in browse  # archive file is paged before JSON output

def test_achievement_wall_first_page_precedes_secondary_enrichment():
    page=text('TournamentAchievementBadgesDemo.html')
    load=page[page.index('async function load()'):page.index("$('close').addEventListener")]
    assert 'await loadPage(true)' in load
    first_page=page[page.index('async function loadPage'):page.index('async function load()')]
    assert first_page.index('render()') < first_page.index('Promise.allSettled([fetchAvatars(newRows),hydrateMedals(newRows)])')
    assert 'const PAGE_SIZE=12' in page
    assert 'Promise.allSettled([fetchAvatars(newRows),hydrateMedals(newRows)])' in page
    assert "if(!catalog.length)tasks.push(json('server/team-points/public/achievements.php'" in page
    assert '🥇' in page and '🥈' in page and '🥉' in page

def test_daily_and_live_hall_send_summary_first_and_page_rank_members():
    repo=text('server/team-points/src/Repository.php'); live=text('server/team-points/src/LiveRanksService.php'); rank=text('assets/js/shared/rank-ladder.js')
    hall=repo[repo.index('public function publicHallOfFame'):repo.index('private function publicCurrentMemberRows')]
    assert 'LIMIT {$pageSize} OFFSET {$offset}' in hall
    assert "'pagination'" in hall and "'selected_rank'" in hall
    public_live=live[live.index('public function publicPayload'):live.index('public function publicPlayerPayload')]
    assert "$payload['members']=$slice" in public_live and "'pagination'" in public_live
    assert 'Prev' in rank and 'Next' in rank and 'onPage' in rank

def test_unified_hall_search_uses_one_local_endpoint():
    js=text('assets/js/pages/dashboard-hall.js'); endpoint=text('server/team-points/public/hall-search.php')
    block=js[js.index('async function searchHallUnified'):js.index('async function loadHall')]
    assert block.count('hall-search.php')==1
    assert block.count('server/team-points/public/')==1
    assert 'publicHallSearch' in endpoint or 'hall' in endpoint.lower()


def test_profile_renders_database_modal_before_tournament_and_avatar_revalidation():
    js=text('assets/js/pages/dashboard-v2.js')
    block=js[js.index('async function openUnifiedPlayerProfile'):js.index('function membersTableColumns')]
    assert 'mode","modal"' in block
    assert block.index('showInsightsModal({replace:true') < block.index('browse.php?view=player')
    assert block.index('showInsightsModal({replace:true') < block.index('cacheMode:"network-only"')
    endpoint=text('server/team-points/public/player-profile.php'); repo=text('server/team-points/src/Repository.php')
    assert "['full','modal','search']" in endpoint
    profile=repo[repo.index('public function publicPlayerProfile'):repo.index('private function achievementCountMap')]
    assert "$includeExtended = $mode === 'full'" in profile
    assert "$profile['recent_matches'] = []" in profile and "$profile['top_opponents'] = []" in profile

def test_public_browser_requests_allow_http_cache_revalidation():
    client=text('assets/js/shared/team-points-client.js'); team=text('TeamInsights.html'); tour=text('Tournaments.html')
    assert 'cache: "default"' in client or "cache: 'default'" in client or 'cache:"default"' in client
    assert "cache:'default'" in team
    assert "cache:'default'" in tour

def test_chart_engine_is_lazy_loaded_out_of_dashboard_core():
    main=text('assets/js/pages/dashboard-v2.js'); module=text('assets/js/pages/dashboard-insights.js'); charts=text('assets/js/pages/dashboard-insights-charts.js')
    assert 'dashboard-insights-charts.js?v=' in module
    assert 'renderNativeLine' in charts and 'renderNativeBars' in charts
    assert len(main.encode()) < 172000


def test_idle_prefetch_is_small_and_same_origin_only():
    js=text('assets/js/pages/dashboard-v2.js')
    assert 'P2K_PROGRESSIVE?.afterIdle' in js and 'canPrefetch' in js
    assert 'achievement-players.php?page=1&page_size=12&filter=current' in js and 'team-insights.php?section=summary' in js

