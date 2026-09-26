from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

def test_v2134_release_identity():
    assert read("VERSION").strip() == "2.13.4"

def test_admin_overview_metrics_are_deferred_while_detail_is_active():
    shell = read("assets/js/admin/admin-shell.js")
    assert "if(!force&&adminDetailDefinition())return" in shell
    assert "if(!state.adminShellLoadedAt)void loadAdminShellMetrics()" in shell

def test_trophy_admin_runtime_is_lazy_and_parallelized():
    registry = read("assets/js/admin/tool-registry.js")
    assert 'const trophyPocReady = loadTrophyScript("p2kTrophyGalleryPocScript"' in registry
    assert "function loadTrophyAdminV2134()" in registry
    assert "trophyAdminReady = Promise.all([" in registry
    assert 'loadTrophyScript("p2kTrophyAdminViewV2121"' in registry
    assert 'loadTrophyScript("p2kTrophyMatchesV2121"' in registry
    assert 'loadTrophyScript("p2kTrophyEngraverV2121"' in registry
    assert ']).then(() => loadTrophyScript("p2kTrophyAdminV2121"' in registry
    assert 'window.addEventListener("p2k-admin-shell-route",maybeLoadTrophyAdmin)' in registry
    assert "void loadTrophyGalleryV2121();" not in registry
