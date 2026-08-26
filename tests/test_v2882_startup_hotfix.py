from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding="utf-8",errors="ignore")
def block(src,start,end):
    a=src.index(start); b=src.index(end,a); return src[a:b]

def test_public_dashboard_is_exact_v286_style_after_regression_rollback():
    repo=text("server/team-points/src/Repository.php")
    dash=block(repo,"public function publicClubDashboard","public function publicHallOfFame")
    assert "p2k_tp_club_totals" in dash
    assert "p2k_tp_match_summaries" in dash
    assert "$this->state(" not in dash and "$this->readState(" not in dash
    assert "currentMemberCountProjected" in dash
    assert "analytics_projection_from_core" in dash

def test_public_generation_metadata_never_creates_state_rows():
    repo=text("server/team-points/src/Repository.php")
    meta=block(repo,"public function publicReadMeta","private function currentMemberCountCore")
    assert "$this->readState($clubSlug)" in meta
    assert "$this->state($clubSlug)" not in meta
    read_state=block(repo,"private function readState","public function storePlayerProfileSnapshot")
    upper=read_state.upper()
    assert "SELECT CORE_GENERATION" in upper
    assert "INSERT " not in upper and "UPDATE " not in upper and "DELETE " not in upper

def test_worker_state_api_still_creates_state_for_write_paths():
    repo=text("server/team-points/src/Repository.php")
    state=block(repo,"public function state","private function readState")
    assert "$this->ensureState($clubSlug)" in state
    ensure=block(repo,"public function ensureState","public function coreGeneration")
    assert "INSERT IGNORE INTO p2k_tp_state" in ensure

def test_dashboard_client_preserves_v286_startup_but_protects_canonical_finished_metrics():
    js=text("assets/js/pages/dashboard-v2.js")
    load=block(js,"async function loadTeamData","function renderGauge")
    assert "setMatchMetric(\"registered\"" in load
    assert "setMatchMetric(\"ongoing\"" in load
    assert "setMatchMetric(\"finished\"" not in load
    assert "clubMembersAPI" not in load and "loadJSON(clubProfileAPI)" not in load


def test_dashboard_database_read_uses_v286_await_before_second_wave():
    js=text("assets/js/pages/dashboard-v2.js")
    load=block(js,"async function loadTeamData","function renderGauge")
    assert 'await (window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("team")' in load
    assert "databaseRequest.then(databaseResult =>" not in load
    assert load.index('await (window.P2K_TEAM_POINTS_CLIENT') < load.index("const secondWave = () =>")
