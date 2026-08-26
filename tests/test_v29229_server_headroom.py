from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
API_CLIENT = (ROOT / 'assets/js/shared/api-client.js').read_text(encoding='utf-8')
TP_CLIENT = (ROOT / 'assets/js/shared/team-points-client.js').read_text(encoding='utf-8')
FRESH = (ROOT / 'assets/js/pages/fresh-points-reconstruction.js').read_text(encoding='utf-8')
ACSR = (ROOT / 'assets/js/shared/client-continuous-refresh.js').read_text(encoding='utf-8')


def test_background_oauth_gateway_leaves_fastcgi_headroom():
    assert 'const OAUTH_BACKGROUND_MAX_POSTS = 2;' in API_CLIENT
    assert 'serverForegroundPressure()' in API_CLIENT
    assert 'if (serverForegroundPressure()) return false;' in API_CLIENT
    assert 'oauthGatewayActiveBackgroundPosts < OAUTH_BACKGROUND_MAX_POSTS' in API_CLIENT


def test_team_points_admin_requests_raise_server_foreground_pressure():
    assert 'P2K_SERVER_FOREGROUND_REQUESTS' in TP_CLIENT
    assert 'withServerTraffic("foreground", "team-points-session"' in TP_CLIENT
    assert 'serverTrafficClass = "foreground"' in TP_CLIENT
    assert 'p2k-server-foreground-pressure' in TP_CLIENT


def test_background_pipeline_calls_do_not_masquerade_as_interactive():
    assert 'reconstruction-ingest' in FRESH
    assert 'serverTrafficClass:"background"' in FRESH
    assert 'requestKind: "acsr-worker-pulse", serverTrafficClass: "background"' in ACSR


def test_api_client_resumes_background_after_foreground_pressure_clears():
    assert 'window.addEventListener("p2k-server-foreground-pressure"' in API_CLIENT
    assert 'scheduleOAuthGatewayFlush(); drainQueue();' in API_CLIENT
    assert 'oauthBackgroundMaxPosts: OAUTH_BACKGROUND_MAX_POSTS' in API_CLIENT
    assert 'serverForegroundRequests:' in API_CLIENT
