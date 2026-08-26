from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(path):
    return (ROOT / path).read_text(encoding='utf-8', errors='ignore')


def test_mca_client_uses_explicit_long_rebuild_and_bounded_step_deadlines():
    js = text('assets/js/pages/team-points-features.js')
    for marker in (
        'MCA_REBUILD_TIMEOUT_MS = 110_000',
        'MCA_PROFILE_STEP_TIMEOUT_MS = 75_000',
        'MCA_PROFILE_LAUNCH_BUDGET_SECONDS = 12',
        'MCA_PROFILE_BATCH_CAP = 32',
        'function mcaProfileBatchLimit',
        "timeoutMs: MCA_REBUILD_TIMEOUT_MS",
        "timeoutMs: MCA_PROFILE_STEP_TIMEOUT_MS",
        'limit: batchLimit',
    ):
        assert marker in js


def test_mca_server_enforces_rate_derived_batch_budget_for_stale_clients():
    php = text('server/team-points/public/live-ranks-admin.php')
    for marker in (
        '$requestedLimit = max(1, min(256',
        '$pacedLimit = max(1, (int)floor($requestedRate * 12.0))',
        '$boundedLimit = min($requestedLimit, 32, $pacedLimit)',
        '$service->processProfileBatch($boundedLimit, $fetcher)',
        "$payload['mca_batch_limit'] = $boundedLimit",
    ):
        assert marker in php
