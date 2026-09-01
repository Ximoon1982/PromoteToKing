from pathlib import Path


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
    assert "refresh_token" not in info
