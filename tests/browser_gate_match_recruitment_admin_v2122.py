#!/usr/bin/env python3
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "assets/js/admin/match-recruitment-access-v2121.js"


def main():
    html = """<!doctype html><html><head><base href="https://p2k.test/"></head><body>
    <section data-admin-shell-panel="competitions"><div class="dashboard-admin-shell-grid"><article data-admin-shell-card="daily"></article></div></section>
    <section data-admin-shell-panel="members" hidden><div class="dashboard-admin-shell-grid"></div></section>
    <section id="adminShellDetail" hidden>
      <button id="adminShellDetailBack" type="button">Back</button>
      <span id="adminShellDetailBreadcrumb"></span><h2 id="adminShellDetailTitle"></h2>
      <nav id="adminShellDetailTabs"></nav>
      <div id="adminShellDetailFrameWrap"><iframe id="adminShellDetailFrame"></iframe></div>
      <div id="adminShellNativeDetailHost" hidden></div>
    </section>
    </body></html>"""
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()
        page.route("https://p2k.test/RecruitMatch.html*", lambda route: route.fulfill(status=200, content_type="text/html", body="<!doctype html><html><body>Recruitment fixture</body></html>"))
        page.set_content(html)
        page.evaluate("window.P2K_ADMIN_MODE=true")
        page.add_script_tag(path=str(SCRIPT))
        page.wait_for_selector("[data-v2121-match-recruitment]")

        page.click("[data-v2121-match-recruitment-open]")
        assert page.locator("#adminShellDetail").is_visible()
        assert page.locator("#adminShellDetail").get_attribute("data-p2k-custom-detail") == "match-recruitment"
        assert page.locator("#adminShellDetailTitle").inner_text() == "Match Recruitment"
        assert page.locator("#adminShellDetailBreadcrumb").inner_text() == "Administration · Competitions"
        assert page.locator("[data-admin-shell-panel=competitions]").is_hidden()
        assert page.locator("#adminShellDetailTabs").is_hidden()
        assert page.locator("#adminShellNativeDetailHost").is_hidden()
        assert page.locator("#adminShellDetailFrameWrap").is_visible()
        src = page.locator("#adminShellDetailFrame").get_attribute("src")
        assert "RecruitMatch.html" in src and "embedded=1" in src and "active=1" in src

        page.click("#adminShellDetailBack")
        assert page.locator("#adminShellDetail").is_hidden()
        assert page.locator("[data-admin-shell-panel=competitions]").is_visible()
        assert page.locator("[data-admin-shell-panel=members]").is_hidden()

        browser.close()
    print("Match Recruitment native admin detail browser gate passed.")


if __name__ == "__main__":
    main()
