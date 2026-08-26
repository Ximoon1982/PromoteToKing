from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding="utf-8", errors="ignore")

def test_acsr_worker_pulse_budget_is_viable_for_outbound_guard():
    control=text("server/control/public/api.php"); worker=text("server/team-points/src/Worker.php"); client=text("assets/js/shared/client-continuous-refresh.js"); tp=text("assets/js/shared/team-points-client.js")
    assert "$minimumViableSeconds = 8" in control and "max_seconds'] ?? 34" in control
    assert "$absoluteDeadlineAt" in control and "canonicalQuota" in control
    assert "absoluteDeadlineAt" in worker and "hasOutboundRequestBudget" in worker
    assert "AUTHORITATIVE_PULSE_SECONDS = 34" in client
    assert "AUTHORITATIVE_PULSE_TIMEOUT_MS = 45_000" in client and "timeoutMs: Math.max(AUTHORITATIVE_PULSE_TIMEOUT_MS" in client
    assert "timeoutMs = REQUEST_TIMEOUT_MS" in tp

def test_archive_acquisition_is_fallback_and_strongly_bounded():
    control=text("server/control/public/api.php"); acamr=text("server/team-points/public/acamr-plan.php")
    assert "$archiveWindowSeconds = 600; $archiveWindowCap = 12;" in control
    assert "$claimStore->claimArchiveSlot($archiveWindowSeconds,$archiveWindowCap)" in control
    assert "$memberTasks === []" in control and "$claimArchiveSlot()" in control
    assert "first day of last month" not in control[control.index("if ($action === 'client-refresh-plan')"):control.index("if ($action === 'logs')")]
    assert "$claimStore->claimArchiveSlot(600,12)" in acamr and "$tasks===[]" in acamr
    store=text("server/team-points/src/AcamrClaimStore.php"); assert "claimArchiveSlot" in store and "archive-acquisition-budget.json" in store
    assert "first day of last month" not in acamr

def test_isre_batches_task_persistence_and_throttles_empty_plan_log():
    continuous=text("assets/js/shared/client-continuous-refresh.js"); task=text("assets/js/pages/task-control.js")
    run=continuous[continuous.index("async function runTask"):continuous.index("async function tick") ]
    assert 'finally { persist(); emit("task"); }' not in run
    assert 'emit("cycle-batch")' in continuous
    assert "EMPTY_PLAN_LOG_MIN_MS = 60_000" in continuous and "last_empty_plan_log_at" in continuous
    assert "const setHTML" in task and "if (!taskGrid) return" in task and "if (!detailHost) return" in task

def test_member_cron_keeps_margin_and_recomputes_optional_budget():
    loop=text("server/team-points/src/CronLoop.php"); cron=text("server/team-points/public/cron.php"); cfg=text("server/team-points/config/config.example.php"); coord=text("server/team-points/src/CronMaintenanceCoordinator.php")
    assert "player_cron_endpoint_max_seconds" in loop and "?38:42" in loop
    assert "'player_cron_endpoint_max_seconds' => 38" in cfg
    assert '$requestDeadlineAt' in cron and 'CronMaintenanceCoordinator' in cron
    assert 'hard_return_reserve_seconds' in coord and 'runClass(' in coord and 'max_statement_time' in coord
