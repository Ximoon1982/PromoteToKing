from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding='utf-8')


def block(source, start, end):
    a = source.index(start)
    b = source.index(end, a)
    return source[a:b]


def test_mlp_keeps_browser_lifecycle_noncanonical_but_queues_authoritative_index_audit():
    repo = text('server/team-points/src/Repository.php')
    ingest = text('server/team-points/src/ObservationIngestor.php')
    passive = block(repo, 'public function recordObservedClubMatchReference', '/**\n     * MLP Fix')
    assert "'unknown',?" in passive
    assert 'observed_status=VALUES(observed_status)' in passive
    assert re.search(r'(?<!observed_)status=VALUES\(observed_status\)', passive) is None
    assert 'public function observedClubLifecycleAuditDue' in repo
    assert "observed_status IN ('registered','in_progress','finished')" in repo
    assert "COALESCE(status,'unknown')<>observed_status" in repo
    club = block(ingest, 'private function clubMatches', 'private function playerMatches')
    assert 'observedClubLifecycleAuditDue($this->clubSlug)' in club
    assert "'sync_club_matches'" in club
    assert "'mlp_fix'=>true" in club
    assert "'priority_discovery'=>true" in club
    assert "'canonical_match_status_written'=>false" in club
    assert "'lifecycle_audit_queued'=>$lifecycleAuditQueued" in club


def test_mlp_uses_existing_canonical_queue_identity_for_coalescing():
    repo = text('server/team-points/src/Repository.php')
    assert "'sync_club_matches'=>'club-match-index'" in repo
    assert 'strongerQueueType' in repo
    assert 'mergeQueuePayloads' in repo


def test_drr_dashboard_recommendations_use_recent_cache_as_fail_soft_fallback():
    finder = text('assets/js/pages/find-match.js')
    dashboard = text('assets/js/pages/dashboard-v2.js')
    assert 'function readDashboardCache(username)' in finder
    assert 'Date.now() - Number(snapshot.savedAt || 0) > 30 * 60 * 1000' in finder
    assert 'const dashboardFallbackSnapshot = dashboardRecommendationMode ? readDashboardCache(username) : null' in finder
    assert 'Dashboard Recommendation Resilience fallback used.' in finder
    assert 'dashboardRecommendationFromCache = true' in finder
    assert 'dashboardRecommendationTerminal = true' in finder
    assert 'if (!dashboardRecommendationFromCache) persistDashboardCache(username)' in finder
    assert 'cached: Boolean(dashboardRecommendationFromCache)' in finder
    assert 'terminal: Boolean(dashboardRecommendationTerminal)' in finder
    assert 'warning: String(dashboardRecommendationWarning || "")' in finder
    assert 'recommendationFallbackVisible' in dashboard
    assert 'preserveExisting: state.recommendationFallbackVisible' in dashboard
    assert 'if (cached && recommendations.length)' in dashboard
    assert 'event.origin !== window.location.origin' in dashboard


def test_general_catalogue_hides_progress_bars_but_player_catalogue_keeps_them():
    dashboard = text('assets/js/pages/dashboard-v2.js')
    demo = text('TournamentAchievementBadgesDemo.html')
    assert 'const showCatalogueProgress=Boolean(name);' in dashboard
    assert 'showCatalogueProgress?achievementDisplayBar(achievedCount,groupTotal' in dashboard
    assert 'showCatalogueProgress?achievementDisplayBar(familyAchieved,total' in dashboard
    assert 'progress:progressByKey.get(key)||null' in dashboard
    assert 'if(name){const profileURL=' in dashboard and 'member-intelligence.php' in dashboard
    assert 'const showCatalogueProgress=Boolean(username);' in demo
    assert 'showCatalogueProgress?displayBar(done,count' in demo
    assert 'showCatalogueProgress?displayBar(familyDone,total' in demo
