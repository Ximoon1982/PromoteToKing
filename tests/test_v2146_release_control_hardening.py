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


ADMIN_SHELL = (ROOT / "assets/js/admin/admin-shell.js").read_text(encoding="utf-8")


def test_release_control_is_exposed_as_maintenance_card_without_embedding():
    assert "function adminReleaseControlCard()" in ADMIN_SHELL
    assert 'title:"Release Control"' not in ADMIN_SHELL  # card is standalone, not an iframe detail definition
    assert "adminReleaseControlCard()," in ADMIN_SHELL
    assert 'href="/ReleaseControl.php"' in ADMIN_SHELL
    assert "/ReleaseControl.php" in ADMIN_SHELL
    card = ADMIN_SHELL.split("function adminReleaseControlCard()", 1)[1].split("function adminMemberLookupCard()", 1)[0]
    assert "iframe" not in card.lower()
    assert "Recovery plane" in card
    assert "Super Admin" in card


API_ROUTER = (ROOT / "api/router.php").read_text(encoding="utf-8")
RUNTIME_DIAGNOSTICS = (ROOT / "assets/js/pages/runtime-diagnostics.js").read_text(encoding="utf-8")


def test_runtime_diagnostics_separate_release_identity_from_component_metadata():
    assert "Release version markers disagree" not in API_ROUTER
    assert "Browser site-config" not in RUNTIME_DIAGNOSTICS
    assert "Loaded site-config cache markers include" not in RUNTIME_DIAGNOSTICS
    assert "'identity_source'=>'VERSION + stamped build cache key'" in API_ROUTER
    assert "'component_versions_are_release_identity'=>false" in API_ROUTER
    assert "Site-config component" in RUNTIME_DIAGNOSTICS
    assert "Manifest component" in RUNTIME_DIAGNOSTICS


def test_preview_cron_diagnostics_are_explicitly_isolated():
    assert "'status'=>'preview_isolated'" in API_ROUTER
    assert "'cron_context'=>$previewActive?'isolated_candidate_sandbox':'public_runtime'" in API_ROUTER
    assert "preview isolated" in RUNTIME_DIAGNOSTICS
