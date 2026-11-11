"""Regression contract: Members Insights CSV must honor the selected activity period.

The interactive Members table and exporter both use the canonical Repository
period projection. The exporter deliberately retains the lightweight, paginated
path established by the v2.15.0 resource guard.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FRONTEND = (ROOT / "assets/js/pages/members-insights-enhancement.js").read_text(encoding="utf-8")
EXPORT = (ROOT / "server/team-points/public/members-insights-export.php").read_text(encoding="utf-8")
ENDPOINT = (ROOT / "server/team-points/public/members-insights.php").read_text(encoding="utf-8")
REPOSITORY = (ROOT / "server/team-points/src/Repository.php").read_text(encoding="utf-8")


def test_export_forwards_same_period_as_interactive_members_leaderboard():
    assert 'url.searchParams.set("start", state.membersStart)' in FRONTEND
    assert 'url.searchParams.set("end", state.membersEnd)' in FRONTEND
    assert "'start' => (string)($_GET['start'] ?? '')" in ENDPOINT
    assert "'end' => (string)($_GET['end'] ?? '')" in ENDPOINT
    assert "$startDate = trim((string)($_GET['start'] ?? ''));" in EXPORT
    assert "$endDate = trim((string)($_GET['end'] ?? ''));" in EXPORT
    assert "'start'=>$startDate,'end'=>$endDate" in EXPORT
    assert "'start'=>'','end'=>''" not in EXPORT
    assert "game_end_utc>=?" in REPOSITORY
    assert "game_end_utc<?" in REPOSITORY


def test_period_validation_precedes_csv_output():
    assert "DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'))" in EXPORT
    assert "INVALID_DATE_RANGE" in EXPORT
    assert EXPORT.index("$startDate = trim") < EXPORT.index("header('Content-Type: text/csv")
    assert "P2K_Members_' . $rangeName" in EXPORT


def test_export_resource_guard_remains_intact():
    assert "$pageSize = 500;" in EXPORT
    assert "'_export'=>true" in EXPORT
    assert "'_include_secondary'=>false" in EXPORT
    assert "publicMemberInsights($club" in EXPORT
    assert "MemberInsightsTableService" not in EXPORT
    assert "flush();" in EXPORT
