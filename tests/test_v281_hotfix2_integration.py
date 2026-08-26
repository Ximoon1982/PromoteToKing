from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
def text(rel): return (ROOT / rel).read_text(encoding="utf-8")

def test_schema3_converges_both_schema2_branches():
    repo = text("server/team-points/src/Repository.php")
    core = text("server/team-points/sql/core-migration-v2.8.1-hotfix2.sql")
    analytics = text("server/team-points/sql/analytics-migration-v2.8.1-hotfix2.sql")
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [6,7,8,9,10,11,12,13,14,15]) and any(f'ANALYTICS_SCHEMA_VERSION = {v}' in repo for v in [5,6,7])
    for marker in ["daily_rating", "chess960_rating", "rating_updated_at", "p2k_avg_rating", "opponent_avg_rating"]:
        assert marker in core
    for marker in ["daily_rating", "chess960_rating", "last_standard_game_at", "last_chess960_game_at",
                   "p2k_avg_rating", "opponent_avg_rating", "first_place_count", "p2k_an_achievement_unlocks"]:
        assert marker in analytics
    assert "VALUES(3)" in core and "VALUES(3)" in analytics

def test_recruitment_rm2_and_opportunistic_ratings():
    recruit = text("assets/js/pages/recruit-match.js")
    finder = text("assets/js/pages/find-match.js")
    config = text("assets/js/site-config.js")
    repo = text("server/team-points/src/Repository.php")
    assert "RM-2" in config
    assert "recruitment-pool.php" in recruit and "lowestOpponentRating" in recruit and "significantTarget" in recruit
    assert "player-stats.php" in finder
    assert "storeMemberRatings" in repo and "recruitmentRatingPool" in repo
    assert (ROOT / "server/team-points/public/player-stats.php").is_file()
    assert (ROOT / "server/team-points/public/recruitment-pool.php").is_file()

def test_team_insights_recoveryfix7_on_v281_ui():
    html = text("TeamInsights.html")
    for marker in ["Low probability", "Medium probability", "High probability", "Current registration status",
                   "forecast-line", "future-zone", "function escapeHTML"]:
        assert marker in html
    assert "id=\"comparison\"" not in html
    assert "vs previous" in html
    assert "name:'Started',color:'#5fbf72'" in html
    assert "name:'Finished',color:'#e7685a'" in html
    assert "name:'In progress',color:'#f6b73c'" in html

def test_category_activity_is_materialized():
    schema = text("server/team-points/sql/analytics-schema.sql")
    builder = text("server/team-points/src/AnalyticsBuilder.php")
    repo = text("server/team-points/src/Repository.php")
    for marker in ["last_standard_game_at", "last_chess960_game_at"]:
        assert marker in schema and marker in builder and marker in repo

def test_recovered_achievement_images_stay_wired():
    catalog = text("server/team-points/src/AchievementCatalog.php")
    for marker in ["tmcl-legend.png", "kotml-competitor.png", "arena-warlord.png",
                   "first-tournament-medal.png", "ten-tournament-medals.png"]:
        assert marker in catalog


def test_mobile_tab_widths_are_unified():
    css = text("assets/css/responsive-unification.css")
    ui = text("ui-v2.html")
    for marker in [
        "#teamInsightsPage", "#hallOfFamePage", "#administrationPage",
        ".dashboard-integrated-subtabs", "html.p2k-embedded",
        "overflow-x: auto", "max-width: 100%"
    ]:
        assert marker in css
    assert "responsive-unification.css" in ui
    for page in [
        "TeamInsights.html", "Tournaments.html", "TournamentAchievementBadgesDemo.html",
        "AnalyzeMatches.htm", "MatchCreationAnalyzer.htm", "ChallengeListAssistant.html",
        "AnalyzeMatch.html", "TeamPointsAdmin.html", "TaskControl.html", "TaskLogs.html",
        "InsightsHealth.html", "TournamentManagement.html"
    ]:
        assert "responsive-unification.css" in text(page)
    for page in ["TeamInsights.html", "Tournaments.html", "TournamentAchievementBadgesDemo.html",
                 "InsightsHealth.html", "TournamentManagement.html"]:
        assert "embedded-page.js" in text(page)
