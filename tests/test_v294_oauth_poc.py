from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGE = (ROOT / "OAuthTest.php").read_text(encoding="utf-8")
CONFIG = (ROOT / "config/oauth-test.php").read_text(encoding="utf-8")


def test_oauth_poc_is_exactly_flag_2_gated():
    assert "(string)$_GET['oauth'] === '2'" in PAGE
    assert "http_response_code(404)" in PAGE
    assert "oauth_callback_present()" in PAGE


def test_oauth_poc_has_login_logout_and_identity_display():
    assert 'value="login"' in PAGE
    assert 'value="logout"' in PAGE
    assert "Authenticated Chess.com user" in PAGE
    assert "oauth_extract_username" in PAGE
    assert "Chess.com OAuth login succeeded." in PAGE


def test_oauth_poc_uses_authorization_code_pkce_and_state():
    for marker in (
        "response_type' => 'code'",
        "code_challenge_method' => 'S256'",
        "code_challenge' => $challenge",
        "code_verifier' => $verifier",
        "state' => $state",
        "hash_equals($expectedState, $state)",
    ):
        assert marker in PAGE


def test_oauth_poc_keeps_tokens_server_side_and_isolated():
    assert "window.P2K_AUTH" not in PAGE
    assert "access_token" not in PAGE
    assert "ID tokens to JavaScript" in PAGE
    assert "session_set_cookie_params" in PAGE
    assert "'httponly' => true" in PAGE
    assert "'samesite' => 'Lax'" in PAGE


def test_oauth_poc_has_chess_com_endpoints_and_no_client_secret():
    assert "https://oauth.chess.com/authorize" in CONFIG
    assert "https://oauth.chess.com/token" in CONFIG
    assert "client_secret" not in PAGE.lower()
    assert "client_secret" not in CONFIG.lower()


def test_oauth_poc_has_only_public_registration_parameters():
    for key in ("'name'", "'client_id'", "'redirect_url'"):
        assert key in CONFIG
