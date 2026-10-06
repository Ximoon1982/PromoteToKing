from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGE = (ROOT / "ReleaseControl.php").read_text(encoding="utf-8")
STATE = (ROOT / "server/release-control/src/ReleaseControlState.php").read_text(encoding="utf-8")
PKG = (ROOT / "server/release-control/src/ReleaseCandidatePackage.php").read_text(encoding="utf-8")
INSTALLER = (ROOT / "server/release-control/src/ReleaseCandidateInstaller.php").read_text(encoding="utf-8")
STATE_STORE = (ROOT / "server/release-control/src/ReleaseStateStore.php").read_text(encoding="utf-8")
CLI = (ROOT / "server/release-control/tools/install-candidate.php").read_text(encoding="utf-8")


def test_v2142_identity_and_recovery_ui_boundary():
    parts = tuple(int(value) for value in (ROOT / "VERSION").read_text().strip().split("."))
    assert parts >= (2, 14, 2)
    assert "direct-root" in PAGE
    assert "<script" not in PAGE.lower()
    assert "<link" not in PAGE.lower()


def test_candidate_package_contract_is_exact_and_release_owned_only():
    assert "CANDIDATE_RELEASE.json" in PKG
    assert "CANDIDATE_PAYLOAD.sha256" in PKG
    assert "CANDIDATE_REMOVED_PATHS.txt" in PKG
    assert "accepted_public_builds" in PKG
    assert "payload_manifest_sha256" in PKG
    assert "source HEAD must be an exact 40-character commit SHA" in PKG
    assert "ReleaseSlotPolicy::isReleaseOwnedPath" in PKG
    assert "is_link($file)" in PKG
    assert "Candidate payload build identity differs from candidate metadata" in PKG


def test_candidate_install_uses_verified_base_slot_and_never_routes():
    assert "currentPublicIdentity()" in INSTALLER
    assert "inspectSlot($baseReleaseId, true)" in INSTALLER
    assert "Candidate VERSION must be newer" in INSTALLER
    assert "@link($source, $dest)" in INSTALLER
    assert "@copy($source, $dest)" in INSTALLER
    assert "@rename($tmp, $final)" in INSTALLER
    assert "'routing_enabled'=>false" in INSTALLER
    assert "'candidate'=>true" in INSTALLER
    assert "registerCandidate" in INSTALLER


def test_candidate_state_write_is_locked_atomic_and_direct_root():
    assert "flock($lock, LOCK_EX)" in STATE_STORE
    assert "state.json" in STATE_STORE
    assert ("'mode'=>'direct-root'" in STATE_STORE) or ("'mode' => 'direct-root'" in STATE_STORE)
    assert "candidate_release" in STATE_STORE
    assert "candidate_registered_at" in STATE_STORE
    assert "@rename($tmp, $path)" in STATE_STORE
    assert "Use explicit replacement to change it" in STATE_STORE


def test_release_control_reports_candidate_but_serving_controls_stay_disabled():
    assert "'candidate_install' => true" in STATE
    assert "'candidate_registration' => true" in STATE
    version = tuple(int(value) for value in (ROOT / "VERSION").read_text().strip().split("."))
    if version >= (2, 14, 5):
        assert "'promotion'=>$candidateValid && $publicValid" in STATE
        assert "'rollback'=>$mode === 'slots'" in STATE
        assert "'public_slot_routing'=>true" in STATE
    else:
        assert "'promotion' => false" in STATE
        assert "'rollback' => false" in STATE
        assert "'public_slot_routing' => false" in STATE
    assert "Preview candidate" in PAGE
    assert "Promote candidate" in PAGE
    assert "Rollback" in PAGE


def test_candidate_cli_is_cli_only_and_checks_public_identity_files():
    assert "PHP_SAPI !== 'cli'" in CLI
    assert "--package=/path/to/extracted/package" in CLI
    assert "replace-candidate" in CLI
    assert "Candidate installation modified the direct-root public identity files" in CLI
