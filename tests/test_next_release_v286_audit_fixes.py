from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')

def block(src,start,end):
    a=src.index(start); b=src.index(end,a); return src[a:b]

def test_dashboard_data_path_is_restored_to_v286_contract():
    repo=text('server/team-points/src/Repository.php'); js=text('assets/js/pages/dashboard-v2.js')
    dash=block(repo,'public function publicClubDashboard','public function publicHallOfFame')
    assert 'p2k_tp_club_totals' in dash and 'p2k_tp_match_summaries' in dash
    assert 'currentMemberCountProjected' in dash and "cache_mode'=>'analytics_projection_from_core'" in dash
    assert '$this->state(' not in dash and '$this->readState(' not in dash
    load=block(js,'async function loadTeamData','function renderGauge')
    assert 'await (window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("team")' in load
    assert 'clubMembersAPI' not in load and 'loadJSON(clubProfileAPI)' not in load and 'clubMatchesAPI' in load
    assert 'setMatchMetric("registered"' in load and 'setMatchMetric("ongoing"' in load and 'setMatchMetric("finished"' not in load
    assert 'lowPriority' not in load and 'afterFirstPaint' in load

def test_dashboard_player_first_wave_uses_materialized_projection_and_core_join_date():
    repo=text('server/team-points/src/Repository.php'); js=text('assets/js/pages/dashboard-v2.js')
    player=block(repo,'public function publicPlayerSummary','public function publicClubDashboard')
    assert '$this->projectedPlayerSummary' in player
    assert 'joined_at,first_seen_at,last_seen_at' in player
    assert "array_key_exists('current_member',$core)" in player
    assert 'publicCurrentMemberRows(' not in player
    assert 'state.playerPoints?.joined_at' in js

def test_profile_core_membership_overrides_projection():
    repo=text('server/team-points/src/Repository.php')
    profile=block(repo,'public function publicPlayerProfile','private function achievementCountMap')
    assert 'current_member,daily_rating' in profile
    assert "array_key_exists('current_member',$member)" in profile
    assert "$profile['team_position']=null" in profile and "$profile['category_position']=null" in profile

def test_club_resolution_is_indexed_and_memoized():
    repo=text('server/team-points/src/Repository.php')
    resolver=block(repo,'private function resolveDataClubSlug','public function matchReadyForAuthoritativeFinalization')
    assert 'resolvedClubSlugs' in repo
    assert 'p2k_tp_state WHERE club_slug=? LIMIT 1' in resolver
    assert 'COUNT(' not in resolver and 'p2k_tp_point_events' not in resolver
    assert 'LIMIT 1' in resolver

def test_public_cache_token_contains_core_generation_and_freshness():
    repo=text('server/team-points/src/Repository.php')
    meta=block(repo,'public function publicReadMeta','private function currentMemberCountCore')
    assert "'core'=>$coreGeneration" in meta
    assert 'members_last_observed_at' in meta and 'club_index_last_observed_at' in meta
    assert "'core:'" in meta and "'analytics:'" in meta

def test_paused_player_lane_still_refreshes_roster_and_live_membership():
    cron=text('server/team-points/src/CronLoop.php'); worker=text('server/team-points/src/Worker.php'); repo=text('server/team-points/src/Repository.php')
    paused=block(cron,"if ((string)($job['status'] ?? '') === 'paused')",'// Every external CRON invocation')
    assert "$lane==='player'" in paused and 'refreshRosterOnly' in paused and 'freshness_guard' in paused
    assert 'reconcileLiveCurrentMembers' in worker
    live=block(repo,'public function reconcileLiveCurrentMembers','public function ensureSummaryIndexes')
    assert 'p2k_lr_players' in live and 'current_member=0' in live and 'current_member=1' in live

def test_custom_range_member_sections_share_one_unpaged_projection():
    endpoint=text('server/team-points/public/members-insights.php'); repo=text('server/team-points/src/Repository.php')
    assert "in_array($section,['summary','ranks','table'],true)" in endpoint
    assert "members-range-base" in endpoint and "$base['_unpaged']=true" in endpoint
    assert 'array_slice($rows' in endpoint and 'shared_range_cache' in endpoint
    range_method=block(repo,'public function publicMemberInsights(','private function publicMemberInsightsMaterialized')
    assert "$unpaged=!empty($options['_unpaged'])" in range_method
    assert 'if(!$unpaged)$rows=array_slice' in range_method

def test_daily_hall_summary_uses_sql_counts_and_rank_page_only():
    repo=text('server/team-points/src/Repository.php')
    hall=block(repo,'public function publicHallOfFame','private function publicCurrentMemberRows')
    assert 'SELECT COUNT(*) members' in hall
    assert 'ORDER BY points DESC,username_key ASC LIMIT 1' in hall
    assert 'LIMIT {$pageSize} OFFSET {$offset}' in hall
    assert 'fetchAll(PDO::FETCH_ASSOC)' in hall  # only selected/search page rows, not a full leaderboard
    assert 'baseRows' not in hall
    assert 'private function hallRankForPoints' in repo and 'public static function dailyRankDefinitions' in repo

