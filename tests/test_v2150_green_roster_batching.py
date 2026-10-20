from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
REPO=(ROOT/"server/team-points-green/src/GreenRepository.php").read_text(encoding="utf-8")
WORKER=(ROOT/"server/team-points-green/src/GreenWorker.php").read_text(encoding="utf-8")

def test_roster_ingest_is_batched_not_per_member_round_trips():
    block=REPO[REPO.index("public function upsertRoster"):REPO.index("private function collapseMemberEvents")]
    assert "array_chunk($keys,500)" in block
    assert "array_chunk($keys,250)" in block
    assert "VALUES '.implode(',',$values)" in block
    assert "SELECT id,username,current_member FROM p2k_g_players WHERE username_key=? LIMIT 1" not in block
    assert "SELECT id FROM p2k_g_players WHERE username_key=?" not in block

def test_roster_runtime_is_split_in_worker_telemetry():
    block=WORKER[WORKER.index("private function fetchRoster"):WORKER.index("private function fetchMatch")]
    assert "timed('roster_http'" in block
    assert "timed('roster_ingest'" in block
    assert "timed('roster_compat_projection'" in block
