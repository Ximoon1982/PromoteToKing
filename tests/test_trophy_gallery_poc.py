from pathlib import Path
import hashlib

ROOT = Path(__file__).resolve().parents[1]

def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

def test_trophy_gallery_is_loaded_through_normal_registry():
    # Preserve the exact POC loader contract at qualified v2.11.5.
    import subprocess
    def released(path):
        return subprocess.check_output(["git", "show", f"c534b2dbb0346eac0fa6de869621d6b7d785ead8:{path}"], cwd=ROOT)
    registry = released("assets/js/admin/tool-registry.js").decode("utf-8")
    runtime_bytes = released("assets/js/admin/trophy-gallery-poc.js")
    runtime = runtime_bytes.decode("utf-8")
    runtime_key = hashlib.sha256(runtime_bytes).hexdigest()[:12]
    assert f"trophy-gallery-poc.js?v=poc-{runtime_key}-20260911-engraving-handoff" in registry
    assert "P2K_TROPHY_GALLERY_POC" in runtime
    assert "mountAdmin" in runtime and "mountPublic" in runtime
    current = text("assets/js/admin/tool-registry.js")
    assert 'const trophyPocReady = loadTrophyScript("p2kTrophyGalleryPocScript", "assets/js/admin/trophy-gallery-poc.js")' in current
    assert 'script.src = `${path}?v=${TROPHY_RUNTIME_KEY}`' in current
    assert 'window.P2K_TROPHY_GALLERY_POC?.mount?.(context)' in current
    assert 'function loadTrophyAdminV2134()' in current
    assert 'window.addEventListener("p2k-admin-shell-route",maybeLoadTrophyAdmin)' in current
    assert 'void loadTrophyGalleryV2121()' not in current

def test_trophy_gallery_uses_standard_team_native_detail():
    shell = text("assets/js/admin/admin-shell.js")
    assert 'trophies: { title:"Trophy Gallery"' in shell
    assert 'mode:"native",nativeKey:"trophy-gallery"' in shell
    assert 'adminShellCard({key:"trophies",category:"team"' in shell
    assert "p2kTrophyAdminPanel" not in shell

def test_deep_link_maps_to_existing_authorized_administration_route():
    navigation = text("assets/js/pages/dashboard-v2.js")
    assert 'const trophyCompatibilityRoute = params.get("trophy") === "1";' in navigation
    assert 'adminDetail: trophyCompatibilityRoute ? "trophies"' in navigation
    assert 'const requestedView = trophyCompatibilityRoute ||' in navigation
    assert 'adminDetailTab: trophyCompatibilityRoute ? "gallery"' in navigation
    assert "dashboardAdministrationTab" not in text("assets/js/admin/trophy-gallery-poc.js")

def test_server_persistence_and_security_contracts_are_present():
    api = text("server/trophy-gallery/public/api.php")
    store = text("server/trophy-gallery/src/TrophyGalleryStore.php")
    assert "Auth::requireAdmin()" in api
    assert "catalogue(true)" in api and "catalogue(false)" in api
    assert "LOCK_EX" in store and "rename($tmp,$this->catalog)" in store
    assert "is_uploaded_file" in store and "getimagesize" in store
    assert "image/svg" not in store
    assert "players" not in store

def test_public_standalone_and_grouping_are_available():
    runtime = text("assets/js/admin/trophy-gallery-poc.js")
    standalone = text("trophies/index.html")
    assert 'state.group==="league"' in runtime
    assert '<option value="chronology">Chronological</option>' in runtime
    assert 'id="p2kTrophyStandalone"' in standalone
