from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGE = (ROOT / "ReleaseControl.php").read_text(encoding="utf-8")
MANAGER = (ROOT / "server/release-control/src/ReleaseVersionManager.php").read_text(encoding="utf-8")
STATE = (ROOT / "server/release-control/src/ReleaseStateStore.php").read_text(encoding="utf-8")
BOOTSTRAP = (ROOT / "server/release-control/src/bootstrap.php").read_text(encoding="utf-8")
ADMIN = (ROOT / "assets/js/admin/admin-shell.js").read_text(encoding="utf-8")


def test_v2147_identity_and_ui_scope():
    assert (ROOT / "VERSION").read_text().strip() == "2.14.7"
    assert "v2.14.7 · Release/version management" in PAGE
    assert "Release/version management" in PAGE
    assert "Deletion preview — nothing has been deleted yet." in PAGE
    assert "Delete obsolete release" in PAGE
    assert "release-control-owned immutable slots" in PAGE


def test_cleanup_is_lock_revalidated_and_protects_all_live_roles():
    assert "withExclusiveLock" in STATE
    assert "withExclusiveLock(function" in MANAGER
    assert "current public" in MANAGER
    assert "rollback target" in MANAGER
    assert "candidate" in MANAGER
    assert "physical recovery baseline" in MANAGER
    assert "directRootReleaseId" in MANAGER
    assert "acquired a protected role after page render" in MANAGER


def test_cleanup_scope_is_narrow_and_hardlink_aware():
    assert "release-control/{releases,previews,runtime-trees}" in MANAGER
    assert "'slot'=>$control . '/releases'" in MANAGER
    assert "'preview'=>$control . '/previews'" in MANAGER
    assert "'runtime'=>$control . '/runtime-trees'" in MANAGER
    assert "estimated_reclaimable_bytes" in MANAGER
    assert "hardlink_preserved_bytes" in MANAGER
    assert "nlink" in MANAGER
    assert "data/" not in MANAGER.split("private function managedRoots", 1)[1].split("private function scanPaths", 1)[0]


def test_release_control_uses_csrf_and_explicit_confirmation_for_delete():
    assert "delete-release" in PAGE
    assert "validateControlCsrfToken" in PAGE
    assert 'name="confirm" value="yes" required' in PAGE
    assert "$releaseManager->deleteRelease($releaseId, $username)" in PAGE


def test_manager_is_in_recovery_bootstrap_and_admin_card_mentions_cleanup():
    assert "ReleaseVersionManager.php" in BOOTSTRAP
    assert "remove unreferenced obsolete release artifacts" in ADMIN
