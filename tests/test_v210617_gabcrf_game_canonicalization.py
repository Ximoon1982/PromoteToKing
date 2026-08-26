from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def read(rel):
    return (ROOT/rel).read_text()

def test_release_identity():
    assert (ROOT/'VERSION').read_text().strip()=='2.10.6.17'
    assert (ROOT/'MIGRATION_VERSION').read_text().strip()=='2.10.6.17'
    assert 'version: "2.10.6.17"' in read('assets/js/site-config.js')
    assert 'dashboard-v2.js?v=2.10.6.17' in read('ui-v2.html')

def test_game_projection_is_canonical_per_sequence():
    c=read('server/team-points-green/src/GreenCompatibility.php')
    assert 'canonicalGameProjection' in c
    assert 'event_username_key' in c
    assert 'CASE WHEN e.username_key=? THEN 0 ELSE 1 END' in c
    assert 'event_identity_trusted' in c
    assert "if(isset($bySequence[$seq])){$duplicates++;continue;}" in c
    assert "'game_duplicates_resolved'=>$gameDuplicatesResolved" in c
    assert '$games=$this->green->core->prepare("SELECT g.*,e.result,e.points' not in c

def test_gabcrf_detects_game_projection_drift():
    c=read('server/team-points-green/src/GreenCompatibility.php')
    marker="COUNT(DISTINCT CONCAT(gx.board_no,':',GREATEST(1,gx.game_index)))"
    assert c.count(marker)>=2
    assert 'FROM p2k_tp_games cg JOIN p2k_tp_boards cbg' in c

def test_parity_counts_canonical_green_games():
    c=read('server/team-points-green/src/GreenCompatibility.php')
    assert "COUNT(DISTINCT CONCAT(g.match_id,':',g.board_no,':',GREATEST(1,g.game_index)))" in c

def test_prior_cycle_runtime_corrective_preserved():
    w=read('server/team-points-green/src/GreenWorker.php')
    r=read('server/team-points-green/src/GreenRepository.php')
    assert 'sameActiveCycle' in w
    assert "RELEASE_LOCK('p2k_green_worker')" in w
    assert 'runPostLockAnalytics' in w
    assert 'cycle completion is only the durable finite-state transition' in r
