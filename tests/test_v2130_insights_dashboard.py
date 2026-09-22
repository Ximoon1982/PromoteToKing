from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def read(path):
    return (ROOT/path).read_text(encoding="utf-8")

def test_v2130_public_insights_and_dashboard_cleanup():
    repo=read("server/team-points/src/Repository.php")
    js=read("assets/js/pages/dashboard-insights.js")
    charts=read("assets/js/pages/dashboard-insights-charts.js")
    team=read("TeamInsights.html")
    ui=read("ui-v2.html")
    assert "average_boards_started" in repo
    assert "Average boards / started match" in js
    assert "FROM p2k_g_matches m WHERE m.verified_club_slug=?" in repo
    assert "m.is_league=1" not in repo[repo.index("if($section==='results')"):repo.index("if($section==='duration')")]
    assert "usable.length === 1" in charts and 'nativeSVG("circle"' in charts
    assert "Low probability" not in team and "Medium probability" not in team and "High probability" not in team
    assert "Low points projection" in team and "High points projection" in team
    assert "+/-10% probability bands" not in repo
    assert "personalStatusBadge" not in ui
    assert "teamStatusBadge" not in ui
