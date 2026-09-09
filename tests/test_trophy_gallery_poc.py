from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

def test_trophy_gallery_is_loaded_through_normal_registry():
    registry = text("assets/js/admin/tool-registry.js")
    runtime = text("assets/js/admin/trophy-gallery-poc.js")
    assert "trophy-gallery-poc.js?v=" in registry
    assert "P2K_TROPHY_GALLERY_POC" in runtime
    assert "mountAdmin" in runtime and "mountPublic" in runtime

def test_trophy_gallery_uses_standard_team_native_detail():
    shell = text("assets/js/admin/admin-shell.js")
    assert 'trophies: { title:"Trophy Gallery"' in shell
    assert 'mode:"native",nativeKey:"trophy-gallery"' in shell
    assert 'adminShellCard({key:"trophies",category:"team"' in shell
    assert "p2kTrophyAdminPanel" not in shell

def test_deep_link_maps_to_existing_authorized_administration_route():
    navigation = text("assets/js/pages/dashboard-v2.js")
    assert 'params.get("trophy") === "1" ? "trophies"' in navigation
    assert 'params.get("trophy") === "1" ? "gallery"' in navigation
    assert "dashboardAdministrationTab" not in text("assets/js/admin/trophy-gallery-poc.js")

def test_server_persistence_and_security_contracts_are_present():
    api = text("server/trophy-gallery/public/api.php")
    store = text("server/trophy-gallery/src/TrophyGalleryStore.php")
    assert "Auth::requireAdmin()" in api
    assert "records(true)" in api and "records(false)" in api
    assert "LOCK_EX" in store and "rename($tmp,$this->catalog)" in store
    assert "is_uploaded_file" in store and "getimagesize" in store
    assert "image/svg" not in store
    assert "players" not in store

def test_public_standalone_and_grouping_are_available():
    runtime = text("assets/js/admin/trophy-gallery-poc.js")
    standalone = text("trophies/index.html")
    assert 'state.group==="league"' in runtime
    assert '<option value="chronology">Chronology</option>' in runtime
    assert 'id="p2kTrophyStandalone"' in standalone
