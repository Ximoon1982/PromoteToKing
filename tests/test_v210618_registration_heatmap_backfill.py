from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def read(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_registration_lineup_is_current_snapshot():
    r=read('server/team-points-green/src/GreenRepository.php')
    assert "DELETE FROM p2k_g_match_players WHERE match_id=? AND is_p2k=? AND username_key NOT IN" in r
    assert 'events remain untouched historical evidence' in r

def test_compatibility_enforces_one_canonical_member_per_match():
    c=read('server/team-points-green/src/GreenCompatibility.php')
    assert '$usedMembers=[]' in c
    assert 'member_duplicates_resolved' in c
    assert "COUNT(DISTINCT COALESCE(im2.canonical_username_key,mp2.username_key))" in c
    assert "COUNT(DISTINCT CONCAT(mp.match_id,':',COALESCE(im.canonical_username_key,mp.username_key)))" in c

def test_heatmap_backfill_is_known_finished_match_only_and_accelerated():
    r=read('server/team-points-green/src/GreenRepository.php')
    api=read('server/team-points-green/public/api.php')
    obs=read('server/team-points-green/public/observe.php')
    acc=read('assets/js/shared/green-accelerator.js')
    assert 'startHeatmapBackfill' in r and ("kind='heatmap_match_detail'" in r or "kind='heatmap_board_detail'" in r)
    assert "gm.status='finished'" in r and "gm.club_verified=1" in r and "gm.time_class='daily'" in r
    assert 'heatmapBackfillPlan' in r and ("'heatmap_match_detail'" in r or "'heatmap_board_detail'" in r) and "'heatmap'=>true" in r
    assert "start-heatmap-backfill" in api and "report-heatmap-failure" in api
    assert "declared==='heatmap_match_detail'" in obs or "declared==='heatmap_board_detail'" in obs
    assert ('"heatmap_match_detail"' in acc or '"heatmap_board_detail"' in acc) and 'report-heatmap-failure' in acc

def test_gab_external_counters_exclude_heatmap_work():
    g=read('server/team-points-green/src/GreenAnalyticsBootstrap.php')
    assert g.count("kind='gab_opponent_profile'") >= 4

def test_task_control_exposes_heatmap_workflow_and_coverage():
    h=read('TaskControl.html'); js=read('assets/js/pages/task-control.js')
    assert 'Historical heatmap backfill' in h
    assert 'greenHeatmapStart' in h and 'greenHeatmapRestart' in h and 'greenHeatmapMetrics' in h
    assert 'paired_rating_matches' in js and 'start-heatmap-backfill' in js

def test_heatmap_browser_cache_refreshes_promptly():
    d=read('assets/js/pages/dashboard-insights.js')
    a=read('assets/js/pages/opponent-balance-analyzer.js')
    assert 'opponents-balance-v4' in d
    assert 'snapshotGet?.(cacheKey,5*60000)' in d
    assert 'Historical heatmap backfill' in a and 'Green Accelerator' in a
