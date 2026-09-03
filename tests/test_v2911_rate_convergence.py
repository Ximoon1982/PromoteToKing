from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding="utf-8")


def test_oauth_transport_controls_rate_not_only_concurrency():
    api = text("assets/js/shared/api-client.js") + text("assets/js/shared/api-oauth-context.js")
    oauth = text("server/team-points/src/OAuthSession.php")
    endpoint = text("server/team-points/public/oauth.php")
    assert ('const OAUTH_INITIAL_RATE_CPS = 8;' in api) or ('const OAUTH_INITIAL_RATE_CPS = 30;' in api)
    assert ('const OAUTH_MIN_CONNECTION_CAP = 32;' in api) or ('const OAUTH_MIN_CONNECTION_CAP = 3;' in api)
    assert ('rate_cps: oauthGatewayRateTarget' in api) or ('rate_cps: OAUTH_MAX_RATE_CPS' in api)
    assert 'requested_rate_cps' in oauth and 'rate_cps' in oauth
    if (ROOT / 'server/team-points/src/OAuthRateCoordinator.php').exists():
        coordinator = text('server/team-points/src/OAuthRateCoordinator.php')
        assert 'reserveLaunch' in oauth
        assert 'target_rate_cps' in coordinator
        assert 'MAX_RESERVATION_AHEAD_SECONDS' in coordinator
    else:
        assert '$launchInterval=$effectiveRate>0?1.0/$effectiveRate:0.0;' in oauth
        assert '$nextLaunchAt' in oauth
        assert '$effectiveRate=max(0.5,$effectiveRate*0.65)' in oauth
    assert "$body['rate_cps']" in endpoint


def test_rate_controller_remembers_safe_and_unsafe_bounds_and_converges():
    api = text("assets/js/shared/api-client.js") + text("assets/js/shared/api-oauth-context.js")
    assert 'oauthGatewaySafeRateTarget' in api
    assert 'oauthGatewayUnsafeRateTarget' in api
    assert 'oauthGatewayBestTargetRate' in api
    if (ROOT / 'server/team-points/src/OAuthRateCoordinator.php').exists():
        coordinator = text('server/team-points/src/OAuthRateCoordinator.php')
        assert 'unsafe_rate_cps' in coordinator and 'safe_rate_cps' in coordinator
        assert 'rate-limit-boundary' in coordinator
        assert 'integral_error' in coordinator and 'last_pressure' in coordinator
        assert 'unsafe * 0.92' in coordinator
        assert 'server-side OAuthRateCoordinator is authoritative' in api
    else:
        assert 'oauthGatewayStableSamples < 2' in api
        assert 'gap * 0.25' in api
        assert 'oauthGatewayUnsafeRateTarget * 0.94' in api
        assert 'oauth-429-converge' in api
        assert 'oauth-rate-plateau' in api
        assert 'p2k-oauth-gateway-tuning-v2' in api


def test_latency_baselines_are_endpoint_class_specific():
    api = text("assets/js/shared/api-client.js") + text("assets/js/shared/api-oauth-context.js")
    for name in ['match-detail','club-index','roster','club-profile','player-stats','player-matches','archive','player-profile']:
        assert name in api
    assert 'oauthGatewayLatencyByClass' in api
    assert 'endpointClass !== "mixed"' in api
    if (ROOT / 'server/team-points/src/OAuthRateCoordinator.php').exists():
        coordinator = text('server/team-points/src/OAuthRateCoordinator.php')
        assert 'latency_baseline_ms' in coordinator
        assert 'endpointClass' in coordinator
        assert 'oauthConcurrencyForRate' in api
    else:
        assert 'concurrencySaturated' in api
        assert 'oauth-latency-cap-grow' in api


def test_match_creation_has_no_feature_local_transport_cap():
    creation = text("assets/js/pages/match-creation-analyzer.js")
    assert creation.count('P2K_API_CLIENT.processPriority') >= 2
    assert 'await window.P2K_API_CLIENT.processPriority' in creation
    assert not re.search(r'processPriority\([\s\S]{0,1400}?concurrency\s*:\s*\d+', creation)
    assert 'P2K_API_CLIENT.json(url' in creation


def test_speed_fetch_reports_rate_convergence_not_logical_256_as_speed():
    refresh = text("assets/js/shared/client-continuous-refresh.js")
    task = text("assets/js/pages/task-control.js")
    assert 'oauthGatewayRateTarget' in refresh
    assert 'oauthGatewaySafeRateTarget' in refresh
    assert 'oauthGatewayUnsafeRateTarget' in refresh
    assert 'OAuth paced · ${rateTarget.toFixed(1)}/s' in refresh
    assert 'Learned safe rate' in task
    assert 'Backlash boundary' in task
    assert 'converges below detected pressure' in task


def test_server_side_admin_bearer_bridges_receive_same_rate_budget():
    js = text("assets/js/pages/team-points-features.js")
    live = text("server/team-points/public/live-ranks-admin.php")
    opp = text("server/team-points/public/opponents-admin.php")
    oauth = text("server/team-points/src/OAuthSession.php")
    assert 'oauth_rate_cps: oauth.rate' in js
    assert "oauth_rate_cps" in live and '$requestedRate' in live
    assert "oauth_rate_cps" in opp and '$oauthRate' in opp
    assert 'batchForAuthorizedRequest(array $requests,int $requestedConcurrency,float $requestedRateCps=0.0' in oauth
