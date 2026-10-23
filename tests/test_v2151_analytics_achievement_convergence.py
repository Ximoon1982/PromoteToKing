from pathlib import Path

SCRIPT = Path("server/team-points/bin/analytics-convergence.php")
BUILDER = Path("server/team-points/src/AnalyticsBuilder.php")

def test_every_minute_convergence_owns_general_analytics_only():
    text = SCRIPT.read_text(encoding="utf-8")
    assert "refreshIfDue($club,300,$deadline)" in text
    assert "refreshAchievementsIfDue" not in text
    assert "'achievement_owner'=>'team-points-maintenance-player-lane'" in text

def test_achievement_watermark_is_not_tied_to_core_generation():
    text = BUILDER.read_text(encoding="utf-8")
    start = text.index("public function achievementSourceWatermark")
    end = text.index("private function runtimeRefreshPaths", start)
    block = text[start:end]
    assert "core_generation" not in block
    assert "total_points" in block
    assert "mca-dates:" in block
    assert "tournaments:" in block
