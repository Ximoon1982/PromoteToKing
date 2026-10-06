from pathlib import Path
import importlib.util

ROOT = Path(__file__).resolve().parents[1]

def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

PAGE = read("ReleaseControl.php")
HTACCESS = read(".htaccess")
PUBLIC = read("PublicRouter.php")
POLICY = read("server/release-control/src/ReleaseSlotPolicy.php")
STATE = read("server/release-control/src/ReleaseControlState.php")
STATE_STORE = read("server/release-control/src/ReleaseStateStore.php")
DEPLOY = read("server/release-control/src/ReleaseDeploymentManager.php")
RUNTIME = read("server/release-control/src/ReleaseRuntimeTree.php")
INSTALLER = read("server/release-control/src/ReleaseCandidateInstaller.php")
README = read("server/release-control/README.md")
DISPATCH = read("cron-dispatch-v2.9.22.sh")
SELECTOR_PATH = ROOT / "server/release-control/tools/release-slot-paths.py"


def selector_module():
    spec = importlib.util.spec_from_file_location("p2k_release_slot_paths_v2145", SELECTOR_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


def test_v2145_identity_and_control_plane():
    assert tuple(int(v) for v in read("VERSION").strip().split(".")) >= (2, 14, 5)
    assert ("Atomic promotion and rollback" in PAGE
            or "Operational proof and hardening" in PAGE
            or "Release/version management" in PAGE)
    assert "Promote candidate" in PAGE
    assert "Rollback" in PAGE
    assert "confirm" in PAGE
    assert "ReleaseDeploymentManager" in PAGE
    assert "<script" not in PAGE.lower()
    assert "<link" not in PAGE.lower()


def test_public_router_is_stable_and_ordered_after_preview():
    assert "PublicRouter.php" in HTACCESS
    preview = HTACCESS.index("/PreviewRouter.php [L]")
    public = HTACCESS.index("/PublicRouter.php [L]", preview)
    assert preview < public
    assert r"ReleaseControl\.php|PreviewRouter\.php|PublicRouter\.php|server/release-control" in HTACCESS
    assert "auth/callback" in HTACCESS
    assert "P2KRC_PREVIEW" in HTACCESS
    assert "X-P2K-Public-Release" in PUBLIC
    assert "X-P2K-Public-Mode" in PUBLIC
    assert "p2k_public_relative_path" in PUBLIC
    assert "api/[^/]+/?$" in PUBLIC
    assert "ReleaseRuntimeTree" in PUBLIC
    assert "public_manifest_sha256" in PUBLIC


def test_public_router_keeps_recovery_paths_out_of_slots():
    assert "POLICY_VERSION = 3" in POLICY
    assert "publicrouter.php" in POLICY.lower()
    mod = selector_module()
    for path in [".htaccess", "ReleaseControl.php", "PreviewRouter.php", "PublicRouter.php"]:
        assert not mod.included(path)
    assert not mod.included("server/release-control/src/ReleaseDeploymentManager.php")
    assert mod.included("VERSION")
    assert mod.included("ui-v2.html")


def test_runtime_tree_is_prepared_before_atomic_pointer_switch():
    assert "inspectSlot($releaseId, true)" in RUNTIME
    assert "symlink_supported" in RUNTIME
    assert "atomic_rename_supported" in RUNTIME
    assert "@link($source, $dest)" in RUNTIME
    assert "@copy($source, $dest)" in RUNTIME
    assert "['data', 'logs', 'storage']" in RUNTIME
    assert "@symlink($source, $dest)" in RUNTIME
    assert "@rename($tmp, $target)" in RUNTIME

    promote = DEPLOY.split("public function promote", 1)[1].split("public function rollback", 1)[0]
    assert "inspectSlot($candidateId, true)" in promote
    assert "currentPublicSlot(true)" in promote
    assert "runtimeTrees->prepare" in promote
    assert "promoteCandidate" in promote
    assert promote.index("runtimeTrees->prepare") < promote.index("promoteCandidate")


def test_state_transition_is_one_atomic_state_file_replacement():
    assert "promoteCandidate" in STATE_STORE
    assert "rollbackPublic" in STATE_STORE
    assert "flock($lock, LOCK_EX)" in STATE_STORE
    assert "@rename($tmp, $path)" in STATE_STORE
    assert "$state['mode'] = 'slots';" in STATE_STORE
    assert "$state['previous_public_release'] = $currentId;" in STATE_STORE
    assert "$state['public_release'] = $candidateId;" in STATE_STORE
    assert "$state['candidate_release'] = $currentId;" in STATE_STORE
    assert "'action'=>'rollback'" in STATE_STORE
    assert "transition_sequence" in STATE_STORE


def test_rollback_restores_rolled_back_release_as_candidate():
    rollback = STATE_STORE.split("public function rollbackPublic", 1)[1].split("public function read", 1)[0]
    assert "$state['public_release'] = $previousId;" in rollback
    assert "$state['previous_public_release'] = null;" in rollback
    assert "$state['candidate_release'] = $currentId;" in rollback
    assert "candidate_build_id" in rollback
    assert "candidate_qualification_workflow" in rollback


def test_candidate_installer_uses_routed_public_slot_after_first_promotion():
    current = INSTALLER.split("private function currentPublicIdentity", 1)[1].split("private function readSlotManifest", 1)[0]
    assert "$mode === 'slots'" in current
    assert "$state['public_release']" in current
    assert "inspectSlot($releaseId, true)" in current
    assert "Current public release-slot identity is invalid" in current


def test_release_control_reports_active_public_and_transition_capabilities():
    assert "'public_slot_routing'=>true" in STATE
    assert "'public_slot_routing_active'=>$mode === 'slots'" in STATE
    assert "'public_cron_follows_release'=>true" in STATE
    assert "'promotion'=>$candidateValid && $candidatePreviewValid && $publicValid" in STATE
    assert "'rollback'=>$mode === 'slots'" in STATE
    assert "Public VERSION" in PAGE
    assert "Physical root VERSION" in PAGE
    assert "Transition #" in PAGE
    assert "Last transition" in PAGE


def test_existing_http_cron_dispatch_follows_public_router_surface():
    assert "BASE_URL" in DISPATCH
    for endpoint in [
        "server/team-points/public/cron-club.php",
        "server/team-points/public/cron-player.php",
        "server/tournaments/public/cron.php",
        "api/track-upcoming-league-matches/",
    ]:
        assert endpoint in DISPATCH
    assert "(?:api|artwork|artwork-masters|assets|auth|config|resources|server|trophies)" in HTACCESS


def test_documentation_preserves_physical_root_and_defers_lifecycle_proof():
    assert "one atomic state-file replacement" in README
    assert "physical Promote to King root remains the recovery baseline" in README
    assert "HTTP/curl CRON endpoints" in README
    assert "rolled-back v2.14.5 slot" in README
    assert "v2.14.6" in README
