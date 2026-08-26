from pathlib import Path
from bs4 import BeautifulSoup
import hashlib

ROOT=Path(__file__).resolve().parents[1]
EXPECTED_PUBLIC={
    'publicDashboardPanel':'a18bc0f9b470edcfd8572e2eb5498e30f37c53ac22dc89ac4c7a3dc94ae4ee6b',
    'hallOfFamePage':'8cba68b3b0aa8f697ffd838f6358d4b424b41700eda8c5609df17148ef881c36',
    'teamInsightsPage':'33f953f29211016d922718cb30e8c7b7991ec8943a7048ffd0bcbc9813b7750f',
}
def read(rel): return (ROOT/rel).read_text(encoding='utf-8')
def sha(s): return hashlib.sha256(s.encode()).hexdigest()

def test_identity_and_public_dom_lock():
    assert read('VERSION').strip()=='2.10.6.16'
    assert read('MIGRATION_VERSION').strip()=='2.10.6.16'
    soup=BeautifulSoup(read('ui-v2.html'),'html.parser')
    for node_id, expected in EXPECTED_PUBLIC.items():
        assert sha(str(soup.find(id=node_id)))==expected,node_id

def test_tail_pass_cannot_cross_completed_cycle_boundary():
    worker=read('server/team-points-green/src/GreenWorker.php')
    assert 'sameActiveCycle(array $state,int $cycleNo)' in worker
    assert "if($this->timeLeft(3.0)&&$this->sameActiveCycle($tailState,$this->cycle))" in worker
    assert "finite_cycle_tail_skipped_cycle_completed" in worker
    tail=worker.split('// Tail pass:',1)[1].split('// Finish the finite Green worker',1)[0]
    assert 'sameActiveCycle' in tail
    assert 'ensureCycle' not in tail

def test_missing_gqac_active_quick_cycle_self_heals_to_quick_boards():
    repo=read('server/team-points-green/src/GreenRepository.php')
    worker=read('server/team-points-green/src/GreenWorker.php')
    assert 'public function repairQuickBoardCycleInvariant()' in repo
    assert "in_array($stage,['quick_profiles','quick_stats'],true)" in repo
    assert "SET stage='quick_boards'" in repo
    assert "phase_key IN ('quick_profiles','quick_stats')" in repo
    assert 'self_healed_missing_gqac' in repo
    assert '$repair=$this->repo->repairQuickBoardCycleInvariant()' in worker
    assert 'quick_cycle_invariant_repairs' in worker

def test_terminal_player_http_refreshes_checkpoint_periodic_cycle():
    repo=read('server/team-points-green/src/GreenRepository.php')
    profile=repo.split('public function markProfileHttp',1)[1].split('public function markStatsHttp',1)[0]
    stats=repo.split('public function markStatsHttp',1)[1].split('public function storeProfile',1)[0]
    assert 'profile_checked_at=UTC_TIMESTAMP()' in profile
    assert 'profile_checked_at IS NULL' not in profile
    assert 'stats_checked_at=UTC_TIMESTAMP()' in stats
    assert 'stats_checked_at IS NULL' not in stats

def test_quick_profile_stats_progress_is_real_and_preserved():
    repo=read('server/team-points-green/src/GreenRepository.php')
    worker=read('server/team-points-green/src/GreenWorker.php')
    js=read('assets/js/pages/task-control.js')
    assert 'public function quickProfileProgress()' in repo
    assert 'public function quickStatsProgress(' in repo
    assert "recordPhaseProgress('quick_profiles','Quick Profiles','running'" in worker
    assert "recordPhaseProgress('quick_stats','Quick Stats','running'" in worker
    assert 'completePhaseProgressRow' in repo
    assert 'current phase</strong>' in js

def test_analytics_maintenance_no_longer_holds_global_green_worker_lock():
    worker=read('server/team-points-green/src/GreenWorker.php')
    repo=read('server/team-points-green/src/GreenRepository.php')
    assert "RELEASE_LOCK('p2k_green_worker')" in worker
    assert 'runPostLockAnalytics();' in worker
    release=worker.index('RELEASE_LOCK(\'p2k_green_worker\')')
    post=worker.index('$this->runPostLockAnalytics();')
    assert release < post
    assert "GET_LOCK('p2k_green_analytics_maintenance',0)" in worker
    assert "timed('green_analytics'" in worker
    assert "timed('compat_analytics'" in worker
    assert 'core_lock_runtime_ms' in worker
    assert 'post_lock_maintenance_ms' in worker
    complete=repo.split('public function completeCycle',1)[1].split('public function recoverQuickCompleteTransition',1)[0]
    assert '$this->rebuildAnalytics($no);' not in complete
    post=worker.split('private function runPostLockAnalytics',1)[1].split('public function run()',1)[0]
    assert 'maybeRebuildAnalytics($this->cycle,300)' in post
    assert 'maybeRebuildAnalytics(300)' in post
    assert 'green_analytics_errors' in post and 'compat_analytics_errors' in post

def test_task_control_reports_utc_and_timing_split():
    html=read('TaskControl.html')
    js=read('assets/js/pages/task-control.js')
    assert 'Timestamps are displayed in UTC.' in html
    assert 'core-lock runtime, total runtime' in html
    assert 'finite worker lock; analytics maintenance runs outside this lock' in js
    assert 'Green AN' in js and 'compat AN' in js
    assert 'pre-2.10.6.16 history may be distorted' in js
