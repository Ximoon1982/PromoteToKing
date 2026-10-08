from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGE = (ROOT / "ReleaseControl.php").read_text(encoding="utf-8")
AUTH = (ROOT / "server/release-control/src/ReleaseControlAuth.php").read_text(encoding="utf-8")
STATE = (ROOT / "server/release-control/src/ReleaseControlState.php").read_text(encoding="utf-8")
README = (ROOT / "server/release-control/README.md").read_text(encoding="utf-8")


def test_release_identity_and_fixed_recovery_url():
    parts = [int(value) for value in (ROOT / "VERSION").read_text().strip().split(".")]
    assert tuple(parts) >= (2, 14, 0)
    assert "fixed URL <code>/ReleaseControl.php</code>" in PAGE
    assert "read-only" in PAGE


def test_recovery_page_is_shell_independent_and_no_store():
    lowered = PAGE.lower()
    allowed_script = '<script src="/server/release-control/public/release-control-upload.js"></script>'
    assert allowed_script in PAGE
    assert "<script" not in PAGE.replace(allowed_script, "").lower()
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
    version = tuple(int(value) for value in (ROOT / "VERSION").read_text().strip().split("."))
    if version >= (2, 14, 5):
        assert "'promotion'=>$candidateValid && $candidatePreviewValid && $publicValid" in STATE
        assert "'rollback'=>$mode === 'slots'" in STATE
    else:
        assert "'promotion' => false" in STATE
        assert "'rollback' => false" in STATE
    assert "Preview candidate" in PAGE
    assert "Promote candidate" in PAGE
    assert "Rollback" in PAGE


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
    assert "public serving in direct-root mode" in README
    assert "must remain outside any switchable application slot" in README
