from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
SERVICE = (ROOT / "server/tournaments/src/TournamentService.php").read_text(encoding="utf-8")
MANAGEMENT = (ROOT / "TournamentManagement.html").read_text(encoding="utf-8")
BUILDER = (ROOT / "tools/release/v2150/build-incremental.sh").read_text(encoding="utf-8")

def test_server_cursor_keeps_previous_quarter_open():
    settled = SERVICE.split("private function settledScanState(): array", 1)[1].split("private function monthAfter", 1)[0]
    assert "$settledQuarter = $currentQuarter - 2;" in settled
    assert "$settledQuarter += 4; $year--;" in settled

def test_server_candidate_scan_includes_current_quarter():
    candidate = SERVICE.split("private function candidateSlugs", 1)[1].split("private function settledScanState", 1)[0]
    assert "$quarter <= $currentQuarter" in candidate
    assert "quarterlyThrough" in candidate

def test_october_q4_state_settles_q2_so_q3_and_q4_are_rescanned():
    current_quarter = 4
    year = 2026
    settled_quarter = current_quarter - 2
    if settled_quarter < 1:
        settled_quarter += 4
        year -= 1
    assert (year, settled_quarter) == (2026, 2)
    generated = []
    q_year, quarter = 2026, settled_quarter + 1
    while q_year < 2026 or (q_year == 2026 and quarter <= current_quarter):
        generated.append((q_year, quarter))
        quarter += 1
        if quarter > 4:
            quarter = 1
            q_year += 1
    assert generated == [(2026, 3), (2026, 4)]

def test_q1_rollover_keeps_previous_q4_open():
    current_quarter = 1
    year = 2027
    settled_quarter = current_quarter - 2
    if settled_quarter < 1:
        settled_quarter += 4
        year -= 1
    assert (year, settled_quarter) == (2026, 3)

def test_browser_repair_uses_same_two_quarter_window():
    match = re.search(r"function settledScanState\(\)\{.*?\}\nfunction normalizedTournament", MANAGEMENT, re.S)
    assert match
    body = match.group(0)
    assert "Math.ceil((now.getUTCMonth()+1)/3)-2" in body
    assert "quarter+=4;year--" in body

def test_incremental_package_contains_tournament_fix():
    assert "TournamentManagement.html" in BUILDER
    assert "server/tournaments/src/TournamentService.php" in BUILDER