def test_live_hall_summary_does_not_build_all_player_rows():
    live=text('server/team-points/src/LiveRanksService.php')
    public=block(live,'public function publicPayload','public function publicPlayerPayload')
    assert 'materialized_summary' in public
    assert 'SELECT COUNT(*)' in public and 'LIMIT 1' in public
    assert 'adminPlayerRows' not in public and 'publicPlayerRows' not in public
    assert 'LIMIT {$pageSize} OFFSET {$offset}' in public

def test_tournament_browse_index_is_materialized_and_304_is_early():
    browse=text('server/tournaments/public/browse.php'); index=text('server/tournaments/src/BrowseIndex.php')
    assert 'requestEtag($_GET)' in browse
    assert browse.index('HTTP_IF_NONE_MATCH') < browse.index('$index=$indexer->index()')
    assert 'browse-index-v1.json' in index and "($cached['signature']??'')===$sig" in index
    assert "'ranking_map'" in index and "'players'" in index

def test_hall_search_uses_tournament_player_index_and_signature_in_cache_key():
    endpoint=text('server/team-points/public/hall-search.php')
    assert 'BrowseIndex' in endpoint and 'tournamentGeneration' in endpoint
    assert "['players'][$needle]" in endpoint
    assert 'foreach($archive' not in endpoint and 'TournamentService' not in endpoint

def test_team_insights_progressive_sections_share_base_dataset_cache():
    repo=text('server/team-points/src/Repository.php')
    base=block(repo,'private function teamInsightsBase','public function publicTeamInsights')
    assert 'team-insights-base|' in base and 'ResponseCache' in base
    assert 'publicReadGenerationToken($clubSlug,false,false)' in base
    method=block(repo,'public function publicTeamInsights','public function publicOpponentStats')
    assert "$shared=$this->teamInsightsBase" in method and "'shared_base_cache'=>true" in method

def test_dashboard_second_wave_is_automatic_v286_style():
    js=text('assets/js/pages/dashboard-v2.js')
    load=block(js,'async function loadTeamData','function renderGauge')
    personal=block(js,'async function loadPersonalData','function matchLists')
    assert 'afterFirstPaint' in load and 'lowPriority' not in load
    assert 'afterFirstPaint' in personal and 'lowPriority' in personal
    assert 'IntersectionObserver' not in load  # Dashboard is never interaction/viewport gated.

def test_hall_and_insights_are_split_out_of_initial_dashboard_bundle():
    main=text('assets/js/pages/dashboard-v2.js'); hall=text('assets/js/pages/dashboard-hall.js'); insights=text('assets/js/pages/dashboard-insights.js')
    assert ('dashboard-hall.js?v=2.8.10' in main or 'dashboard-hall.js?v=2.8.11' in main or 'dashboard-hall.js?v=2.9.0' in main or 'dashboard-hall.js?v=2.9.1' in main or 'dashboard-hall.js?v=2.9.2' in main or 'dashboard-hall.js?v=2.9.5' in main or 'dashboard-hall.js?v=2.9.7' in main or 'dashboard-hall.js?v=2.9.8' in main or 'dashboard-hall.js?v=2.9.9' in main or 'dashboard-hall.js?v=2.9.10' in main or 'dashboard-hall.js?v=2.9.11' in main or 'dashboard-hall.js?v=2.10.4' in main) and ('dashboard-insights.js?v=2.8.10' in main or 'dashboard-insights.js?v=2.8.11' in main or 'dashboard-insights.js?v=2.9.0' in main or 'dashboard-insights.js?v=2.9.1' in main or 'dashboard-insights.js?v=2.9.2' in main or 'dashboard-insights.js?v=2.9.4' in main or 'dashboard-insights.js?v=2.9.5' in main or 'dashboard-insights.js?v=2.9.7' in main or 'dashboard-insights.js?v=2.9.8' in main or 'dashboard-insights.js?v=2.9.9' in main or 'dashboard-insights.js?v=2.9.10' in main or 'dashboard-insights.js?v=2.9.11' in main or 'dashboard-insights.js?v=2.10.4' in main)
    assert 'P2K_CREATE_DASHBOARD_HALL' in hall and 'P2K_CREATE_DASHBOARD_INSIGHTS' in insights
    assert 'async function loadMemberInsights' in insights and 'async function searchHallUnified' in hall
    assert len(main.encode()) < 172000  # post-v2.9.13 ACSR + achievement Family orchestration remains bounded

def test_duration_reference_lines_wait_for_chart_module():
    insights=text('assets/js/pages/dashboard-insights.js'); charts=text('assets/js/pages/dashboard-insights-charts.js')
    duration=block(insights,'function renderDurationDistribution','function renderMatchAnalytics')
    assert 'ensureInsightsCharts().then' in duration and 'api.renderNativeBars' in duration and 'api.nativeSVG' in duration
    assert 'nativeSVG,' in charts[charts.rindex('return Object.freeze'):]

def test_finished_dashboard_drilldown_uses_canonical_archive_page():
    js=text('assets/js/pages/dashboard-v2.js')
    drill=block(js,'async function openDashboardMatchList','function closeDashboardMatchList')
    assert 'matches-insights.php?section=table&filter=finished' in drill
    assert 'complete archive' in drill
    assert 'state.teamData?.lists?.finished' in drill  # fallback only when archive endpoint is unavailable
    assert drill.index('matches-insights.php?section=table&filter=finished') < drill.index('state.teamData?.lists?.finished')
