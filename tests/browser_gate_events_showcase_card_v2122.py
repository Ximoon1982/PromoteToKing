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
        fake_now_ms = 2_000_000_000_000
        fake_now_s = fake_now_ms // 1000
        state = {
            "ok": True,
            "schemaVersion": 4,
            "revision": 9,
            "arenas": [
                {
                    "active": True,
                    "name": "Ongoing Blitz",
                    "link": "https://example.test/arena/ongoing",
                    "startAt": "2033-05-18T03:33:10Z",
                    "durationMinutes": 2,
                    "duration": "2m",
                    "timeControl": "3+2",
                },
                {
                    "active": True,
                    "name": "Registration Bullet",
                    "link": "https://example.test/arena/registration",
                    "startAt": "2033-05-18T04:03:20Z",
                    "durationMinutes": 60,
                    "duration": "1h",
                    "timeControl": "1+0",
                },
                {
                    "active": True,
                    "name": "Upcoming Rapid",
                    "link": "https://example.test/arena/upcoming",
                    "startAt": "2033-05-18T05:33:20Z",
                    "durationMinutes": 60,
                    "duration": "1h",
                    "timeControl": "15+10",
                },
            ],
            "items": [
                {"matchId": str(i), "enabled": True, "urgent": i == 5001}
                for i in range(5001, 5007)
            ],
            "catalog": [],
        }
        starts = {
            5001: fake_now_s + 24 * 3600,
            5002: fake_now_s + 72 * 3600,
            5003: fake_now_s + 96 * 3600,
            5004: fake_now_s + 120 * 3600,
            5005: fake_now_s + 144 * 3600,
            5006: fake_now_s + 30 * 3600,
        }
        details = {}
        for i in range(5001, 5007):
            league = i < 5006
            name = f"PCL Fixture {i}" if league else f"Friendly Fixture {i}"
            state["catalog"].append({
                "matchId": str(i),
                "name": name,
                "url": f"https://www.chess.com/club/matches/promote-to-king/{i}",
                "apiUrl": f"https://api.chess.com/pub/match/{i}",
                "startTime": starts[i],
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
                "start_time": starts[i],
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
            page.add_init_script(
                f"window.__P2K_TEST_NOW={fake_now_ms}; Date.now=()=>window.__P2K_TEST_NOW; "
                f"window.__CARD_FIXTURE={fixture}; window.__CLUB_INDEX_CALLS=0;"
            )
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

            base_url = f"http://127.0.0.1:{port}/server/events-showcase/public/embed-card.php?theme=dark"
            page.goto(base_url, wait_until="domcontentloaded")
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

            # v18 r4 arena states, colors/classes, icon accessibility and live text.
            ongoing = page.locator('[href="https://example.test/arena/ongoing"]')
            registration = page.locator('[href="https://example.test/arena/registration"]')
            assert ongoing.locator(".pc-badge").inner_text() == "On-going"
            assert "ongoing" in (ongoing.locator(".pc-badge").get_attribute("class") or "")
            assert "ongoing" in (ongoing.locator("[data-arena-live]").get_attribute("class") or "")
            assert registration.locator(".pc-badge").inner_text() == "Registration"
            assert "registration" in (registration.locator(".pc-badge").get_attribute("class") or "")
            assert registration.locator("[data-arena-live]").inner_text().startswith("Starts in ")
            assert ongoing.locator('svg[role="img"][aria-label="Blitz"]').count() == 1
            assert registration.locator('svg[role="img"][aria-label="Bullet"]').count() == 1

            before = ongoing.locator("[data-arena-live]").inner_text()
            page.evaluate("window.__P2K_TEST_NOW += 1000")
            page.wait_for_timeout(1100)
            after = ongoing.locator("[data-arena-live]").inner_text()
            assert before != after, "ongoing arena countdown did not tick"

            # Cross registration boundary; the 1-second clock must re-render phase automatically.
            registration_start = registration.evaluate("e=>Number(e.dataset.arenaStart)")
            page.evaluate("value=>window.__P2K_TEST_NOW=value", registration_start + 1000)
            page.wait_for_function(
                "document.querySelector('[href=\"https://example.test/arena/registration\"] .pc-badge')?.textContent === 'On-going'",
                timeout=2500,
            )
            registration = page.locator('[href="https://example.test/arena/registration"]')
            assert "ongoing" in (registration.locator("[data-arena-live]").get_attribute("class") or "")

            # Cross expiry boundary; expired arena disappears and the next Upcoming arena becomes visible.
            old_end = ongoing.evaluate("e=>Number(e.dataset.arenaEnd)")
            page.evaluate("value=>window.__P2K_TEST_NOW=value", old_end + 1000)
            page.wait_for_function("!document.querySelector('[href=\"https://example.test/arena/ongoing\"]')", timeout=2500)
            upcoming = page.locator('[href="https://example.test/arena/upcoming"]')
            assert upcoming.count() == 1
            assert upcoming.locator(".pc-badge").inner_text() == "Upcoming"
            assert "upcoming" in (upcoming.locator("[data-arena-live]").get_attribute("class") or "")
            assert upcoming.locator('svg[role="img"][aria-label="Rapid"]').count() == 1

            # v18 r4 special <=48h League treatment.
            first = page.locator("[data-daily] .pc-card").nth(0)
            second = page.locator("[data-daily] .pc-card").nth(1)
            assert "pc-league-48h" in (first.get_attribute("class") or "")
            assert "pc-league-48h" not in (second.get_attribute("class") or "")
            assert "pc-red" in (first.locator(".pc-primary > span").nth(0).get_attribute("class") or "")
            logo = first.locator("img.pc-club-logo")
            assert logo.get_attribute("loading") == "lazy"
            assert logo.get_attribute("referrerpolicy") == "no-referrer"
            assert (logo.get_attribute("alt") or "").endswith(" club logo")

            # Tab state is both visual and accessible, before and after redraw/filter change.
            assert page.locator('[data-filter="league"]').get_attribute("aria-selected") == "true"
            assert page.locator('[data-filter="friendly"]').get_attribute("aria-selected") == "false"
            page.click('[data-filter="friendly"]')
            page.wait_for_function("document.querySelectorAll('[data-daily] .pc-card').length === 1")
            assert "Friendly" in page.locator("[data-daily] .pc-badge").inner_text()
            assert page.locator('[data-filter="league"]').get_attribute("aria-selected") == "false"
            assert page.locator('[data-filter="friendly"]').get_attribute("aria-selected") == "true"

            # Hover feedback restored from v18 r4.
            card = page.locator("[data-daily] .pc-card").first
            card.hover()
            page.wait_for_timeout(180)
            assert card.evaluate("e=>getComputedStyle(e).transform") != "none"

            # POC section contract remains available.
            page.goto(base_url + "&section=daily", wait_until="domcontentloaded")
            assert page.locator("section.pc-section").nth(0).get_attribute("hidden") is not None
            assert page.locator("section.pc-section").nth(1).get_attribute("hidden") is None
            page.goto(base_url + "&section=arenas", wait_until="domcontentloaded")
            assert page.locator("section.pc-section").nth(0).get_attribute("hidden") is None
            assert page.locator("section.pc-section").nth(1).get_attribute("hidden") is not None

            browser.close()
    finally:
        server.terminate()
        try:
            server.wait(timeout=3)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait(timeout=3)

    print("Events Showcase v18 r4 DB-first card parity browser gate passed.")


if __name__ == "__main__":
    main()
