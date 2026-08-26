from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TASK = (ROOT / 'assets/js/pages/task-control.js').read_text(encoding='utf-8')
CONTROL = (ROOT / 'server/control/public/api.php').read_text(encoding='utf-8')
WORKER = (ROOT / 'server/team-points/src/Worker.php').read_text(encoding='utf-8')
CRON = (ROOT / 'server/team-points/public/cron.php').read_text(encoding='utf-8')


def test_selected_task_detail_survives_periodic_status_refresh():
    assert 'state.taskDetails.clear()' not in TASK
    assert 'work: cached.work || task.work' in TASK
    assert 'detail_loaded_at: cached.detail_loaded_at' in TASK
    assert 'taskDetailLoads: new Map()' in TASK
    assert 'loadSelectedTaskDetail({ quiet: true })' in TASK
    assert 'last detailed snapshot is being retained because refresh failed' in TASK


def test_all_server_panels_share_the_telemetry_persistence_path():
    assert 'SERVER_TASK_FALLBACKS' in TASK
    for key in ('team-points-club', 'team-points-player', 'match-tracking', 'tournaments'):
        assert key in TASK
    assert 'slice(0, 32)' in TASK
    assert 'Task last success' in TASK
    assert 'Detail snapshot' in TASK
    assert 'task.details || {}' in TASK


def test_club_detail_reports_freshness_and_durable_debt_without_full_core_summary():
    branch = CONTROL[CONTROL.index("if (in_array($key, ['team-points-club'"):CONTROL.index("if ($key === 'match-tracking')")]
    assert 'Repository::summary()/synchronizationCoverage()' not in branch
    assert 'club_index_verified_age_seconds' in branch
    assert 'active_detail_checks_due' in branch
    assert 'durable_remaining' in branch
    assert 'queue_pending' in branch
    assert 'queue_retry' in branch
    assert 'active_canonical' in branch
    assert 'No Club Points durable job exists.' in branch


def test_member_detail_distinguishes_operational_and_server_verified_freshness():
    assert 'player_matches_operational_fresh_percent' in CONTROL
    assert 'player_matches_server_verified_percent' in CONTROL
    assert 'player_stats_operational_fresh_percent' in CONTROL
    assert 'player_stats_server_verified_percent' in CONTROL
    assert "COALESCE(player_matches_observed_at,'1970-01-01')" in CONTROL
    assert "COALESCE(stats_observed_at,'1970-01-01')" in CONTROL


def test_idle_worker_and_controller_keep_reason_telemetry():
    assert "No runnable job — " in WORKER
    assert "'idle_reason' => $idleReason" in WORKER
    assert "'job_status' => $job['status'] ?? null" in WORKER
    assert "'worker_idle_reason'" in CRON
    assert "'worker_idle_reason'=>$result['idle_reason']??null" in CONTROL
