from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_v2150_trophy_modal_assets_are_loaded_on_both_entry_points():
    ui = read("ui-v2.html")
    standalone = read("trophies/index.html")
    css_key = "trophy-gallery-v2150-mobile.css?v=p2k-2.15.0-trophy-modal-layout-2"
    js_key = "trophy-gallery-v2150-modal-layout.js?v=p2k-2.15.0-trophy-modal-layout-2"
    assert css_key in ui
    assert css_key in standalone
    assert js_key in ui
    assert js_key in standalone


def test_v2150_orientation_classifier_is_desktop_only_and_uses_real_image_dimensions():
    js = read("assets/js/admin/trophy-gallery-v2150-modal-layout.js")
    assert "const LANDSCAPE_RATIO = 1.10;" in js
    assert 'const DESKTOP = "(min-width: 781px)";' in js
    assert "img.naturalWidth / img.naturalHeight" in js
    assert 'classList.toggle("p2k-v2150-landscape"' in js
    assert 'classList.toggle("p2k-v2150-side-by-side"' in js
    assert 'img.addEventListener("load"' in js


def test_v2150_landscape_modal_stacks_art_above_details_on_desktop():
    css = read("assets/trophy-gallery/trophy-gallery-v2150-mobile.css")
    assert "@media (min-width: 781px)" in css
    assert ".p2k-r538-modal.p2k-v2150-landscape .p2k-r538-modal-body" in css
    assert "grid-template-columns: minmax(0, 1fr) !important;" in css
    assert "height: min(58dvh, 640px) !important;" in css


def test_v2150_portrait_and_square_keep_existing_side_by_side_layout():
    css = read("assets/trophy-gallery/trophy-gallery-v2150-mobile.css")
    # The override must only alter the body grid when the landscape class is present.
    body_grid_rules = [
        line for line in css.splitlines()
        if "grid-template-columns:" in line
    ]
    assert body_grid_rules == ["    grid-template-columns: minmax(0, 1fr) !important;"]


def test_v2150_modal_image_click_still_opens_existing_viewer_and_viewer_is_full_viewport():
    legacy = read("assets/js/admin/trophy-gallery-r5fix3.8.js")
    css = read("assets/trophy-gallery/trophy-gallery-v2150-mobile.css")
    js = read("assets/js/admin/trophy-gallery-v2150-modal-layout.js")
    assert 'artImg.addEventListener("click",enlarge);' in legacy
    assert "function openViewer(url" in legacy
    assert ".p2k-r538-viewer {" in css
    assert "width: 100% !important;" in css
    assert "height: 100dvh !important;" in css
    assert 'if (event.key !== "Escape") return;' in js
    assert "viewer.hidden = true;" in js


def test_v2150_mobile_modal_keeps_symmetric_gutters():
    css = read("assets/trophy-gallery/trophy-gallery-v2150-mobile.css")
    assert "@media (max-width: 560px)" in css
    assert "width: min(calc(100vw - 24px), 100%) !important;" in css
    assert "max-width: calc(100vw - 24px) !important;" in css
    assert "margin-left: auto !important;" in css
    assert "margin-right: auto !important;" in css
