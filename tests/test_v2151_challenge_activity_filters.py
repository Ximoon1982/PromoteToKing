from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HTML = (ROOT / "ChallengeListAssistant.html").read_text(encoding="utf-8")
JS = (ROOT / "assets/js/pages/challenge-list-assistant.js").read_text(encoding="utf-8")
CSS = (ROOT / "assets/css/challenge-list-assistant.css").read_text(encoding="utf-8")
BUILDER = (ROOT / "tools/release/v2151/build-incremental.sh").read_text(encoding="utf-8")

def test_activity_filter_ui_has_all_requested_criteria():
    for marker in [
        "p2kActivityRegistrationEnabled",
        "p2kActivityProgressEnabled",
        "p2kActivityStartedEnabled",
        "p2kActivityAverageEnabled",
        "p2kActivityBoardSizeEnabled",
        "p2kActivityCombine",
    ]:
        assert marker in HTML
    assert "All enabled criteria" in HTML
    assert "Any enabled criterion" in HTML

def test_defaults_preserve_old_inactivity_semantics():
    assert 'id="p2kActivityRegistrationValue"' in HTML and 'value="0"' in HTML
    assert 'id="p2kActivityProgressValue"' in HTML
    assert 'id="p2kActivityStartedValue"' in HTML
    assert 'id="p2kActivityStartedWindow"' in HTML
    assert '<option value="months" selected>months</option>' in HTML

def test_activity_engine_supports_and_or_and_all_comparators():
    assert 'settings.combine === "any"' in JS
    for op in ["lt","lte","eq","gte","gt"]:
        assert f'operator === "{op}"' in JS or f'{op}:' in JS

def test_started_matches_include_in_progress_and_finished():
    assert 'const startedRecords = [...inProgress, ...finished];' in JS
    assert 'matchesArray(data, "in_progress")' in JS
    assert 'matchesArray(data, "finished")' in JS

def test_board_filters_fetch_details_only_when_summary_lacks_boards():
    assert "let boards = matchBoards(summary);" in JS
    assert "if (boards === null)" in JS
    assert 'await fetchJSON(detailURL, signal)' in JS
    assert "settings.average.enabled || settings.boardSize.enabled" in JS

def test_club_endpoint_is_checked_and_http_errors_are_documented():
    assert 'fetchJSON(apiURL(slug), signal, { networkOnly: true })' in JS
    assert 'ENDPOINT ERROR:' in JS
    assert '[401,403,404,410]' in JS
    assert '"club_api_status"' in JS
    assert '"matches_api_status"' in JS

def test_activity_csv_contains_observed_metrics():
    for header in [
        "registered_matches","in_progress_matches","recent_started_matches",
        "average_window_matches","average_boards","board_size_qualifying_matches","matched_criteria"
    ]:
        assert f'"{header}"' in JS

def test_v2151_package_is_cumulative_and_contains_activity_runtime():
    assert 'ChallengeListAssistant.html' in BUILDER
    assert 'assets/css/challenge-list-assistant.css' in BUILDER
    assert 'assets/js/pages/challenge-list-assistant.js' in BUILDER
    assert 'TeamPointsAdmin.html' in BUILDER
    assert 'server/team-points-green/src/GreenCompatibility.php' in BUILDER
    assert '"version":"2.15.0","source_head":"3776ecdd6b91b67816f5fa3853e763ac5b5d7f98"' in BUILDER
    assert '"version":"2.15.0","source_head":"0bafb17000c118d0a40feaa5e9c5bba65bbf9b84"' in BUILDER


def test_green_membership_is_authoritative_for_member_table_and_csv_population():
    repo=(ROOT/"server/team-points/src/Repository.php").read_text(encoding="utf-8")
    export=(ROOT/"server/team-points/public/members-insights-export.php").read_text(encoding="utf-8")
    assert "publicGreenMemberInsightsMaterialized" in repo
    assert "FROM p2k_g_players" in repo
    assert "green_membership_authoritative" in repo
    assert "analytics_available" in repo
    assert "if(PublicReadDatabase::source()==='green')return $this->publicGreenMemberInsightsMaterialized" in repo
    assert "$repository->publicMemberInsights" in export
    assert "FROM p2k_g_players WHERE username_key IN" in export


def test_member_export_fix_accepts_promoted_v2151_predecessor():
    assert '"version":"2.15.1","source_head":"04b1f2cb2ef36dd50780e1efaa51ae69a19c407c"' in BUILDER
