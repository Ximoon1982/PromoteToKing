#!/usr/bin/env python3
"""Mobile Prize Administration gate for the v2.12 Trophy engraver corrective."""

from __future__ import annotations

from functools import partial
from http.server import ThreadingHTTPServer
import hashlib
import importlib.util
from pathlib import Path
from threading import Thread

from playwright.sync_api import sync_playwright

BASE_GATE_PATH = Path(__file__).with_name("browser_gate_trophy_gallery_overlay.py")
spec = importlib.util.spec_from_file_location("p2k_trophy_overlay_gate", BASE_GATE_PATH)
if spec is None or spec.loader is None:
    raise RuntimeError("Unable to load Trophy overlay browser gate")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

FIX_KEY = "p2k-2.12.0-trophyfix-8d879f14341e"
FIX_HASH = "8d879f14341e"
MASTER_NAMES = (
    "medal_gold.png", "medal_silver.png", "medal_bronze.png",
    "cup_gold.png", "cup_silver.png", "cup_bronze.png",
    "crystal_gold.png", "crystal_silver.png", "crystal_bronze.png",
)


def assert_release_identity(tree: Path) -> None:
    ui = (tree / "ui-v2.html").read_text(encoding="utf-8")
    editor = (tree / "assets/trophy-gallery/engraving/editor.html").read_text(encoding="utf-8")
    fix = tree / "assets/js/admin/trophy-gallery-v2120-mobile-fix.js"

    assert FIX_KEY in ui
    assert hashlib.sha256(fix.read_bytes()).hexdigest().startswith(FIX_HASH)
    assert "data:image" not in editor
    for name in MASTER_NAMES:
        assert f"./{name}" in editor
        assert (tree / "assets/trophy-gallery/engraving" / name).is_file()


def main() -> None:
    chromium = Path(base.CHROMIUM)
    if not chromium.exists():
        raise RuntimeError("Chromium is required for the mobile Trophy engraver browser gate")

    with base.production_tree() as tree:
        assert_release_identity(tree)
        base.FixtureHandler.authenticated = True
        base.FixtureHandler.revision = 1
        base.FixtureHandler.record = {
            "id": "installed-trophy", "status": "published", "league": "OWL",
            "competition": "Cup", "award": "Winner", "title": "Installed Trophy",
            "award_date": "2026-09-09", "description_md": "Persistent",
            "award_page": "", "competition_page": "", "result_table_url": "",
            "vignette_media_id": "", "modal_media_id": "", "matches": [],
        }
        base.FixtureHandler.meta = {}
        base.FixtureHandler.upload_count = 0

        server = ThreadingHTTPServer(("127.0.0.1", 0), partial(base.FixtureHandler, directory=str(tree)))
        thread = Thread(target=server.serve_forever, daemon=True)
        thread.start()
        origin = f"http://127.0.0.1:{server.server_port}"
        errors: list[str] = []
        master_requests: list[str] = []
        try:
            with sync_playwright() as playwright:
                browser = playwright.chromium.launch(
                    headless=True,
                    executable_path=str(chromium),
                    args=["--no-sandbox", "--disable-dev-shm-usage"],
                )
                context = browser.new_context(bypass_csp=True, viewport={"width": 390, "height": 844})
                page = context.new_page()
                page.on("pageerror", lambda error: errors.append(error.stack or str(error)))
                page.on(
                    "request",
                    lambda request: master_requests.append(request.url.rsplit("/", 1)[-1].split("?", 1)[0])
                    if "/assets/trophy-gallery/engraving/" in request.url
                    and request.url.split("?", 1)[0].endswith(".png")
                    else None,
                )

                page.goto(
                    f"{origin}/ui-v2.html?ui=v2&page=administration&adminCategory=team&trophy=1",
                    wait_until="domcontentloaded",
                )
                page.wait_for_function("window.__P2K_TROPHY_V2120_MOBILE_FIX === true", timeout=15000)
                host = "#adminShellNativeDetailHost"
                page.wait_for_selector(
                    f"{host}[data-native-detail='trophy-gallery']:not([hidden]) form[data-r538-enhanced='1']",
                    timeout=15000,
                )
                assert page.viewport_size == {"width": 390, "height": 844}
                assert page.locator(f'script[src*="trophy-gallery-v2120-mobile-fix.js?v={FIX_KEY}"]').count() == 1

                for slot in ("vignette", "modal"):
                    page.click(f"{host} [data-engrave='{slot}']")
                    modal = page.locator(f"{host} .p2k-engraver-modal:not([hidden])")
                    modal.wait_for(timeout=15000)
                    visible_frame = modal.locator("iframe.p2k-engraver:not([hidden])")
                    visible_frame.wait_for(timeout=15000)
                    modal_box = modal.bounding_box()
                    frame_box = visible_frame.bounding_box()
                    assert modal_box and modal_box["width"] > 0 and modal_box["height"] > 0
                    assert frame_box and frame_box["width"] > 0 and frame_box["height"] > 0
                    assert frame_box["width"] <= 392
                    frame = page.frame_locator(f"{host} .p2k-engraver-modal:not([hidden]) iframe.p2k-engraver")
                    frame.locator("#p2kR538UsePrize").wait_for(timeout=15000)
                    assert frame.locator("#middleText").input_value() == "Promote to King"
                    page.click(f"{host} .p2k-engraver-modal:not([hidden]) [data-engraver-close]")
                    page.wait_for_function(
                        "!document.querySelector('#adminShellNativeDetailHost .p2k-engraver-modal:not([hidden])')",
                        timeout=5000,
                    )

                assert set(master_requests).issubset(set(MASTER_NAMES)), master_requests
                assert master_requests, "Engraver did not request its external master image assets"

                page.goto(f"{origin}/ui-v2.html?ui=v2&page=hall&hall=trophies", wait_until="domcontentloaded")
                page.wait_for_selector(".p2k-trophy-group [data-r538-title]", timeout=15000)
                title_border = page.locator(".p2k-trophy-group [data-r538-title]").first.evaluate(
                    "el => getComputedStyle(el).borderBottomWidth"
                )
                group_border = page.locator(".p2k-trophy-group > h3").first.evaluate(
                    "el => getComputedStyle(el).borderBottomWidth"
                )
                assert title_border == "0px", title_border
                assert group_border != "0px", group_border
                assert not errors, errors

                context.close()
                browser.close()
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=5)


if __name__ == "__main__":
    main()
