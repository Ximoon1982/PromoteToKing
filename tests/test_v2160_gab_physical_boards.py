"""GAB v2.16.0: physical boards and unresolved identities (regressions).

These source guards complement production read-only GAB/MIAC verification.
Do not assume that two usernames on a board are aliases.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COMPAT = (ROOT / "server/team-points-green/src/GreenCompatibility.php").read_text()
GAB = (ROOT / "server/team-points-green/src/GreenAnalyticsBootstrap.php").read_text()


def test_reconciliation_counts_physical_boards_not_alias_strings():
    start = COMPAT.index("public function reconcileCompatibilityBatch(")
    end = COMPAT.index("public function rebuildAnalytics()", start)
    reconciliation = COMPAT[start:end]
    assert reconciliation.count("COUNT(DISTINCT mp2.board_no)") == 2
    assert "COUNT(DISTINCT COALESCE(mi2.canonical_username_key" not in reconciliation
    assert reconciliation.count("p2k_g_gab_unresolved_boards ui") >= 4
    assert "'unresolved_identity_boards'=>$blocked" in reconciliation


def test_unresolved_player_is_reported_not_fabricated():
    projector = COMPAT.split("public function projectMatch(", 1)[1].split("public function projectMatchBatch(", 1)[0]
    assert "p2k_g_gab_unresolved_boards" in projector
    assert "if($mid<=0){$identityIssue->execute" in projector
    assert "if($mid<=0)continue" not in projector
    assert "mi.conflict=0" in projector
    assert "DELETE FROM p2k_g_gab_unresolved_boards" in projector


def test_read_parity_uses_real_board_slots():
    smoke = COMPAT.split("public function smokeTest(): array", 1)[1]
    assert "COUNT(DISTINCT CONCAT(mp.match_id,':',mp.board_no))" in smoke
    assert "COUNT(DISTINCT CONCAT(mp.match_id,':',COALESCE(" not in smoke


def test_monitor_exposes_persistent_identity_issues():
    assert "unresolved_identity_boards" in GAB
    assert "'identity_issues'=>['count'=>$unresolvedIdentity,'sample'=>$identitySamples]" in GAB
    assert "p2k_g_gab_unresolved_boards" in GAB


def test_regression_fixture_two_names_one_board():
    # Matches 1779702/11, 1783550/1, 1784614/5, 1785044/3, 1807846/11.
    players = [(11, "theartof_loving"), (11, "ziggyjunior")]
    assert len(set(username for _, username in players)) == 2
    assert len(set(board for board, _ in players)) == 1
    # They are NOT a confirmed alias pair; actual games prefer ziggyjunior.
    assert set(["theartof_loving", "ziggyjunior"]) == {x[1] for x in players}


def test_regression_fixture_unresolved_physical_board():
    # Match 1656281, physical board 86, noble_sse has point events/games
    # but no Green player or compact compatibility member. Do not assign
    # those games to patrix726 (historical match lineup only).
    actual_boards, projected_boards = 121, 120
    assert actual_boards - projected_boards == 1
    assert "noble_sse" != "patrix726"
