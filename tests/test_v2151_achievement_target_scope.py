from pathlib import Path

BUILDER = Path("server/team-points/src/AnalyticsBuilder.php")

def test_achievement_target_clauses_do_not_leak_into_general_projection_rebuilds():
    text = BUILDER.read_text(encoding="utf-8")
    start = text.index("private function rebuildAchievements")
    before = text[:start]
    assert "$coreTargetClause" not in before
    assert "$mcaTargetClause" not in before
    assert "$analyticsTargetClause" not in before
