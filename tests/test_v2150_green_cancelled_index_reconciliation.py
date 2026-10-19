from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def read(rel):
    return (ROOT / rel).read_text(encoding="utf-8")

def test_authoritative_index_absence_queues_verification_instead_of_cancelling():
    repo = read("server/team-points-green/src/GreenRepository.php")
    block = repo[repo.index("public function upsertIndex"):repo.index("public function normalizeDailyDiscoveryWatermark")]
    assert "status IN ('registered','in_progress')" in block
    assert "isset($seenIds[$currentId])" in block
    assert "armGfflMatch($currentId,'club_index_absent',100,true)" in block
    assert "'missing_current_detected'=>$missingCurrent" in block
    assert "SET status='cancelled'" not in block

def test_missing_current_matches_flow_through_existing_terminal_verification():
    repo = read("server/team-points-green/src/GreenRepository.php")
    assert "if($status===404)" in repo
    assert "status='cancelled',is_void=1" in repo
    assert "exclusion_reason='http_404'" in repo
    assert "purgeMatchDerivedData($id)" in repo

def test_green_worker_exposes_missing_current_detection_metric():
    worker = read("server/team-points-green/src/GreenWorker.php")
    assert "index_missing_current_detected" in worker
    assert "missing_current_detected" in worker
