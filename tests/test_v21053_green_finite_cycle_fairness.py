from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT/rel).read_text(encoding='utf-8')

def test_gqac_transient_debt_is_bounded_and_self_heals_existing_cycle():
    repo=text('server/team-points-green/src/GreenRepository.php')
    worker=text('server/team-points-green/src/GreenWorker.php')
    assert 'GQAC_TRANSIENT_ATTEMPT_LIMIT = 3' in repo
    assert 'retireExhaustedTransientQuickBoardItems' in repo
    assert repo.count('retireExhaustedTransientQuickBoardItems($cycleNo)') >= 2
    assert "requeued_for_next=1" in repo
    assert "b.retry_after>=COALESCE(i.first_claimed_at,'1970-01-01')" in repo
    assert 'deferQuickBoardTransientIfExhausted' in repo
    assert 'gqac_deferred_transient' in worker
    assert "!in_array($status,[404,410],true)" in worker

def test_server_reserves_majority_of_soft_budget_for_finite_cycle_before_sidecars():
    worker=text('server/team-points-green/src/GreenWorker.php')
    core=worker.index('$beforeCore=$this->requestCount')
    gab=worker.index('new GreenAnalyticsBootstrap($this->repo)', core)
    gffl=worker.index('$gfflCap=4', core)
    maintenance=worker.index('maintainCurrentMatches', gffl)
    assert core < gab < gffl < maintenance
    assert '$this->softTargetSeconds*0.70' in worker
    assert "finite_cycle_requests" in worker
    assert "finite_cycle_tail_requests" in worker
    assert "gffl_requests" in worker
    assert "current_maintenance_requests" in worker
    assert '$gfflCap=6' not in worker

def test_accelerator_keeps_gab_first_but_reserves_finite_cycle_slots():
    repo=text('server/team-points-green/src/GreenRepository.php')
    assert 'ACCELERATOR_FINITE_RESERVE_MAX = 16' in repo
    assert '$sidecarLimit=max(0,$limit-$finiteReserve)' in repo
    gab=repo.index("planExternal($sidecarLimit")
    gffl=repo.index('gfflPlan($sidecarLimit-count($urls))')
    ordinary=repo.index('$ordinaryLimit=max(0,$limit-count($urls))')
    assert gab < gffl < ordinary
    assert 'claimQuickBoardRows($ordinaryLimit' in repo
    assert 'claimMatchRows($ordinaryLimit' in repo
    assert 'claimQuickBoardRows($limit,$owner' not in repo[repo.index('public function feedPlan'):repo.index('public function ingestObservation')]

def test_cycle_observability_does_not_render_gab_gffl_as_zero_over_zero_phases():
    js=text('assets/js/pages/task-control.js')
    assert '.filter(r=>!["gab","gffl"].includes(String(r.phase_key||"")))' in js
    assert 'blocked_retry' in js and 'deferred_transient' in js
    assert 'finite_cycle_requests' in js and 'gffl_requests' in js and 'current_maintenance_requests' in js

def test_gab_mariadb_portability_fix_is_integrated():
    gab=text('server/team-points-green/src/GreenAnalyticsBootstrap.php')
    assert "SHOW KEYS FROM `{$table}` WHERE Key_name='PRIMARY'" in gab
    assert "ORDER BY Seq_in_index" not in gab
    assert 'usort($rows' in gab
