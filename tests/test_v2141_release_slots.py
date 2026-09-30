from pathlib import Path
import importlib.util

ROOT = Path(__file__).resolve().parents[1]
PAGE = (ROOT / "ReleaseControl.php").read_text(encoding="utf-8")
STATE = (ROOT / "server/release-control/src/ReleaseControlState.php").read_text(encoding="utf-8")
POLICY = (ROOT / "server/release-control/src/ReleaseSlotPolicy.php").read_text(encoding="utf-8")
MATERIALIZER = (ROOT / "server/release-control/src/ReleaseSlotMaterializer.php").read_text(encoding="utf-8")
PROBE = (ROOT / "server/release-control/src/ReleaseSlotFilesystemProbe.php").read_text(encoding="utf-8")
STORE = (ROOT / "server/release-control/src/ReleaseSlotStore.php").read_text(encoding="utf-8")
SELECTOR_PATH = ROOT / "server/release-control/tools/release-slot-paths.py"

def selector_module():
    spec = importlib.util.spec_from_file_location("p2k_release_slot_paths", SELECTOR_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module

def test_v2141_release_identity_and_read_only_recovery_page():
    assert (ROOT / "VERSION").read_text().strip() == "2.14.1"
    assert "v2.14.1 · release-slot foundation · read-only" in PAGE
    assert "Public serving is still direct-root" in PAGE
    assert "Installed release slots" in PAGE
    assert "Preview candidate" in PAGE and "disabled" in PAGE
    assert "Promote candidate" in PAGE
    assert "Rollback" in PAGE
    lowered = PAGE.lower()
    assert "<script" not in lowered
    assert "<link" not in lowered

def test_web_state_projection_remains_non_mutating():
    for token in ["file_put_contents(", "rename(", "unlink(", "mkdir(", "rmdir("]:
        assert token not in STATE, token
    assert "'slot_materialization' => true" in STATE
    assert "'candidate_install' => false" in STATE
    assert "'personal_preview' => false" in STATE
    assert "'promotion' => false" in STATE
    assert "'rollback' => false" in STATE
    assert "'state_mutation' => false" in STATE
    assert "'public_slot_routing' => false" in STATE

def test_release_slot_policy_keeps_shared_and_recovery_state_external():
    assert "ReleaseControl.php" in POLICY
    assert "server/release-control/" in POLICY
    assert "data/" in POLICY and "logs/" in POLICY and "storage/" in POLICY
    assert ".local." in POLICY
    mod = selector_module()
    assert mod.included("VERSION")
    assert mod.included("ui-v2.html")
    assert mod.included("MaxRatingBackfill.php")
    assert mod.included("assets/js/site-config.js")
    assert mod.included("config/site-branding.js")
    assert mod.included("config/oauth-test.php")
    assert mod.included("OAuthTest.php")
    assert mod.included("cron-dispatch-v2.9.22.sh")
    assert mod.included("cron-mca-results-v2.10.6.25.sh")
    assert mod.included("weekly-backup-v2.9.22.sh")
    assert not mod.included("install-oauth-v2.9.22.sh")
    assert not mod.included("reset-install-cron-v2.9.22.sh")
    assert mod.included("server/team-points/src/Repository.php")
    assert mod.included("server/team-points/config/config.example.php")
    assert not mod.included("ReleaseControl.php")
    assert not mod.included("server/release-control/src/ReleaseControlState.php")
    assert not mod.included("data/runtime-v280/cache/a.json")
    assert not mod.included("server/team-points/config/config.local.php")
    assert not mod.included("server/team-points/config/oauth.local.php")

def test_materializer_is_snapshot_only_and_atomic():
    assert "ReleaseSlotFilesystemProbe" in MATERIALIZER
    assert "@link($source, $dest)" in MATERIALIZER
    assert "@copy($source, $dest)" in MATERIALIZER
    assert "@rename($tmp, $final)" in MATERIALIZER
    assert "Existing release slot is invalid and will not be overwritten" in MATERIALIZER
    assert "routing_enabled' => false" in MATERIALIZER
    assert "shared_paths_external" in MATERIALIZER
    assert "P2K_RELEASE_SLOT_FORCE_COPY" in MATERIALIZER

def test_filesystem_probe_tests_ionos_relevant_primitives():
    assert "hardlink_supported" in PROBE
    assert "hardlink_snapshot_isolation" in PROBE
    assert "symlink_supported" in PROBE
    assert "symlink_snapshot_safe' => false" in PROBE
    assert "atomic_rename_supported" in PROBE
    assert "selected_strategy" in PROBE
    assert "filesystem-capabilities.json" in PROBE

def test_slot_inspection_validates_manifest_and_files_without_mutation():
    assert "manifest digest mismatch" in STORE
    assert "manifest files are missing" in STORE
    assert "shared/recovery paths leaked into the slot" in STORE
    assert "file hash mismatches" in STORE
    for token in ["file_put_contents(", "rename(", "unlink(", "mkdir(", "rmdir("]:
        assert token not in STORE, token
