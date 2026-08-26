from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = (ROOT / 'server/team-points/src/FreshPointsReconstruction.php').read_text(encoding='utf-8')
TASK = (ROOT / 'assets/js/pages/task-control.js').read_text(encoding='utf-8')
FRESH = (ROOT / 'assets/js/pages/fresh-points-reconstruction.js').read_text(encoding='utf-8')


def test_reconstruction_snapshot_is_metadata_only():
    start = PHP.index('public function snapshot(')
    end = PHP.index('public function command(', start)
    body = PHP[start:end]
    assert '$this->review(' not in body
    assert "'review'=>null" in body
    assert 'snapshot/status must stay metadata-only' in body


def test_reconstruction_module_does_not_fetch_status_on_script_load():
    tail = FRESH[-500:]
    assert 'window.P2K_FRESH_POINTS_RECONSTRUCTION=api;' in tail
    assert 'window.P2K_FRESH_POINTS_RECONSTRUCTION=api;init();' not in tail
    assert 'async function init(){if(initPromise)return initPromise;' in FRESH


def test_task_control_bootstrap_is_status_only_and_logs_are_lazy():
    start = TASK.index('async function refresh({ logs = true, refreshSelectedDetail = true } = {})')
    end = TASK.index('async function command(', start)
    body = TASK[start:end]
    assert 'endpoint("status"' in body
    assert 'loadSelectedTaskDetail({ quiet: true })' in body
    assert 'state.selected' in body
    assert 'for (const task' not in body
    assert 'P2K_FRESH_POINTS_RECONSTRUCTION.sync' not in body
    assert 'loadLogs()' not in body
    init_start = TASK.index('async function initialize()')
    init_end = TASK.index('$("taskControlReconnect")', init_start)
    init_body = TASK[init_start:init_end]
    assert 'await refresh({ logs: false });' in init_body


def test_reconciliation_differences_are_manual_not_page_load_polling():
    assert 'automatic reconciliation polling removed' in TASK
    assert 'scheduleReconciliationRefresh(' not in TASK
    assert 'refreshDifferences?.("club"' not in TASK or 'setTimeout' not in TASK[TASK.find('refreshDifferences?.("club"')-250:TASK.find('refreshDifferences?.("club"')+250]
    assert 'reconstructionRefreshClubDifferences' in TASK
    assert 'reconstructionRefreshPlayerDifferences' in TASK


def test_reconstruction_state_loads_only_when_card_is_explicitly_opened():
    assert 'if (state.selected === RECONSTRUCTION_TASK)' in TASK
    assert 'await window.P2K_FRESH_POINTS_RECONSTRUCTION?.init?.();' in TASK
