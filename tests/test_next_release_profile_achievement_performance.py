from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
def text(path): return (ROOT / path).read_text(encoding='utf-8')

def function_slice(source, name, next_name=None):
    start = source.index(f'function {name}')
    if next_name:
        end = source.index(f'function {next_name}', start + 1)
    else:
        end = len(source)
    return source[start:end]

def test_profile_and_achievement_reads_do_not_rebuild_analytics():
    repo = text('server/team-points/src/Repository.php')
    profile = function_slice(repo, 'publicPlayerProfile', 'achievementCountMap')
    catalog = function_slice(repo, 'publicAchievementCatalog', 'publicMatchDetail')
    assert 'refreshAnalyticsForRead' not in profile
    assert 'refreshAchievementsForRead' not in profile
    assert 'refreshAnalyticsForRead' not in catalog
    assert 'refreshAchievementsForRead' not in catalog
    assert 'projectedPlayerSummary' in profile
    assert 'p2k_an_player_monthly' in profile
    assert 'summary($clubSlug)' not in profile

def test_achievement_wall_has_dedicated_materialized_endpoint():
    repo = text('server/team-points/src/Repository.php')
    page = text('TournamentAchievementBadgesDemo.html')
    endpoint = text('server/team-points/public/achievement-players.php')
    assert 'publicAchievementPlayers' in repo
    assert 'p2k_an_player_totals' in repo
    assert 'ORDER BY COALESCE(a.achievement_count,0) DESC,p.username ASC' in repo
    assert 'achievement-players.php' in page
    assert 'members-insights.php?${params}' not in page
    assert 'ResponseCache' in endpoint

def test_public_materialized_endpoints_use_real_response_cache_and_etags():
    cache = text('server/team-points/src/ResponseCache.php')
    http = text('server/team-points/src/Http.php')
    profile = text('server/team-points/public/player-profile.php')
    achievements = text('server/team-points/public/achievements.php')
    assert 'public-response-cache' in cache
    assert 'stale_until_epoch' in cache
    assert 'jsonCacheable' in http and 'ETag:' in http and 'stale-while-revalidate' in http
    assert "'player-profile|'" in profile and 'jsonCacheable' in profile
    assert "'achievement-catalog|'" in achievements and 'jsonCacheable' in achievements

def test_generation_tokens_are_cheap_refresh_state_reads():
    repo = text('server/team-points/src/Repository.php')
    segment = function_slice(repo, 'publicReadMeta', 'publicMemberInsights')
    assert 'p2k_an_refresh_state' in segment
    assert 'p2k_lr_processing_state' in segment
    assert 'sourceWatermark' not in segment
    assert 'COUNT(*)' in segment  # current-member summary only, not Core source scanning

def test_cron_owns_materialized_refresh_with_bounded_worker_budget():
    cron = text('server/team-points/public/cron.php')
    loop = text('server/team-points/src/CronLoop.php')
    coord = text('server/team-points/src/CronMaintenanceCoordinator.php')
    assert 'CronMaintenanceCoordinator' in cron and 'refreshIfDue' in coord and 'refreshAchievementsIfDue' in coord
    assert cron.index("$loop->execute($chainId,'cron',$workerBudget)") < cron.index('(new CronMaintenanceCoordinator')
    assert '$workerBudget' in cron and 'ClubIntelligenceService' not in loop
    assert '?int $workerBudgetSeconds = null' in loop

def test_browser_reuses_profile_and_achievement_payloads_and_avatars_do_not_block_wall():
    dashboard = text('assets/js/pages/dashboard-v2.js')
    page = text('TournamentAchievementBadgesDemo.html')
    assert 'const publicReadCache = new Map()' in dashboard
    assert 'loadPublicCachedJSON' in dashboard
    assert ('ttl:120000' in dashboard or 'ttl: 120000' in dashboard) and ('ttl:300000' in dashboard or 'ttl: 300000' in dashboard)
    assert 'const requestCache=new Map()' in page
    assert "cache:force?'reload':'default'" in page
    assert 'Promise.allSettled([fetchAvatars(newRows),hydrateMedals(newRows)])' in page
