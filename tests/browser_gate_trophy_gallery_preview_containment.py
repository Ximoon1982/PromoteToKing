#!/usr/bin/env python3
"""Browser regression for Trophy Gallery admin image-preview containment."""
from __future__ import annotations

import json
import os
import shutil
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
CHROMIUM = os.environ.get("P2K_CHROMIUM") or shutil.which("chromium") or shutil.which("google-chrome") or shutil.which("google-chrome-stable") or "/usr/bin/chromium"
R5310_JS = "r5310-fd6d89209aa6"
R5310_PATH = ROOT / "assets/js/admin/trophy-gallery-r5fix3.10.js"


def main() -> None:
    if not Path(CHROMIUM).exists():
        raise RuntimeError("Chromium is required for the Trophy preview containment gate")

    errors: list[str] = []
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            headless=True,
            executable_path=CHROMIUM,
            args=["--no-sandbox", "--disable-dev-shm-usage"],
        )
        page = browser.new_page(viewport={"width": 1280, "height": 900})
        page.on("pageerror", lambda error: errors.append(error.stack or str(error)))

        page.set_content(
            """
            <!doctype html>
            <html><head><meta charset="utf-8"></head><body>
              <main id="adminShellNativeDetailHost" data-native-detail="trophy-gallery"></main>
            </body></html>
            """,
            wait_until="domcontentloaded",
        )
        page.add_script_tag(path=str(R5310_PATH))
        page.wait_for_function("window.__P2K_TROPHY_R5FIX3_10 === true", timeout=5000)

        page.evaluate(
            """() => {
              const host = document.querySelector('#adminShellNativeDetailHost');
              host.innerHTML = `
                <form class="p2k-trophy-form">
                  <section class="p2k-media-card">
                    <strong>Vignette image</strong>
                    <button type="button" class="p2k-media-preview" data-preview="vignette">
                      <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="">
                    </button>
                  </section>
                </form>`;
            }"""
        )

        host = "#adminShellNativeDetailHost"
        page.wait_for_function(
            """document.querySelector("#adminShellNativeDetailHost [data-preview='vignette']")?.dataset.r5310Contained === '1'""",
            timeout=5000,
        )

        page.evaluate(
            """() => {
                const style = document.createElement('style');
                style.id = 'preview-escape-regression';
                style.textContent = `
                  #adminShellNativeDetailHost .p2k-media-preview { height:100vh!important; overflow:visible!important; }
                  #adminShellNativeDetailHost .p2k-media-preview img { position:fixed!important; inset:0!important; width:100vw!important; height:100vh!important; max-width:none!important; max-height:none!important; }
                `;
                document.head.appendChild(style);
            }"""
        )

        page.wait_for_function(
            """() => {
              const p = document.querySelector("#adminShellNativeDetailHost [data-preview='vignette']");
              const i = p?.querySelector('img');
              return p && i
                && getComputedStyle(p).height === '280px'
                && getComputedStyle(p).overflow === 'hidden'
                && getComputedStyle(i).position === 'absolute'
                && getComputedStyle(i).objectFit === 'contain';
            }""",
            timeout=5000,
        )

        preview = page.locator(f"{host} [data-preview='vignette']")
        image = preview.locator("img")
        preview_box = preview.bounding_box()
        image_box = image.bounding_box()
        assert preview_box is not None and image_box is not None
        assert 279 <= preview_box["height"] <= 281, preview_box
        assert preview.evaluate("el => getComputedStyle(el).overflow") == "hidden"
        assert image.evaluate("el => getComputedStyle(el).position") == "absolute"
        assert image.evaluate("el => getComputedStyle(el).objectFit") == "contain"
        assert image_box["x"] >= preview_box["x"] - 1
        assert image_box["y"] >= preview_box["y"] - 1
        assert image_box["x"] + image_box["width"] <= preview_box["x"] + preview_box["width"] + 1
        assert image_box["y"] + image_box["height"] <= preview_box["y"] + preview_box["height"] + 1
        assert page.locator("#p2kTrophyR5Fix310Style").count() == 1
        assert not errors, errors
        browser.close()

    print(json.dumps({
        "trophy_gallery_admin_preview_containment": "passed",
        "r5fix3_10_js_key": R5310_JS,
        "height_px": preview_box["height"],
        "position": "absolute",
        "overflow": "hidden",
        "errors": errors,
    }, indent=2))


if __name__ == "__main__":
    main()
