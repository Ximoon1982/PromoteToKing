from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_oauth_v2914_starts_30_cps_cap_3_but_keeps_adaptive_learning():
    api=text('assets/js/shared/api-client.js')+text('assets/js/shared/api-oauth-context.js'); coord=text('server/team-points/src/OAuthRateCoordinator.php')
    assert 'const OAUTH_INITIAL_RATE_CPS = 30;' in api
    assert 'const OAUTH_INITIAL_TARGET = 3;' in api or 'const OAUTH_INITIAL_TARGET = 8;' in api
    assert 'const OAUTH_MIN_CONNECTION_CAP = 3;' in api
    assert 'p2k-oauth-gateway-tuning-v3' in api or 'p2k-oauth-gateway-tuning-v4' in api
    assert 'private const STATE_VERSION = 2;' in coord and 'private const INITIAL_RATE = 30.0;' in coord
    assert 'clean-probe' in coord and 'boundary-converge' in coord and 'unsafe * 0.92' in coord
    assert 'oauthConcurrencyForRate(serverRate, median, p95)' in api

def test_p0_interactive_survival_reserves_gateway_and_suppresses_background():
    api=text('assets/js/shared/api-client.js')+text('assets/js/shared/api-oauth-context.js')
    for token in ('OAUTH_FOREGROUND_RESERVED_POSTS = 1','OAUTH_BACKGROUND_MAX_POSTS','OAUTH_INTERACTIVE_WAIT_TARGET_MS = 250','oauthInteractiveProtection','oauthForegroundWaitMaxMs','oauthBackgroundAdmissionSuppressions'):
        assert token in api
    assert 'counts.foreground > 0 || oauthGatewayActiveForegroundPosts > 0' in api
    assert 'trafficClass === "background"' in api

def test_automatic_acquisition_is_background_and_acsr_yields_to_interactive():
    continuous=text('assets/js/shared/client-continuous-refresh.js')
    acamr=text('assets/js/shared/authenticated-member-refresh.js')
    acsr=text('assets/js/shared/active-convergence-refresh.js')
    assert 'trafficClass: "background"' in continuous
    assert 'trafficClass: "background"' in acamr
    assert 'interactiveProtectionActive()' in continuous and 'emit("interactive-protection")' in continuous
    assert "skipped: 'interactive-protection'" in acsr
    assert 'p2k-api-interactive-protection' in acsr

def test_task_control_surfaces_survival_metrics():
    task=text('assets/js/pages/task-control.js')
    for label in ('Interactive protection','Foreground gateway queue','Background gateway queue','Reserved foreground lane','Foreground queue wait','Max foreground wait','Background suppressions'):
        assert label in task
