from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def read(rel): return (ROOT/rel).read_text(encoding="utf-8")

def test_quick_discovered_waits_only_for_actionable_match_work():
    worker=read("server/team-points-green/src/GreenWorker.php")
    block=worker[worker.index("if($stage==='quick_discovered')"):worker.index("if($stage==='quick_matches')")]
    assert "hasUnknownMatch()" in block
    assert "unknownMatchCount()>0" not in block

def test_actionable_unknown_predicate_matches_match_claiming_constraints():
    repo=read("server/team-points-green/src/GreenRepository.php")
    block=repo[repo.index("public function hasUnknownMatch"):repo.index("public function activeMatchIds")]
    assert "p2k_g_work_claims" in block
    assert "c.object_key IS NULL" in block
    assert "retry_after IS NULL OR m.retry_after<=UTC_TIMESTAMP()" in block
    assert "INTERVAL 10 MINUTE" in block
    assert "INTERVAL 2 MINUTE" in block
    assert "index_time_class" in block

def test_non_actionable_disagreement_remains_visible_but_cannot_livelock_cycle():
    repo=read("server/team-points-green/src/GreenRepository.php")
    assert "public function unknownMatchCount()" in repo
    assert "public function hasUnknownMatch()" in repo
