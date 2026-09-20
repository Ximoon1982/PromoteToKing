from pathlib import Path
import subprocess


ROOT = Path(__file__).resolve().parents[1]


def test_seven_day_login_keeps_cookie_and_csrf_security_contract():
    source = (ROOT / 'server/team-points/src/OAuthSession.php').read_text(encoding='utf-8')
    assert 'SESSION_RETENTION_SECONDS = 604800' in source
    assert "'secure'=>$secure" in source
    assert "'httponly'=>true" in source
    assert "'samesite'=>'Lax'" in source
    assert "'expires'=>time()+self::SESSION_RETENTION_SECONDS" in source
    assert "$_SESSION['oauth_csrf']=self::b64(random_bytes(24))" in source
    assert "hash_equals($expected,$value)" in source


def test_refresh_rotates_session_id_and_never_exposes_tokens():
    source = (ROOT / 'server/team-points/src/OAuthSession.php').read_text(encoding='utf-8')
    refresh_start = source.index('private static function refreshAccessToken')
    refresh_end = source.index('private static function scope', refresh_start)
    refresh = source[refresh_start:refresh_end]
    assert "'grant_type'=>'refresh_token'" in refresh
    assert "session_regenerate_id(true)" in refresh
    info_start = source.index('public static function sessionInfo()')
    info_end = source.index('public static function authenticatedUsername', info_start)
    info = source[info_start:info_end]
    assert "'expires_at'" in info
    assert "access_token" not in info
    assert "'refresh_token'=>" not in info


def test_oauth_session_store_is_isolated_and_restores_callers():
    source = (ROOT / 'server/team-points/src/OAuthSession.php').read_text(encoding='utf-8')
    assert "'/sessions/oauth'" in source
    assert 'session_save_path($isolatedPath)' in source
    assert 'migrateLegacySessionFile' in source
    assert 'restoreSessionRuntime' in source
    assert "'store_isolated'=>self::$sessionStoreIsolated" in source
    result = subprocess.run(
        ['php', str(ROOT / 'tests/test_v2111_oauth_session_isolation.php')],
        cwd=ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, result.stdout + result.stderr
    assert 'Validated P2KOAUTH isolated storage' in result.stdout


def test_transient_session_endpoint_failure_is_not_presented_as_logout():
    source = (ROOT / 'assets/js/shared/real-oauth.js').read_text(encoding='utf-8')
    assert 'async function refreshSession(attempt = 0)' in source
    assert 'if (attempt < 1)' in source
    assert 'Authentication temporarily unavailable' in source
    assert 'sessionStatusUnavailable' in source
