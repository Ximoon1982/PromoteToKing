from pathlib import Path

SCRIPT = Path("server/team-points/bin/analytics-convergence.php")

def test_convergence_prioritizes_stale_achievements_when_older():
    text = SCRIPT.read_text(encoding="utf-8")
    assert "achievementSourceWatermark($club)" in text
    assert "$achievementStale" in text
    assert "$achievementRefreshed<=$allRefreshed" in text
    assert "['achievements','all']" in text
    assert "refreshIfDue($club,300,$deadline)" in text
    assert "refreshAchievementsIfDue($club,21600,$deadline)" in text
    assert "'order'=>$order" in text
