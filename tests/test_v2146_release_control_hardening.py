from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGE = (ROOT / "ReleaseControl.php").read_text(encoding="utf-8")
AUTH = (ROOT / "server/release-control/src/ReleaseControlAuth.php").read_text(encoding="utf-8")


def test_release_control_actions_use_recovery_plane_csrf():
    assert "controlCsrfToken($username)" in PAGE
    assert "validateControlCsrfToken($username, $providedCsrf)" in PAGE
    assert "validateControlCsrfToken($usernameHint, $providedCsrf)" in PAGE
    assert "$auth->currentCsrfToken()" not in PAGE


def test_recovery_plane_csrf_is_identity_bound_and_session_independent():
    assert "public function controlCsrfToken(string $username)" in AUTH
    assert "public function validateControlCsrfToken(string $username, string $token)" in AUTH
    assert "action-csrf.key" in AUTH
    assert "hash_hmac('sha256'" in AUTH
    assert "'v1|' . $username . '|' . $expires" in AUTH
    block = AUTH.split("public function controlCsrfToken", 1)[1].split("public function allowlistSource", 1)[0]
    assert "P2KTPSESSID" not in block
    assert "P2KOAUTH" not in block
