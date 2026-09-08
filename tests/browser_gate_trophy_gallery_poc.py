#!/usr/bin/env python3
"""Exercise Trophy Gallery POC r4 in the actual v2.11.4 Hall/Admin shell DOM."""

from __future__ import annotations

import json
import os
from pathlib import Path
import re
import shutil

from playwright.sync_api import sync_playwright


ROOT = Path(__file__).resolve().parents[1]
CHROMIUM = os.environ.get("P2K_CHROMIUM") or shutil.which("chromium") or "/usr/bin/chromium"


def clean_ui() -> str:
    html = (ROOT / "ui-v2.html").read_text(encoding="utf-8", errors="ignore")
    html = re.sub(r"<script\b[^>]*>.*?</script>", "", html, flags=re.I | re.S)
    return re.sub(r'<meta[^>]+http-equiv=["\']Content-Security-Policy["\'][^>]*>', "", html, flags=re.I)


BOOTSTRAP = r"""
document.getElementById('dashboardAdministrationTab').hidden=false;
document.getElementById('administrationPage').hidden=false;
document.getElementById('hallOfFamePage').hidden=true;
const host=document.getElementById('adminDashboardHost');host.hidden=false;
const state={admin:true,category:'competitions',adminDetail:'',adminDetailTab:'',adminToolTab:''};
const byId=id=>document.getElementById(id);
const shell=window.P2K_DASHBOARD_MODULES.adminShell.create({
 state,byId,escapeHTML:value=>String(value??''),number:value=>String(value??0),setText:()=>{},
 applyOAuthContext:()=>{},setIntegratedFrameActivity:()=>{},ensureIntegratedFrame:()=>{},
 writeNavigationState:()=>{},tools:[]
});
host.innerHTML=shell.adminPanelMarkup();
function activateCategory(category){
 state.category=category;
 host.querySelectorAll('[data-admin-shell-panel]').forEach(panel=>panel.hidden=panel.dataset.adminShellPanel!==category);
 host.querySelectorAll('[data-admin-category]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.adminCategory===category)));
 window.dispatchEvent(new CustomEvent('p2k-admin-shell-route',{detail:{category}}));
}
host.querySelectorAll('[data-admin-category]').forEach(button=>button.addEventListener('click',()=>activateCategory(button.dataset.adminCategory)));
document.getElementById('dashboardAdministrationTab').addEventListener('click',()=>{
 document.getElementById('administrationPage').hidden=false;document.getElementById('hallOfFamePage').hidden=true;
});
document.querySelector('[data-public-page="hall"]').addEventListener('click',()=>{
 document.getElementById('administrationPage').hidden=true;document.getElementById('hallOfFamePage').hidden=false;
});
window.__activateCategory=activateCategory;
"""


def main() -> None:
    if not Path(CHROMIUM).exists():
        raise RuntimeError("Chromium is required for the Trophy Gallery POC browser gate")
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True, executable_path=CHROMIUM, args=["--no-sandbox", "--disable-dev-shm-usage"])
        page = browser.new_page()
        errors: list[str] = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.set_content(clean_ui(), wait_until="domcontentloaded")
        page.add_script_tag(path=str(ROOT / "assets/js/admin/admin-shell.js"))
        page.add_script_tag(content=BOOTSTRAP)
        page.add_script_tag(path=str(ROOT / "assets/js/admin/trophy-gallery-poc.js"))
        page.evaluate("window.P2K_TROPHY_GALLERY_POC.mount({})")

        page.click('[data-public-page="hall"]')
        page.click("#p2kTrophyHallTab")
        page.wait_for_selector("#p2kTrophyHallPanel:not([hidden]) .p2k-trophy-card")
        initial_cards = page.locator("#p2kTrophyHallPanel .p2k-trophy-card").count()
        page.fill("[data-trophy-q]", "Super Bingo")
        filtered_cards = page.locator("#p2kTrophyHallPanel .p2k-trophy-card").count()
        page.click("#p2kTrophyHallPanel [data-trophy-open]")
        page.wait_for_selector("#p2kTrophyModal:not([hidden])")

        page.click("#dashboardAdministrationTab")
        page.evaluate("window.__activateCategory('misc')")
        page.wait_for_selector("[data-trophy-admin-card]")
        page.click("[data-trophy-admin-card] button")
        page.wait_for_function("document.querySelector(\"[data-admin-category='team']\")?.getAttribute('aria-pressed') === 'true'")
        page.wait_for_selector("[data-admin-shell-panel='team'] #p2kTrophyAdminPanel:not([hidden])")

        page.click("[data-trophy-add]")
        page.fill('[data-trophy-form] input[name="title"]', "Browser-created r4 trophy")
        page.fill('[data-trophy-form] input[name="league"]', "Browser League")
        page.select_option('[data-trophy-form] select[name="status"]', "published")
        page.click('[data-trophy-form] button[type="submit"]')
        stored = page.evaluate("JSON.parse(localStorage.getItem('p2k-trophy-gallery-poc-v1')).some(row => row.title === 'Browser-created r4 trophy' && row.status === 'published')")

        page.evaluate("""() => {
          const host=document.getElementById('adminDashboardHost');
          const panel=host.querySelector("[data-admin-shell-panel='team']");
          panel.replaceChildren();
          window.dispatchEvent(new CustomEvent('p2k-admin-shell-route',{detail:{category:'team'}}));
        }""")
        page.wait_for_selector("[data-admin-shell-panel='team'] #p2kTrophyAdminPanel")
        remounted = page.locator("[data-admin-shell-panel='team'] #p2kTrophyAdminPanel").count()

        page.click('[data-public-page="hall"]')
        page.click("#p2kTrophyHallTab")
        page.fill("[data-trophy-q]", "Browser-created r4 trophy")
        published_visible = page.locator("#p2kTrophyHallPanel .p2k-trophy-card").count()
        result = {"initial_cards": initial_cards, "filtered_cards": filtered_cards, "stored": stored, "remounted": remounted, "published_visible": published_visible, "page_errors": errors}
        browser.close()

    assert initial_cards >= 1 and filtered_cards == 1, result
    assert stored is True and remounted == 1 and published_visible == 1, result
    assert errors == [], result
    print(json.dumps({"trophy_gallery_poc_runtime": "passed", **result}, indent=2))


if __name__ == "__main__":
    main()
