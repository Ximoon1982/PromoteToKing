from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGE = (ROOT / "ReleaseControl.php").read_text(encoding="utf-8")
AUTH = (ROOT / "server/release-control/src/ReleaseControlAuth.php").read_text(encoding="utf-8")
STATE = (ROOT / "server/release-control/src/ReleaseControlState.php").read_text(encoding="utf-8")
README = (ROOT / "server/release-control/README.md").read_text(encoding="utf-8")


def test_release_identity_and_fixed_recovery_url():
    assert (ROOT / "VERSION").read_text().strip() == "2.14.0"
    assert "fixed URL <code>/ReleaseControl.php</code>" in PAGE
    assert "v2.14.0 foundation · read-only" in PAGE


def test_recovery_page_is_shell_independent_and_no_store():
    lowered = PAGE.lower()
    assert "<script" not in lowered
    assert "<link" not in lowered
    assert "dashboard-v2.js" not in PAGE
    assert "ui-v2.html" not in PAGE
    assert "Cache-Control: no-store" in PAGE
    assert "frame-ancestors 'none'" in PAGE
    assert "X-Frame-Options: DENY" in PAGE


def test_v2140_recovery_plane_is_read_only():
    forbidden = ["file_put_contents(", "rename(", "unlink(", "mkdir(", "rmdir("]
    for token in forbidden:
        assert token not in STATE, token
    assert "'candidate_install' => false" in STATE
    assert "'personal_preview' => false" in STATE
    assert "'promotion' => false" in STATE
    assert "'rollback' => false" in STATE
    assert "'state_mutation' => false" in STATE
    assert "Candidate installation, personal preview, promotion and rollback are intentionally disabled" in PAGE


def test_recovery_auth_is_server_side_and_read_only():
    assert "P2KTPSESSID" in AUTH
    assert "P2KOAUTH" in AUTH
    assert "read_and_close" in AUTH
    assert "P2K_RELEASE_CONTROL_ADMINS" in AUTH
    assert "config.local.php" in AUTH
    assert "return=' . rawurlencode('/ReleaseControl.php')" in AUTH
    assert "access_token" in AUTH
    assert "echo" not in AUTH


def test_state_contract_and_recovery_boundary_are_documented():
    assert "schema_version" in README
    assert '"mode": "direct-root"' in README
    assert "v2.14.0 does not create this file" in README
    assert "must remain outside any switchable application slot" in README
