from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_trophy_gallery_poc_is_loaded_only_through_admin_registry():
    registry = text("assets/js/admin/tool-registry.js")
    poc = text("assets/js/admin/trophy-gallery-poc.js")
    assert "trophy-gallery-poc.js?v=poc-dcd71c8e-1" in registry
    assert "dashboardAdministrationTab" in poc
    assert "isAdminVisible()" in poc
    assert "if(!isAdminVisible())return" in poc


def test_trophy_gallery_poc_mounts_hall_and_admin_surfaces():
    poc = text("assets/js/admin/trophy-gallery-poc.js")
    assert 'data.hallSubtab="trophies"' in poc
    assert 'data.hallPanel="trophies"' in poc
    assert 'p2kTrophyAdminPanel' in poc
    assert 'adminToolGrid' in poc
    assert 'data-trophy-admin-card' in poc


def test_trophy_gallery_poc_has_imported_visuals_and_workflow_controls():
    poc = text("assets/js/admin/trophy-gallery-poc.js")
    for marker in (
        "php169n9ussq2pv6QXsaHt.png",
        "phpbdkgpmqj53bj0Fpy5XH.png",
        "php693lr5tfd7f59LBxG9p.png",
        "php5vt7kffnmide1GmnI0E.png",
    ):
        assert marker in poc
    for marker in (
        'status: "published"',
        'status: "draft"',
        "Add trophy",
        "Publish",
        "Duplicate",
        "Delete",
        "Detour threshold",
        "Use processed PNG",
    ):
        assert marker in poc


def test_trophy_gallery_poc_does_not_introduce_server_persistence():
    poc = text("assets/js/admin/trophy-gallery-poc.js")
    assert "localStorage" in poc
    assert "fetch(" not in poc
    assert "XMLHttpRequest" not in poc
