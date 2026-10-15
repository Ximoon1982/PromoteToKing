from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EXPORT = (ROOT / "server/team-points/public/members-insights-export.php").read_text(encoding="utf-8")
SERVICE = (ROOT / "server/team-points/src/MemberInsightsTableService.php").read_text(encoding="utf-8")
BUILDER = (ROOT / "tools/release/v2150/build-incremental.sh").read_text(encoding="utf-8")

def test_csv_uses_lightweight_materialized_repository_pages():
    assert "publicMemberInsights($club" in EXPORT
    assert "publicMemberInsightsSection($club" not in EXPORT
    assert "$pageSize = 500;" in EXPORT
    assert "'_export'=>true" in EXPORT
    assert "'_include_secondary'=>false" in EXPORT
    assert "flush();" in EXPORT
    assert "MemberInsightsTableService" not in EXPORT
    assert "include_position_comparison" not in EXPORT

def test_csv_preserves_result_coverage_semantics():
    assert "$covered = $wins + $draws + $losses;" in EXPORT
    assert "$missing = max(0, $games - $covered);" in EXPORT
    assert "$coveragePercent = $games > 0 ? round(100 * $covered / $games, 1) : 0.0;" in EXPORT
    assert "$winRate = $covered > 0 ? round(100 * $wins / $covered, 1) : '';" in EXPORT

def test_interactive_table_keeps_comparison_by_default():
    assert "array_key_exists('include_position_comparison', $options)" in SERVICE
    assert "$comparisonRequested" in SERVICE
    assert "$comparisonAvailable = $comparisonRequested" in SERVICE

def test_release_notes_document_streaming_resource_guard():
    assert "streams materialized member pages" in BUILDER


def test_repository_allows_larger_export_pages_without_changing_interactive_limit():
    repository = (ROOT / "server/team-points/src/Repository.php").read_text(encoding="utf-8")
    assert "$pageLimit=!empty($options['_export'])?500:100;" in repository
    assert "array_key_exists('_include_secondary',$options)" in repository
