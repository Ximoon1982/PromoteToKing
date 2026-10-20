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


def test_integrity_repair_includes_finished_boards_from_in_progress_matches():
    assert "m.status IN ('in_progress','finished') AND b.state='finished'" in GREEN
    assert "ORDER BY COALESCE(b.last_verified_at,'1970-01-01 00:00:00') ASC" in GREEN

def test_daily_hall_switches_to_green_native_totals_after_cutover():
    repo=(ROOT/"server/team-points/src/Repository.php").read_text(encoding="utf-8")
    hall=repo[repo.index("public function publicHallOfFame"):repo.index("private function publicCurrentMemberRows")]
    assert "PublicReadDatabase::source()==='green'" in hall
    assert "greenNativeHallOfFame" in repo
    assert "p2k_g_player_totals" in repo
    assert "greenHallMemberStats" in repo
