from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = (ROOT / "server/tournaments/src/TournamentService.php").read_text(encoding="utf-8")
MANAGEMENT = (ROOT / "TournamentManagement.html").read_text(encoding="utf-8")
BUILDER = (ROOT / "tools/release/v2150/build-incremental.sh").read_text(encoding="utf-8")

def test_server_stops_top10_from_october_2026():
    candidate = SERVICE.split("private function candidateSlugs", 1)[1].split("private function settledScanState", 1)[0]
    assert "2026-10-01" in candidate
    assert "? [10, 32] : [32]" in candidate

def test_browser_historical_scan_preserves_top10_through_september_2026():
    assert "(year<2026||(year===2026&&monthIndex<9))?[10,32]:[32]" in MANAGEMENT

def test_cutoff_semantics():
    def sizes(year, month_index):
        return [10, 32] if (year < 2026 or (year == 2026 and month_index < 9)) else [32]
    assert sizes(2026, 8) == [10, 32]   # September
    assert sizes(2026, 9) == [32]       # October
    assert sizes(2027, 0) == [32]

def test_installer_documents_cutoff():
    assert "Monthly Top 10 discovery ends after September 2026; Top 32 continues." in BUILDER
