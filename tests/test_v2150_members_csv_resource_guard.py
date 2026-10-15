from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EXPORT = (ROOT / "server/team-points/public/members-insights-export.php").read_text(encoding="utf-8")
SERVICE = (ROOT / "server/team-points/src/MemberInsightsTableService.php").read_text(encoding="utf-8")
BUILDER = (ROOT / "tools/release/v2150/build-incremental.sh").read_text(encoding="utf-8")

def test_csv_disables_historical_position_comparison():
    assert "'include_position_comparison'=>false" in EXPORT

def test_interactive_table_keeps_comparison_by_default():
    assert "array_key_exists('include_position_comparison', $options)" in SERVICE
    assert "$comparisonRequested" in SERVICE
    assert "$comparisonAvailable = $comparisonRequested" in SERVICE

def test_service_is_packaged_with_export_fix():
    assert "server/team-points/src/MemberInsightsTableService.php" in BUILDER

def test_release_notes_document_resource_guard():
    assert "historical position-comparison pass" in BUILDER
