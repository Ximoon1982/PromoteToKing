"""v2.16.0: protect the 30-minute Quick cycle and keep roster once per cycle.

Only source-level regression guards; live timing still requires production verification.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKER = (ROOT / "server/team-points-green/src/GreenWorker.php").read_text()
GAB = (ROOT / "server/team-points-green/src/GreenAnalyticsBootstrap.php").read_text()


def test_roster_stays_at_quick_cycle_boundary():
    quick = WORKER.split("private function runQuick(", 1)[1].split(
        "private function runDeep(", 1
    )[0]
    assert "if($stage==='quick_index_roster')" in quick
    assert "$this->fetchRoster()" in quick
    assert "refreshRosterIfStale" not in WORKER
    assert "roster_freshness_due" not in WORKER


def test_finite_cycle_precedes_bounded_gab():
    run = WORKER.split("public function run(): array", 1)[1]
    assert run.index("$this->timed('finite_cycle'") < run.index(
        "$this->runGabWithStatementBudget"
    )
    assert "SET SESSION max_statement_time=5" in WORKER
    assert "SELECT @@SESSION.max_statement_time" in WORKER
    assert "max_statement_time='.sprintf('%.6F',$previous)" in WORKER
    assert "gab_deferred_statement_cap_unavailable" in WORKER
    assert "runSlice(4.0)" in WORKER
    assert "runSlice(min(5.0" not in WORKER


def test_gab_query_timeout_does_not_reset_cursor_or_mark_error():
    assert "private function isStatementBudgetExceeded(" in GAB
    assert "===1969" in GAB
    lane = GAB.split("if($key==='compat_reconciliation'){", 1)[1].split(
        "if($key==='analytics_build')", 1
    )[0]
    assert "$this->isStatementBudgetExceeded($e)" in lane
    assert "$budgetExceeded=true;break;" in lane
    assert "'gabcrf_deferred'=>true" in lane
    assert "'last_full_pass_remaining'" in lane
    assert "cursor_json=NULL" not in lane
    assert "if(!is_array($r))" in lane


def test_original_hard_budget_and_cycle_transition_preserved():
    assert "max(15,min(55,$hardBudgetSeconds" in WORKER
    assert "max(12.0,min((float)$this->softTargetSeconds-10.0" in WORKER
    assert "$this->repo->completeCycle($this->achieved,'quick_index_roster')" in WORKER
