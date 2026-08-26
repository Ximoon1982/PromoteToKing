from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGE = (ROOT / "TaskControl.html").read_text(encoding="utf-8")
JS = (ROOT / "assets/js/pages/task-control.js").read_text(encoding="utf-8")
WORKER = (ROOT / "assets/js/shared/client-continuous-refresh.js").read_text(encoding="utf-8")
CONTROL = (ROOT / "server/control/public/api.php").read_text(encoding="utf-8")


def test_task_control_loads_continuous_refresh_worker_and_detail_panel():
    assert ('assets/js/shared/client-continuous-refresh.js?v=post-2.9.7' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.8' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.9' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.10' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.11' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.12' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.13' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.14' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.15' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.16' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.17' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.18' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.19' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.20' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.21' in PAGE or 'assets/js/shared/client-continuous-refresh.js?v=2.9.22' in PAGE)
    assert 'id="clientRefreshDetail"' in PAGE
    assert 'id="clientRefreshClassRows"' in PAGE
    assert 'id="clientRefreshLogRows"' in PAGE


def test_card_uses_normal_task_card_layout_and_expected_controls():
    assert 'label: "Client continuous refresh"' in JS
    assert 'class="task-card ${task.task_key === state.selected ? "is-selected" : ""}"' in JS
    assert 'data-client-refresh-command="start"' in JS
    assert 'data-client-refresh-command="pause"' in JS
    assert 'data-client-refresh-command="stop"' in JS


def test_continuous_refresh_is_disabled_by_default_and_persistent_only_after_explicit_start():
    assert 'mode: "disabled"' in WORKER
    assert 'Disabled by default.' in WORKER
    assert 'function start()' in WORKER
    assert 'function pause()' in WORKER
    assert 'function stop()' in WORKER


def test_worker_never_controls_api_parallelism():
    assert 'P2K_API_CLIENT.jsonDetailed' in WORKER
    assert 'cacheMode: "network-only"' in WORKER
    assert 'setConcurrentMode' not in WORKER
    assert 'setConcurrency(' not in WORKER
    assert 'api_concurrency_controlled_elsewhere' in CONTROL


def test_worker_uses_claim_backed_observation_path():
    assert 'observationSource: "client_refresh"' in WORKER
    assert 'observationClaimToken' in WORKER
    assert 'observationClaimKind' in WORKER
    assert 'new AcamrClaimStore($storage)' in CONTROL
    assert '$claimStore->issue($username,$tasks,$claimTtl' in CONTROL
    assert "'task-control','client_refresh'" in CONTROL


def test_planner_is_due_aware_and_covers_requested_data_classes():
    assert "if ($action === 'client-refresh-plan')" in CONTROL
    assert 'acamrCandidateMembers' in CONTROL
    for kind in ['club_index', 'roster', 'matches', 'stats', 'archive']:
        assert f"'kind'=>'{kind}'" in CONTROL
    assert 'synchronizationCoverage' in CONTROL


def test_selected_task_shows_progress_metrics_classes_and_local_logs():
    for marker in ['Player matches fresh', 'Player stats fresh', 'Observation accepted', 'Server work queued', 'clientRefreshClassRows', 'clientRefreshLogRows']:
        assert marker in JS
    assert 'Throughput is inherited from the shared Chess.com API client.' in PAGE


def test_staging_does_not_renumber_release():
    version = (ROOT / 'VERSION').read_text(encoding='utf-8').strip()
    assert version in {'2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
