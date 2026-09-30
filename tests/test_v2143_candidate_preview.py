from pathlib import Path
import importlib.util

ROOT = Path(__file__).resolve().parents[1]
PAGE = (ROOT / "ReleaseControl.php").read_text(encoding="utf-8")
ROUTER = (ROOT / "PreviewRouter.php").read_text(encoding="utf-8")
SESSION = (ROOT / "server/release-control/src/ReleasePreviewSession.php").read_text(encoding="utf-8")
TREE = (ROOT / "server/release-control/src/ReleasePreviewTree.php").read_text(encoding="utf-8")
STATE = (ROOT / "server/release-control/src/ReleaseControlState.php").read_text(encoding="utf-8")
AUTH = (ROOT / "server/release-control/src/ReleaseControlAuth.php").read_text(encoding="utf-8")
HTACCESS = (ROOT / ".htaccess").read_text(encoding="utf-8")
POLICY = (ROOT / "server/release-control/src/ReleaseSlotPolicy.php").read_text(encoding="utf-8")
SELECTOR_PATH = ROOT / "server/release-control/tools/release-slot-paths.py"


def selector_module():
    spec = importlib.util.spec_from_file_location("p2k_release_slot_paths_v2143", SELECTOR_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


def test_v2143_identity_and_control_plane_contract():
    assert (ROOT / "VERSION").read_text().strip() == "2.14.3"
    assert "v2.14.3 · Super Admin candidate preview" in PAGE
    assert "Public serving is still direct-root" in PAGE
    assert "Preview candidate for me" in PAGE
    assert "Stop preview" in PAGE
    assert "Promote candidate" in PAGE
    assert "Rollback" in PAGE
    assert "'personal_preview' => true" in STATE
    assert "'promotion' => false" in STATE
    assert "'rollback' => false" in STATE
    assert "'public_slot_routing' => false" in STATE
    assert "'candidate_cron' => false" in STATE


def test_preview_enable_uses_prebuilt_tree_and_is_not_a_heavy_web_operation():
    assert "describeExisting($releaseId)" in SESSION
    assert "Candidate preview tree is not prepared" in SESSION
    assert "->prepare($releaseId)" not in SESSION
    assert "Preview tree" in PAGE
    assert "previewTreeReady" in PAGE


def test_preview_cookie_is_signed_identity_bound_and_http_only():
    assert "P2KRC_PREVIEW" in SESSION
    assert "hash_hmac('sha256'" in SESSION
    assert "'secure'=>true" in SESSION
    assert "'httponly'=>true" in SESSION
    assert "'samesite'=>'Lax'" in SESSION
    assert "identity_mismatch" in SESSION
    assert "candidate_changed" in SESSION
    assert "preview-secret.key" in SESSION
    assert "random_bytes(32)" in SESSION


def test_preview_control_uses_authenticated_csrf():
    assert "currentCsrfToken" in AUTH
    assert "hash_equals($expectedCsrf, $providedCsrf)" in PAGE
    assert 'name="csrf"' in PAGE
    assert 'name="action" value="enable-preview"' in PAGE
    assert 'name="action" value="disable-preview"' in PAGE


def test_preview_router_recovers_original_uri_after_apache_internal_rewrite():
    assert "__p2k_preview_path" in ROUTER
    assert "p2k_preview_transport($_SERVER)" in ROUTER
    assert "p2k_preview_original_uri($_SERVER)" in ROUTER
    assert "THE_REQUEST" in ROUTER
    assert "P2K_PREVIEW_ORIGINAL_PATH" in ROUTER
    assert "RewriteRule ^(.*)$ PreviewRouter.php?__p2k_preview_path=$1 [END,QSA,B]" in HTACCESS


def test_preview_router_is_cookie_gated_and_keeps_recovery_shared_paths_out():
    assert "P2KRC_PREVIEW" in HTACCESS
    assert "PreviewRouter.php" in HTACCESS
    assert "server/release-control" in HTACCESS
    assert "auth/callback" in HTACCESS
    assert "data" in HTACCESS and "logs" in HTACCESS and "storage" in HTACCESS
    assert "ReleaseSlotPolicy::isReleaseOwnedPath" in ROUTER
    assert "X-P2K-Candidate-Preview" in ROUTER
    assert "Candidate preview writes are disabled" in ROUTER
    assert "['GET','HEAD']" in ROUTER
    assert "P2K_TP_CONFIG" in ROUTER
    assert "p2k-candidate-preview-banner" in ROUTER


def test_preview_tree_uses_candidate_files_and_only_explicit_shared_links():
    assert "inspectSlot($releaseId, true)" in TREE
    assert "symlink_supported" in TREE
    assert "atomic_rename_supported" in TREE
    assert "@link($source, $dest)" in TREE
    assert "@copy($source, $dest)" in TREE
    assert "['data', 'logs', 'storage']" in TREE
    assert "str_contains($name, '.local.')" in TREE
    assert "server/release-control/" in TREE
    assert "@rename($tmp, $target)" in TREE


def test_recovery_files_are_outside_policy_v2_but_legacy_policy_still_supported():
    assert "POLICY_VERSION = 2" in POLICY
    assert "isReleaseOwnedPathForVersion" in POLICY
    mod = selector_module()
    assert not mod.included(".htaccess")
    assert not mod.included("ReleaseControl.php")
    assert not mod.included("PreviewRouter.php")
    assert not mod.included("server/release-control/src/ReleasePreviewSession.php")
    assert mod.included("VERSION")
    assert mod.included("ui-v2.html")
