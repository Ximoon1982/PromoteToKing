from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def read(rel): return (ROOT/rel).read_text(encoding="utf-8")

def test_release_identity():
    assert (ROOT/"VERSION").read_text().strip()=="2.10.6.20"
    assert (ROOT/"MIGRATION_VERSION").read_text().strip()=="2.10.6.20"

def test_terminal_404_410_suppresses_gffl_rearming():
    repo=read("server/team-points-green/src/GreenRepository.php")
    assert "last_http_status FROM p2k_g_matches" in repo
    assert "in_array((int)($m['last_http_status']??0),[404,410],true)" in repo
    assert "COALESCE(m.last_http_status,0) NOT IN (404,410)" in repo

def test_registration_cancellation_can_be_terminal_without_corruption():
    repo=read("server/team-points-green/src/GreenRepository.php")
    assert "legitimately be cancelled while still in registration" in repo
    assert "status='cancelled',is_void=1" in repo
    assert "exclusion_reason='http_404'" in repo

def test_cron_terminal_response_closes_gffl_debt():
    worker=read("server/team-points-green/src/GreenWorker.php")
    assert "markMatchHttp($id,$status" in worker
    assert "in_array($status,[404,410],true)){$this->repo->completeGfflMatch($id);" in worker

def test_browser_success_and_terminal_both_close_gffl_debt():
    obs=read("server/team-points-green/public/observe.php")
    assert "declared==='gffl_match_detail'" in obs
    assert "$repo->markMatchHttp($matchId,$terminal" in obs
    assert "$repo->completeGfflMatch($matchId);" in obs
    assert "$r['gffl_completed']=true" in obs

def test_accelerator_allows_terminal_gffl_observation():
    acc=read("assets/js/shared/green-accelerator.js")
    assert '"gffl_match_detail"' in acc
    assert 'trafficClass:"background"' in acc
    assert 'jsonpFallback:false' in acc

def test_old_ineligible_debt_self_heals_and_metrics_expose_terminal_count():
    repo=read("server/team-points-green/src/GreenRepository.php")
    task=read("assets/js/pages/task-control.js")
    assert "retireIneligibleGfflDebt" in repo
    assert "m.last_http_status IN (404,410)" in repo
    assert "terminal_closed" in repo
    assert 'greenMetric("Terminal closed"' in task

def test_authoritative_index_reappearance_only_clears_terminal_suppression():
    repo=read("server/team-points-green/src/GreenRepository.php")
    assert "this authoritative club index explicitly shows the match again" in repo
    assert "last_http_status=CASE WHEN last_http_status IN (404,410) THEN NULL ELSE last_http_status END" in repo
    assert "trusted_legacy=0 AND last_http_status IN (404,410)" in repo
