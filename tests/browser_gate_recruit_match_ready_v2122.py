#!/usr/bin/env python3
import os
import threading
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]


class Quiet(SimpleHTTPRequestHandler):
    def log_message(self, *_):
        pass


def neutralize_support_scripts(page):
    page.route("**/config/site-branding.js*", lambda route: route.fulfill(status=200, content_type="application/javascript", body=""))
    page.route("**/assets/js/site-config.js*", lambda route: route.fulfill(status=200, content_type="application/javascript", body=""))
    page.route("**/assets/js/shared/**", lambda route: route.fulfill(status=200, content_type="application/javascript", body=""))


def main():
    os.chdir(ROOT)
    server = ThreadingHTTPServer(("127.0.0.1", 0), Quiet)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    base = f"http://127.0.0.1:{server.server_port}/RecruitMatch.html"
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)

            # Regression for the original race: the controller script load event
            # fires while its async IIFE is still waiting for admin readiness.
            page = browser.new_page()
            page.add_init_script("""
              window.__p2kAdminDelayStarted = performance.now();
              window.P2K_ADMIN_ACCESS_READY = new Promise(resolve => setTimeout(() => {
                window.__p2kAdminDelayResolved = performance.now();
                resolve(true);
              }, 300));
              window.P2K_RECRUITMENT_V2 = {};
              window.P2K_SITE_CONFIG = {clubSlug: 'promote-to-king'};
            """)
            neutralize_support_scripts(page)
            page.goto(base, wait_until="domcontentloaded")
            page.wait_for_function("document.documentElement.dataset.p2kRecruitmentReady === '1'", timeout=4000)
            assert page.evaluate("typeof document.getElementById('p2kMatchForm').onsubmit") == "function"
            assert page.evaluate("performance.now() - window.__p2kAdminDelayStarted") >= 250
            assert "did not bind" not in page.locator("#p2kStatusText").inner_text()
            page.close()

            # A genuine initialization failure must reject the ready contract and
            # remain visible to the user instead of hanging indefinitely.
            denied = browser.new_page()
            denied.add_init_script("""
              window.P2K_ADMIN_ACCESS_READY = Promise.resolve(false);
              window.P2K_RECRUITMENT_V2 = {};
              window.P2K_SITE_CONFIG = {clubSlug: 'promote-to-king'};
            """)
            neutralize_support_scripts(denied)
            denied.goto(base, wait_until="domcontentloaded")
            denied.locator("#p2kStatusText", has_text="Administrator access was not granted.").wait_for(timeout=4000)
            assert denied.locator("#p2kLoadButton").is_disabled()
            assert denied.locator("#p2kScanButton").is_disabled()
            denied.close()

            browser.close()
    finally:
        server.shutdown()
    print("Match Recruitment controller readiness browser gate passed.")


if __name__ == "__main__":
    main()
