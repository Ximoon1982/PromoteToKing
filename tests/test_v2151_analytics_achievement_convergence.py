from pathlib import Path

SCRIPT = Path("server/team-points/bin/analytics-convergence.php")

def test_convergence_refreshes_achievements_when_budget_remains():
    text = SCRIPT.read_text(encoding="utf-8")
    assert "refreshIfDue($club,300,$deadline)" in text
    assert "refreshAchievementsIfDue($club,21600,$deadline)" in text
    assert "$deadline-microtime(true)>=10.0" in text
    assert "'achievements'=>$achievements" in text
