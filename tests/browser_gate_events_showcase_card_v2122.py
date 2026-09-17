#!/usr/bin/env python3
import json
import socket
import subprocess
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
    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", str(ROOT)],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    try:
        wait_server(port)
        future = "2099-01-02T18:00:00+01:00"
        state = {
            "ok": True,
            "schemaVersion": 4,
            "revision": 9,
            "arenas": [
                {"active": True, "name": f"Arena {i}", "link": f"https://www.chess.com/play/arena/a{i}", "startAt": future, "durationMinutes": 120, "duration": "2h", "timeControl": "3+2"}
                for i in range(1, 4)
            ],
            "items": [
                {"matchId": str(i), "enabled": True, "urgent": i == 5001}
                for i in range(5001, 5007)
            ],
            "catalog": [],
        }
        details = {}
        start_epoch = 4070970000
        for i in range(5001, 5007):
            league = i < 5006
            name = f"PCL Fixture {i}" if league else f"Friendly Fixture {i}"
            state["catalog"].append({
                "matchId": str(i),
                "name": name,
                "url": f"https://www.chess.com/club/matches/promote-to-king/{i}",
                "apiUrl": f"https://api.chess.com/pub/match/{i}",
                "startTime": start_epoch + (i - 5001) * 3600,
                "timeControl": 86400,
                "maxRating": 1600,
                "category": "league" if league else "friendly",
                "isLeague": league,
                "leagueAcronyms": ["PCL"] if league else [],
                "opponentName": f"Opponent {i}",
                "opponentSlug": f"opponent-{i}",
                "opponentLogo": "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==",
            })
            details[str(i)] = {
                "id": i,
                "name": name,
                "start_time": start_epoch + (i - 5001) * 3600,
                "settings": {
                    "rules": "chess",
                    "time_control": 86400,
                    "min_rating": 1200,
                    "max_rating": 1600,
                    "min_team_players": 2,
                    "max_team_players": 8,
                },
                "teams": {
                    "p2k": {
                        "name": "Promote to King",
                        "@id": "https://api.chess.com/pub/club/promote-to-king",
                        "players": [{"username": "P2KOne", "rating": 1450}],
                    },
                    "opp": {
                        "name": f"Opponent {i}",
                        "players": [{"username": "OppOne", "rating": 1425}, {"username": "OppTwo", "rating": 1400}],
                    },
                },
            }

        fixture = json.dumps({"details": details})
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            page = browser.new_page(viewport={"width": 320, "height": 480})
            page.add_init_script(f"window.__CARD_FIXTURE={fixture}; window.__CLUB_INDEX_CALLS=0;")
            page.route("**/config/site-branding.js*", lambda route: route.fulfill(status=200, content_type="application/javascript", body=""))
            page.route("**/assets/js/site-config.js*", lambda route: route.fulfill(
                status=200,
                content_type="application/javascript",
                body='window.P2K_SITE_CONFIG={clubSlug:"promote-to-king",leagueAcronyms:["PCL"],api:{defaultAttempts:1}};',
            ))
            page.route("**/assets/js/shared/api-client.js*", lambda route: route.fulfill(
                status=200,
                content_type="application/javascript",
                body=r'''
                  window.P2K_API_CLIENT={json:async url=>{
                    const f=window.__CARD_FIXTURE;
                    if(String(url).includes('/pub/club/promote-to-king/matches')) { window.__CLUB_INDEX_CALLS++; throw new Error('DB-first card must not request club match index'); }
                    const m=String(url).match(/\/pub\/match\/(\d+)/);
                    if(m && f.details[m[1]]) return f.details[m[1]];
                    throw new Error('Unexpected fixture API request: '+url);
                  }};
                ''',
            ))
            page.route("**/server/events-showcase/public/api.php?action=state", lambda route: route.fulfill(
                status=200,
                content_type="application/json",
                body=json.dumps(state),
            ))

            page.goto(
                f"http://127.0.0.1:{port}/server/events-showcase/public/embed-card.php?theme=dark",
                wait_until="domcontentloaded",
            )
            page.wait_for_function("document.querySelectorAll('[data-daily] .pc-card').length === 4")
            assert page.evaluate("window.__CLUB_INDEX_CALLS") == 0
            assert page.locator(".pc-title").nth(0).inner_text() == "⚔️ Arenas ⚔️"
            assert page.locator(".pc-title").nth(1).inner_text() == "⚔️ Daily Matches ⚔️"
            assert page.locator("[data-arenas] .pc-card").count() == 2
            assert page.locator("[data-daily] .pc-card").count() == 4
            width = page.locator(".pc-page").evaluate("e=>e.getBoundingClientRect().width")
            assert 259 <= width <= 261
            columns = page.locator("[data-daily]").evaluate("e=>getComputedStyle(e).gridTemplateColumns.split(' ').filter(Boolean).length")
            assert columns == 2
            height = page.evaluate("document.documentElement.scrollHeight")
            assert height <= 480, f"card embed exceeds requested 480px iframe height: {height}px"

            page.click('[data-filter="friendly"]')
            page.wait_for_function("document.querySelectorAll('[data-daily] .pc-card').length === 1")
            assert "Friendly" in page.locator("[data-daily] .pc-badge").inner_text()

            browser.close()
    finally:
        server.terminate()
        try:
            server.wait(timeout=3)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait(timeout=3)

    print("Events Showcase v18 r4 DB-first card browser gate passed.")


if __name__ == "__main__":
    main()
