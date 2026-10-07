from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGE = (ROOT / "ReleaseControl.php").read_text(encoding="utf-8")
MANAGER = (ROOT / "server/release-control/src/ReleaseVersionManager.php").read_text(encoding="utf-8")
STATE = (ROOT / "server/release-control/src/ReleaseStateStore.php").read_text(encoding="utf-8")
BOOTSTRAP = (ROOT / "server/release-control/src/bootstrap.php").read_text(encoding="utf-8")
ADMIN = (ROOT / "assets/js/admin/admin-shell.js").read_text(encoding="utf-8")
TP_ADMIN = (ROOT / "TeamPointsAdmin.html").read_text(encoding="utf-8")
TP_ADMIN_JS = (ROOT / "assets/js/pages/team-points-admin.js").read_text(encoding="utf-8")
STORAGE = (ROOT / "server/team-points/src/StorageMetricsService.php").read_text(encoding="utf-8")


def test_v2147_identity_and_ui_scope():
    assert tuple(int(v) for v in (ROOT / "VERSION").read_text().strip().split(".")) >= (2, 14, 7)
    assert ("v2.14.7 · Release/version management" in PAGE) or ("v2.14.8 · ZIP install + filesystem cleanup" in PAGE)
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
    assert ("remove unreferenced obsolete release artifacts" in ADMIN) or ("clean conservative P2K maintenance artifacts" in ADMIN)


def test_release_management_is_single_paginated_detailed_list():
    assert "<h2>Installed release slots</h2>" not in PAGE
    assert "$releasePageSize = 10;" in PAGE
    assert "array_slice($managedReleasesAll, $releasePageOffset, $releasePageSize)" in PAGE
    assert 'aria-label="Release list pagination"' in PAGE
    assert "Showing <?= rc_h($releasePageFrom) ?>–<?= rc_h($releasePageTo) ?>" in PAGE
    assert "Page <?= rc_h($releasePage) ?> of <?= rc_h($releasePageCount) ?>" in PAGE
    assert "?release_page=<?= rc_h($releasePage + 1) ?>#release-management" in PAGE
    assert "?release_page=<?= rc_h($releasePage - 1) ?>#release-management" in PAGE
    assert "$integrityValid ? 'valid' : 'INVALID'" in PAGE


def test_deletion_preview_has_direct_anchor_and_keeps_page_context():
    assert 'id="deletion-preview"' in PAGE
    assert "#deletion-preview" in PAGE
    assert 'name="release_page" value="<?= rc_h($releasePage) ?>"' in PAGE
    assert "cleanup_preview=" in PAGE
    assert "#release-management" not in PAGE.split("cleanup_preview=", 1)[1].split("Preview deletion", 1)[0]


def test_storage_capacity_labels_apparent_size_and_hardlink_semantics():
    assert "apparent/path-summed sizes" in TP_ADMIN
    assert "do not represent physical disk blocks or reclaimable space" in TP_ADMIN
    assert "Apparent filesystem" in TP_ADMIN
    assert "not a forecast of physical disk blocks" in TP_ADMIN
    assert "Apparent filesystem size" in TP_ADMIN_JS
    assert "hard links counted per path" in TP_ADMIN_JS
    assert "Apparent filesystem:" in TP_ADMIN_JS
    assert "renderProjection('Apparent filesystem'" in TP_ADMIN_JS
    assert "'measurement_basis'=>'apparent_path_bytes'" in STORAGE
    assert "'hard_links_counted_per_path'=>true" in STORAGE


def test_same_version_candidate_replacement_requires_exact_accepted_public_identity():
    installer = (ROOT / "server/release-control/src/ReleaseCandidateInstaller.php").read_text(encoding="utf-8")
    builder = (ROOT / "tools/release/v2147/build-universal-2.13x.sh").read_text(encoding="utf-8")
    assert "version_compare((string)$meta['version'], (string)$current['version'], '<')" in installer
    assert "Candidate VERSION must not be older than the current public VERSION." in installer
    assert "Candidate package does not accept current public build" in installer
    for head in (
        "42bceef45141821420dba8cb13d4f65c9fc6c0ba",
        "1d54cce25475c86b3a4af7bb179ff58302de43a2",
        "76f38c288520127554317885691f0159bbd81b35",
    ):
        assert head in builder
