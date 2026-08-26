from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def read(rel): return (ROOT/rel).read_text(encoding="utf-8")

def test_heatmap_uses_board_rating_provenance_not_match_detail():
    repo=read("server/team-points-green/src/GreenRepository.php")
    compat=read("server/team-points-green/src/GreenCompatibility.php")
    obs=read("server/team-points-green/public/observe.php")
    assert "kind='heatmap_board_detail'" in repo
    assert "kind='heatmap_match_detail'" in repo  # obsolete ledger cleanup is intentional
    assert "NOT EXISTS(SELECT 1 FROM p2k_g_games" in repo
    assert "storedGameRatingPair" in compat and "white_rating" in compat and "black_rating" in compat
    assert "declared==='heatmap_board_detail'" in obs
    assert "paired_rating_observed" in obs

def test_compatibility_match_rating_average_is_strictly_paired_by_board():
    compat=read("server/team-points-green/src/GreenCompatibility.php")
    assert "$pr=is_numeric($pb['p2k_rating']" in compat
    assert "$or=is_numeric($pb['opponent_rating']" in compat
    assert "if($pr<=0||$or<=0)continue" in compat
    assert "$rated++;" in compat

def test_gabcrf_reprojects_stored_game_rating_evidence():
    compat=read("server/team-points-green/src/GreenCompatibility.php")
    marker="COALESCE(cm.rated_board_count,0)<=0)"
    assert compat.count(marker)>=2
    assert compat.count("EXISTS(SELECT 1 FROM p2k_g_games gr")>=2

def test_accelerator_heatmap_is_background_bounded_and_no_jsonp():
    acc=read("assets/js/shared/green-accelerator.js")
    assert "FEEDER_CONCURRENCY=16" in acc
    assert 'trafficClass:"background"' in acc
    assert 'jsonpFallback:false' in acc
    assert "processPriority(tasks" in acc
    assert "Promise.all(tasks.map" not in acc
    assert '"heatmap_board_detail"' in acc

def test_accelerator_ui_uses_oauth_gateway_telemetry_and_throttled_render():
    task=read("assets/js/pages/task-control.js")
    assert "oauthGatewayActivePosts" in task and "oauthGatewayQueued" in task
    assert "oauthGatewayTarget" in task and "oauthGatewaySafeRateTarget" in task
    assert "scheduleAcceleratorRender" in task

def test_gab_external_counter_does_not_count_heatmap_ledger():
    gab=read("server/team-points-green/src/GreenAnalyticsBootstrap.php")
    assert "FROM p2k_g_gab_external_work WHERE kind='gab_opponent_profile'" in gab
