#!/usr/bin/env python3
import json
import socket
import subprocess
import tempfile
import time
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]


def free_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def wait_server(port):
    deadline = time.monotonic() + 5
    while time.monotonic() < deadline:
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=.2):
                return
        except OSError:
            time.sleep(.05)
    raise RuntimeError("PHP fixture server did not start")


def main():
    port = free_port()
    fixture_html = '''<!doctype html><html><head><meta charset="utf-8"></head><body>
<section data-admin-shell-panel="competitions"><div class="dashboard-admin-shell-grid"><article data-admin-shell-card="daily"></article></div></section>
<section id="adminShellDetail" hidden><h1 id="adminShellDetailTitle"></h1><span id="adminShellDetailBreadcrumb"></span><div id="adminShellDetailTabs"></div><div id="adminShellDetailFrameWrap"><iframe id="adminShellDetailFrame"></iframe></div><div id="adminShellNativeDetailHost" hidden></div></section>
<script src="/assets/js/admin/events-showcase-v2122.js"></script>
</body></html>'''
    handle = tempfile.NamedTemporaryFile("w", suffix=".html", prefix=".events-showcase-admin-", dir=ROOT, delete=False)
    fixture_path = Path(handle.name)
    try:
        handle.write(fixture_html)
        handle.close()
        server = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{port}", "-t", str(ROOT)],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        try:
            wait_server(port)
            state = {
                "ok": True,
                "schemaVersion": 4,
                "revision": 3,
                "arenas": [],
                "items": [{"matchId": "12345", "enabled": True, "urgent": False}],
            }
            catalog = {
                "ok": True,
                "source": "p2k-core",
                "loadedAt": 2_000_000_000_000,
                "matches": [
                    {
                        "matchId": "12345",
                        "name": "PCL Configured Match",
                        "url": "https://www.chess.com/club/matches/promote-to-king/12345",
                        "apiUrl": "https://api.chess.com/pub/match/12345",
                        "category": "league",
                        "isLeague": True,
                        "leagueAcronyms": ["PCL"],
                        "startTime": 2_000_003_600,
                        "timeControl": 86400,
                        "opponentName": "Configured Opponent",
                        "opponentSlug": "configured-opponent",
                        "joinable": True,
                    },
                    {
                        "matchId": "67890",
                        "name": "Friendly Available Match",
                        "url": "https://www.chess.com/club/matches/promote-to-king/67890",
                        "apiUrl": "https://api.chess.com/pub/match/67890",
                        "category": "friendly",
                        "isLeague": False,
                        "leagueAcronyms": [],
                        "startTime": 2_000_007_200,
                        "timeControl": 86400,
                        "opponentName": "Available Opponent",
                        "opponentSlug": "available-opponent",
                        "joinable": True,
                    },
                ],
            }
            with sync_playwright() as p:
                browser = p.chromium.launch(headless=True)
                page = browser.new_page(viewport={"width": 1280, "height": 900})
                page.route("**/server/events-showcase/public/api.php?action=state", lambda route: route.fulfill(
                    status=200, content_type="application/json", body=json.dumps(state)
                ))
                page.route("**/server/events-showcase/public/api.php?action=catalog*", lambda route: route.fulfill(
                    status=200, content_type="application/json", body=json.dumps(catalog)
                ))
                page.route("**/server/events-showcase/public/embed.php*", lambda route: route.fulfill(
                    status=200, content_type="text/html", body="<!doctype html><title>line preview</title>"
                ))
                page.route("**/server/events-showcase/public/embed-card.php*", lambda route: route.fulfill(
                    status=200, content_type="text/html", body="<!doctype html><title>card preview</title>"
                ))
                page.goto(f"http://127.0.0.1:{port}/{fixture_path.name}", wait_until="domcontentloaded")
                page.wait_for_selector("[data-es-open]")

                assert page.evaluate("typeof window.P2K_TEAM_POINTS_CLIENT") == "undefined"
                page.click("[data-es-open]")
                page.wait_for_function("document.querySelector('[data-es-selected-count]')?.textContent === '1 configured'")
                page.wait_for_function("document.querySelectorAll('[data-es-search-results] .es-search-row').length === 1")
                assert page.locator("[data-es-selected]").inner_text().find("Configured Opponent") >= 0
                assert page.locator("[data-es-search-results]").inner_text().find("Available Opponent") >= 0
                assert "Loaded 2 current registered matches." in page.locator("[data-es-feedback]").inner_text()

                page.evaluate("""
                    window.__ES_CONNECTS=0; window.__ES_WRITES=0;
                    window.P2K_TEAM_POINTS_CLIENT={
                      connect: async()=>{ window.__ES_CONNECTS++; },
                      endpointRequest: async(_url,options)=>{
                        window.__ES_WRITES++;
                        return {ok:true,revision:4,items:options.body.items,arenas:options.body.arenas};
                      }
                    };
                """)
                page.click('[data-es-add="67890"]')
                page.wait_for_function("window.__ES_WRITES === 1 && window.__ES_CONNECTS === 1")
                page.wait_for_function("document.querySelector('[data-es-selected-count]')?.textContent === '2 configured'")
                assert page.locator("[data-es-selected]").inner_text().find("Available Opponent") >= 0
                browser.close()
        finally:
            server.terminate()
            try:
                server.wait(timeout=3)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=3)
    finally:
        fixture_path.unlink(missing_ok=True)

    print("Events Showcase admin read/write lifecycle browser gate passed.")


if __name__ == "__main__":
    main()
