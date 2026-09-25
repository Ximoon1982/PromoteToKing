#!/usr/bin/env python3
"""Focused v2.12.1 Trophy Hall/admin/engraver browser regression."""
from __future__ import annotations

from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from io import BytesIO
import json
import os
from pathlib import Path
import shutil
from threading import Thread
from urllib.parse import parse_qs, urlparse

from PIL import Image
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
CHROMIUM = os.environ.get("P2K_CHROMIUM") or shutil.which("chromium") or "/usr/bin/chromium"

PNG = BytesIO()
Image.new("RGB", (16, 16), (180, 100, 20)).save(PNG, format="PNG")
PNG_BYTES = PNG.getvalue()


def trophy(tid: str, league: str, date: str, title: str) -> dict:
    return {
        "id": tid, "status": "published", "league": league, "competition": f"{league} Cup",
        "award": "Winner", "title": title, "award_date": date,
        "description_md": "Qualified v2.12.1 trophy", "award_page": "",
        "competition_page": "", "result_table_url": "", "vignette_media_id": "",
        "modal_media_id": "", "vignette_url": "", "modal_url": "", "matches": [],
    }


class Fixture(SimpleHTTPRequestHandler):
    authenticated = True
    revision = 1
    records = {
        "t-2026": trophy("t-2026", "Beta League", "2026-08-01", "Beta 2026"),
        "t-2025": {**trophy("t-2025", "Alpha League", "2025-07-01", "Alpha 2025"), "vignette_media_id": "c" * 32},
        "t-2024": trophy("t-2024", "Alpha League", "2024-06-01", "Alpha 2024"),
    }
    meta: dict[str, dict] = {}
    actions: list[str] = []
    upload_count = 0

    def log_message(self, *_args: object) -> None:
        return

    def send_json(self, payload: dict, status: int = 200) -> None:
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    @classmethod
    def summaries(cls) -> list[dict]:
        base = [{k: r.get(k, "") for k in ("id", "status", "title", "league", "competition", "award_date")}
                for r in cls.records.values()]
        extras = [{"id": f"fixture-{n:02d}", "status": "draft" if n % 2 else "published",
                   "title": f"Fixture Trophy {n:02d}", "league": "Fixture League",
                   "competition": "Fixture Cup", "award_date": f"2023-{(n % 12) + 1:02d}-01"}
                  for n in range(1, 21)]
        return base + extras

    def fixture_get(self) -> bool:
        parsed = urlparse(self.path)
        query = parse_qs(parsed.query)
        cls = type(self)
        if parsed.path.endswith("/server/team-points/public/oauth.php"):
            self.send_json({
                "ok": True, "enabled": True, "authenticated": cls.authenticated,
                "csrf": "fixture-csrf", "admin_bootstrap": "fixture-bootstrap",
                "profile": {"username": "Ximoon", "admin": cls.authenticated,
                            "roles": ["admin"] if cls.authenticated else []},
            })
            return True
        if parsed.path.endswith("/server/team-points/public/session.php"):
            if cls.authenticated:
                self.send_json({"ok": True, "username": "ximoon", "csrf": "fixture-admin-csrf"})
            else:
                self.send_json({"ok": False, "error": {"code": "OAUTH_SESSION_REQUIRED", "message": "Authentication required"}}, 401)
            return True
        if parsed.path.endswith("/server/trophy-gallery/public/editor-meta.php"):
            action = query.get("action", ["get"])[0]
            tid = query.get("id", [""])[0]
            if action == "get":
                self.send_json({"ok": True, "id": tid, "meta": cls.meta.get(tid, {})})
            else:
                self.send_json({"ok": True, "version": 1, "records": cls.meta})
            return True
        if parsed.path.endswith("/server/trophy-gallery/public/api.php"):
            action = query.get("action", ["list"])[0]
            cls.actions.append(action)
            if action == "list":
                self.send_json({"ok": True, "revision": cls.revision,
                                "records": [r for r in cls.records.values() if r.get("status") == "published"]})
            elif action == "admin-index":
                self.send_json({"ok": True, "revision": cls.revision, "records": cls.summaries()})
            elif action == "get":
                tid = query.get("id", [""])[0]
                self.send_json({"ok": True, "revision": cls.revision, "record": cls.records[tid]})
            elif action == "match-search":
                self.send_json({"ok": True, "matches": [{"match_id": 987,
                    "match_name": "Promote to King vs Golden Phoenix", "opponent_name": "Golden Phoenix"}]})
            elif action == "admin-list":
                self.send_json({"ok": True, "revision": cls.revision, "records": list(cls.records.values())})
            else:
                self.send_json({"ok": True})
            return True
        if parsed.path.endswith("/server/trophy-gallery/public/media.php"):
            self.send_response(200)
            self.send_header("Content-Type", "image/png")
            self.send_header("Content-Length", str(len(PNG_BYTES)))
            self.end_headers()
            self.wfile.write(PNG_BYTES)
            return True
        if parsed.path.endswith(".php") or "/api/" in parsed.path:
            self.send_json({"ok": True, "rows": [], "items": [], "matches": [], "members": []})
            return True
        return False

    def do_GET(self) -> None:  # noqa: N802
        if self.fixture_get():
            return
        super().do_GET()

    def do_POST(self) -> None:  # noqa: N802
        parsed = urlparse(self.path)
        query = parse_qs(parsed.query)
        cls = type(self)
        raw = self.rfile.read(int(self.headers.get("Content-Length", "0")))
        if parsed.path.endswith("/server/trophy-gallery/public/editor-meta.php"):
            incoming = json.loads(raw or b"{}")
            tid = str(incoming.get("id") or "")
            cls.meta[tid] = {
                "modal_media_mode": incoming.get("modal_media_mode", "vignette"),
                "result_tables": incoming.get("result_tables") or [],
            }
            self.send_json({"ok": True, "id": tid, "meta": cls.meta[tid]})
            return
        if parsed.path.endswith("/server/trophy-gallery/public/api.php"):
            action = query.get("action", [""])[0]
            cls.actions.append(action)
            cls.revision += 1
            if action == "save":
                incoming = json.loads(raw or b"{}")
                incoming.pop("revision", None)
                tid = str(incoming.get("id") or "new-trophy")
                incoming["id"] = tid
                old = cls.records.get(tid, trophy(tid, incoming.get("league", ""), incoming.get("award_date", ""), incoming.get("title", tid)))
                cls.records[tid] = {**old, **incoming}
                payload = {"ok": True, "record": cls.records[tid], "revision": cls.revision}
            elif action == "upload":
                cls.upload_count += 1
                tid = "new-trophy" if b"new-trophy" in raw else "t-2026"
                slot = "modal" if b"\r\n\r\nmodal\r\n" in raw else "vignette"
                media_id = ("b" if slot == "modal" else "a") * 31 + str(cls.upload_count % 10)
                cls.records[tid][slot + "_media_id"] = media_id
                payload = {"ok": True, "media": {"id": media_id}, "record": cls.records[tid], "revision": cls.revision}
            else:
                payload = {"ok": True, "record": cls.records.get("t-2026"), "revision": cls.revision}
            self.send_json(payload)
            return
        if self.fixture_get():
            return
        self.send_error(404)


def main() -> None:
    if not Path(CHROMIUM).exists():
        raise RuntimeError("Chromium is required for the v2.12.1 Trophy browser gate")
    Fixture.authenticated = True
    Fixture.revision = 1
    Fixture.actions = []
    Fixture.upload_count = 0
    Fixture.meta = {}
    server = ThreadingHTTPServer(("127.0.0.1", 0), partial(Fixture, directory=str(ROOT)))
    thread = Thread(target=server.serve_forever, daemon=True)
    thread.start()
    origin = f"http://127.0.0.1:{server.server_port}"
    errors: list[str] = []
    master_requests: list[str] = []
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True, executable_path=CHROMIUM,
                                        args=["--no-sandbox", "--disable-dev-shm-usage"])
            context = browser.new_context(bypass_csp=True, viewport={"width": 1100, "height": 850})
            page = context.new_page()
            page.on("pageerror", lambda e: errors.append(e.stack or str(e)))
            page.on("request", lambda r: master_requests.append(r.url.rsplit("/", 1)[-1].split("?", 1)[0])
                    if "/assets/trophy-gallery/engraving/" in r.url and r.url.split("?", 1)[0].endswith(".png") else None)
            page.route("https://example.test/**", lambda route: route.fulfill(status=200, content_type="image/png", body=PNG_BYTES))
            page.goto(f"{origin}/ui-v2.html?ui=v2&page=administration&adminCategory=team&trophy=1",
                      wait_until="domcontentloaded")
            host = "#adminShellNativeDetailHost"
            page.wait_for_selector(f"{host}[data-native-detail='trophy-gallery']:not([hidden]) [data-v2121-form]", timeout=20000)
            page.wait_for_function("window.P2K_TROPHY_ADMIN_V2121 !== undefined")
            assert "admin-index" in Fixture.actions and "get" in Fixture.actions, Fixture.actions
            assert "admin-list" not in Fixture.actions, Fixture.actions
            assert page.locator(f"{host} input[name='award']").count() == 0
            assert page.locator(f"{host} input[name='award_page']").count() == 1
            assert page.locator(f"{host} [data-v2121-admin-root]").count() == 1
            assert page.locator(f"{host} [data-v2121-filter]").count() == 1
            assert page.locator(f"{host} .p2k-trophy-tools [data-v2121-filter]").count() == 1
            assert page.locator(f"{host} .p2k-trophy-admin-table").count() == 1
            expect(page.locator(f"{host} [data-v2121-row]")).to_have_count(10)
            assert page.locator(f"{host} [data-v2121-page-info]").inner_text() == "1–10 of 23 · Page 1 of 3"
            page.click(f"{host} [data-v2121-next]")
            expect(page.locator(f"{host} [data-v2121-row]")).to_have_count(10)
            assert page.locator(f"{host} [data-v2121-page-info]").inner_text() == "11–20 of 23 · Page 2 of 3"
            page.fill(f"{host} [data-v2121-filter]", "t-2025")
            expect(page.locator(f"{host} [data-v2121-select]")).to_have_count(1)
            assert page.locator(f"{host} [data-v2121-select]").first.get_attribute("data-v2121-select") == "t-2025"
            page.fill(f"{host} [data-v2121-filter]", "Beta League Cup")
            expect(page.locator(f"{host} [data-v2121-select]")).to_have_count(1)
            page.fill(f"{host} [data-v2121-filter]", "2024-06-01")
            expect(page.locator(f"{host} [data-v2121-select]")).to_have_count(1)
            page.fill(f"{host} [data-v2121-filter]", "")
            expect(page.locator(f"{host} [data-v2121-select]")).to_have_count(10)
            assert page.locator(f"{host} [data-v2121-page-info]").inner_text() == "1–10 of 23 · Page 1 of 3"

            page.fill(f"{host} [data-v2121-match-search]", "Golden")
            page.wait_for_selector(f"{host} [data-v2121-add-match='987']", timeout=10000)
            page.click(f"{host} [data-v2121-add-match='987']")
            page.wait_for_selector(f"{host} [data-v2121-unlink='987']", timeout=10000)
            assert any(int(m["match_id"]) == 987 for m in Fixture.records["t-2026"]["matches"])

            page.click(f"{host} [data-v2121-new]")
            page.wait_for_selector(f"{host} [data-v2121-form]")
            page.fill(f"{host} [name='title']", "New v2.12.1 Trophy")
            page.fill(f"{host} [name='league']", "Gamma League")
            page.fill(f"{host} [name='vignette_url']", "https://example.test/trophy.png")
            page.wait_for_selector(f"{host} [data-v2121-art='vignette'] .p2k-media-preview img", timeout=5000)
            page.wait_for_selector(f"{host} [data-v2121-art='modal'] .p2k-media-preview img", timeout=5000)
            assert page.locator(f"{host} .p2k-media-controls").count() == 2
            art_boxes = page.locator(f"{host} [data-v2121-art]")
            assert art_boxes.count() == 2
            vignette_box = art_boxes.nth(0).bounding_box()
            modal_box_admin = art_boxes.nth(1).bounding_box()
            assert vignette_box and modal_box_admin
            assert abs(vignette_box["y"] - modal_box_admin["y"]) <= 2, (vignette_box, modal_box_admin)
            assert modal_box_admin["x"] > vignette_box["x"], (vignette_box, modal_box_admin)
            assert page.locator(f"{host} [data-v2121-art='modal'] [data-v2121-modal-mode]").count() == 1
            assert page.locator(f"{host} [data-v2121-file='modal']").is_disabled()
            assert page.locator(f"{host} [name='modal_url']").is_disabled()
            assert page.locator(f"{host} [data-v2121-engrave='modal']").is_disabled()
            vignette_src = page.locator(f"{host} [data-v2121-art='vignette'] .p2k-media-preview img").get_attribute("src")
            modal_src = page.locator(f"{host} [data-v2121-art='modal'] .p2k-media-preview img").get_attribute("src")
            assert modal_src == vignette_src, (vignette_src, modal_src)
            page.click(f"{host} [data-v2121-preview]")
            page.wait_for_selector("#p2kTrophyAdminCombinedPreviewV2121:not([hidden])", timeout=5000)
            expect(page.locator("#p2kTrophyAdminCombinedPreviewV2121 .p2k-trophy-preview-output")).to_have_count(2)
            page.click("#p2kTrophyAdminCombinedPreviewV2121 [data-v2121-preview-close]")
            page.select_option(f"{host} [name='modal_media_mode']", "custom")
            assert not page.locator(f"{host} [data-v2121-file='modal']").is_disabled()
            assert not page.locator(f"{host} [name='modal_url']").is_disabled()
            assert not page.locator(f"{host} [data-v2121-engrave='modal']").is_disabled()
            preview_style = page.locator(f"{host} [data-v2121-art='vignette'] .p2k-media-preview").evaluate(
                "el => ({overflow:getComputedStyle(el).overflow, width:el.getBoundingClientRect().width, height:el.getBoundingClientRect().height})")
            assert preview_style["overflow"] == "hidden" and preview_style["width"] > 100 and preview_style["height"] > 100, preview_style
            assert page.locator(f"{host} [data-v2121-art='vignette'] .p2k-media-preview img").evaluate(
                "img => getComputedStyle(img).objectFit") == "contain"
            page.click(f"{host} [data-v2121-art='vignette'] .p2k-media-preview")
            page.wait_for_selector("#p2kTrophyAdminViewerV2121:not([hidden]) img", timeout=5000)
            page.click("#p2kTrophyAdminViewerV2121 [data-v2121-view-close]")

            # Engraving can be prepared before the first save. The file remains browser-local
            # until the canonical save creates an ID and uploads pending artwork.
            page.click(f"{host} [data-v2121-engrave='modal']")
            page.wait_for_selector("#p2kTrophyEngraverV2121:not([hidden]) iframe.p2k-engraver", timeout=15000)
            assert page.evaluate("document.body.classList.contains('p2k-engraver-open')")
            pre_save_frame = page.frame_locator("#p2kTrophyEngraverV2121 iframe.p2k-engraver")
            pre_save_frame.locator("body").evaluate("""() => {
                const raw=atob('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAGUlEQVR4nGPckiLCQApgIkn1qIZRDUNKAwDJRQFMFkEbaQAAAABJRU5ErkJggg==');
                const bytes=Uint8Array.from(raw, ch => ch.charCodeAt(0));
                parent.postMessage({
                    type:'p2k-trophy-engraving',
                    name:'pre-save-modal.png',
                    blob:new Blob([bytes],{type:'image/png'})
                }, location.origin);
            }""")
            page.wait_for_selector("#p2kTrophyEngraverV2121[hidden]", state="attached", timeout=10000)
            assert not page.evaluate("document.body.classList.contains('p2k-engraver-open')")
            page.wait_for_function("""() => {
                const img=document.querySelector('#adminShellNativeDetailHost [data-v2121-art="modal"] .p2k-media-preview img');
                return !!img && img.src.startsWith('blob:');
            }""")

            upload = Path(os.environ.get("RUNNER_TEMP", "/tmp")) / "p2k-v2121-upload.png"
            Image.new("RGB", (16, 16), (180, 100, 20)).save(upload)
            page.set_input_files(f"{host} [data-v2121-file='vignette']", str(upload))
            page.evaluate("window.__p2kV2121Form = document.querySelector('#adminShellNativeDetailHost [data-v2121-form]')")
            page.evaluate("window.scrollTo(0, document.documentElement.scrollHeight)")
            page.wait_for_timeout(50)
            before_scroll = page.evaluate("window.scrollY")
            before_upload = Fixture.upload_count
            page.locator(f"{host} [data-v2121-form] button[type='submit']").evaluate("button => button.click()")
            page.wait_for_function("document.querySelector('#adminShellNativeDetailHost [data-v2121-status]')?.textContent === 'Saved.'", timeout=15000)
            assert Fixture.upload_count == before_upload + 2
            assert Fixture.records["new-trophy"]["vignette_url"] == "https://example.test/trophy.png"
            assert page.evaluate("window.__p2kV2121Form === document.querySelector('#adminShellNativeDetailHost [data-v2121-form]')")
            after_scroll = page.evaluate("window.scrollY")
            assert abs(after_scroll - before_scroll) <= 2, (before_scroll, after_scroll)
            page.fill(f"{host} [data-v2121-filter]", "new-trophy")
            expect(page.locator(f"{host} [data-v2121-select]")).to_have_count(1)
            page.fill(f"{host} [data-v2121-filter]", "")

            replacement = Path(os.environ.get("RUNNER_TEMP", "/tmp")) / "p2k-v2121-replacement.png"
            Image.new("RGB", (18, 18), (20, 130, 190)).save(replacement)
            before_direct_upload = Fixture.upload_count
            page.set_input_files(f"{host} [data-v2121-file='vignette']", str(replacement))
            page.wait_for_function("document.querySelector('#adminShellNativeDetailHost [data-v2121-status]')?.textContent === 'Artwork updated.'", timeout=15000)
            assert Fixture.upload_count == before_direct_upload + 1
            page.wait_for_function("""() => {
                const img=document.querySelector('#adminShellNativeDetailHost [data-v2121-art="vignette"] .p2k-media-preview img');
                return !!img && img.src.startsWith('blob:');
            }""")

            page.click(f"{host} [data-v2121-engrave='vignette']")
            page.wait_for_selector("#p2kTrophyEngraverV2121:not([hidden]) iframe.p2k-engraver", timeout=15000)
            assert page.evaluate("document.body.classList.contains('p2k-engraver-open')")
            frame = page.frame_locator("#p2kTrophyEngraverV2121 iframe.p2k-engraver")
            frame.locator("#finish").wait_for(timeout=15000)
            page.wait_for_timeout(300)
            assert master_requests == ["medal_gold.png"], master_requests
            assert frame.locator("#sampleBtn").count() == 0
            assert frame.locator("#downloadBtn").inner_text() == "Use in gallery"
            assert frame.locator("#deviceDownloadBtn").inner_text() == "Download image"
            assert frame.locator("#middleText").input_value() == "Promote to King"
            frame.locator("#finish").select_option("silver")
            page.wait_for_timeout(300)
            assert master_requests == ["medal_gold.png", "medal_silver.png"], master_requests
            assert frame.locator("#middleText").input_value() == "Promote to King"
            frame.locator("#awardType").select_option("cup")
            page.wait_for_timeout(300)
            assert frame.locator("#cupPlaque").input_value() == "Promote\nto King"
            frame.locator("#finish").select_option("bronze")
            page.wait_for_timeout(300)
            assert frame.locator("#cupPlaque").input_value() == "Promote\nto King"
            frame.locator("#awardType").select_option("crystal")
            page.wait_for_timeout(300)
            assert frame.locator("#crystalTop").input_value() == "Promote\nto King"
            assert frame.locator("#crystalTopSize").input_value() == "140"
            with page.expect_download(timeout=10000) as download_info:
                frame.locator("#deviceDownloadBtn").click()
            assert download_info.value.suggested_filename.endswith(".png")
            before_engraver_upload = Fixture.upload_count
            frame.locator("#downloadBtn").click()
            page.wait_for_selector("#p2kTrophyEngraverV2121[hidden]", state="attached", timeout=10000)
            page.wait_for_function("document.querySelector('#adminShellNativeDetailHost [data-v2121-status]')?.textContent === 'Artwork updated.'", timeout=15000)
            assert Fixture.upload_count == before_engraver_upload + 1
            assert not page.evaluate("document.body.classList.contains('p2k-engraver-open')")

            Fixture.authenticated = False
            public = browser.new_context(bypass_csp=True, viewport={"width": 390, "height": 844})
            hall = public.new_page()
            public_errors: list[str] = []
            hall.on("pageerror", lambda e: public_errors.append(e.stack or str(e)))
            hall.goto(f"{origin}/ui-v2.html?ui=v2&page=hall&hall=achievements", wait_until="domcontentloaded")
            hall.wait_for_selector("[data-hall-subtab='trophies']", timeout=15000)
            hall.click("[data-hall-subtab='trophies']")
            hall.wait_for_selector("#p2kTrophyHallPanel:not([hidden]) .p2k-v2121-trophy-stats", timeout=15000)
            assert "hall=trophies" in hall.url
            values = hall.locator("#p2kTrophyHallPanel .p2k-v2121-trophy-stat strong").all_inner_texts()
            assert values == ["3", "2", "3", "2026"], values
            cols = hall.locator("#p2kTrophyHallPanel .p2k-trophy-grid").first.evaluate(
                "el => getComputedStyle(el).gridTemplateColumns.split(' ').filter(Boolean).length")
            assert cols == 2, cols
            order = hall.locator("#p2kTrophyHallPanel [data-v2121-order]")
            order.select_option("oldest")
            hall.wait_for_timeout(50)
            assert hall.locator("#p2kTrophyHallPanel .p2k-trophy-group > h3").first.inner_text() == "2024"
            order.select_option("league-az")
            hall.wait_for_timeout(50)
            assert hall.locator("#p2kTrophyHallPanel .p2k-trophy-group > h3").first.inner_text() == "Alpha League"
            # Inspect in the click task itself: deferred cleanup must not expose legacy fields.
            immediate_modal = hall.locator("#p2kTrophyHallPanel [data-open]").first.evaluate("""button => {
                button.click();
                const modal = document.querySelector('#p2kTrophyModal');
                return {
                    visible: !!modal && !modal.hidden,
                    enlarge: modal.querySelectorAll('[data-enlarge]').length,
                    award: [...modal.querySelectorAll('dt')].filter(el => el.textContent.trim() === 'Award').length,
                };
            }""")
            assert immediate_modal == {"visible": True, "enlarge": 0, "award": 0}, immediate_modal
            hall.locator("#p2kTrophyModal [data-close]").click()
            hall.locator("#p2kTrophyHallPanel [data-open]").first.click()
            hall.wait_for_selector("#p2kTrophyModal:not([hidden])")
            assert hall.locator("#p2kTrophyModal [data-enlarge]").count() == 0
            award_labels = hall.locator("#p2kTrophyModal dt").evaluate_all(
                "els => els.filter(el => el.textContent.trim() === 'Award').length")
            assert award_labels == 0
            hall.go_back(wait_until="domcontentloaded")
            assert "hall=achievements" in hall.url
            hall.go_forward(wait_until="domcontentloaded")
            hall.wait_for_selector("#p2kTrophyHallPanel:not([hidden])", timeout=15000)
            assert "hall=trophies" in hall.url
            assert not public_errors, public_errors
            standalone = public.new_page()
            standalone.on("pageerror", lambda e: public_errors.append(e.stack or str(e)))
            standalone.goto(f"{origin}/trophies/", wait_until="domcontentloaded")
            standalone.wait_for_selector("#p2kTrophyStandalone .p2k-v2121-trophy-stats")
            expect(standalone.locator(".p2k-trophy-card")).to_have_count(3)
            standalone.fill("[data-search]", "Alpha 2025")
            expect(standalone.locator(".p2k-trophy-card")).to_have_count(1)
            if not standalone.evaluate("!!document.getElementById('p2kTrophyR538FinalStyle')"):
                standalone.evaluate("delete window.__P2K_TROPHY_R5FIX3_8")
                standalone.add_script_tag(path=str(ROOT / "assets/js/admin/trophy-gallery-r5fix3.8.js"))
            standalone.locator("[data-open]").first.click()
            standalone.wait_for_selector("#p2kR538Modal:not([hidden])", timeout=10000)
            modal_box = standalone.locator("#p2kR538Modal .p2k-r538-modal").bounding_box()
            assert modal_box is not None
            right_gutter = 390 - (modal_box["x"] + modal_box["width"])
            assert modal_box["x"] >= 8 and right_gutter >= 8, (modal_box, right_gutter)
            close_box = standalone.locator("#p2kR538Modal [data-r538-close]").bounding_box()
            assert close_box is not None
            assert close_box["width"] >= 36 and abs(close_box["width"] - close_box["height"]) <= 1, close_box
            modal_image = standalone.locator("#p2kR538Modal [data-r538-view-image]")
            expect(modal_image).to_have_count(1)
            modal_image.click()
            standalone.wait_for_selector("#p2kR538Viewer:not([hidden])", timeout=5000)
            viewer_box = standalone.locator("#p2kR538Viewer .p2k-r538-viewer").bounding_box()
            assert viewer_box is not None
            assert viewer_box["x"] >= 6 and 390 - (viewer_box["x"] + viewer_box["width"]) >= 6, viewer_box
            standalone.click("#p2kR538Viewer [data-r538-viewer-close]")
            modal_image.focus()
            standalone.keyboard.press("Enter")
            standalone.wait_for_selector("#p2kR538Viewer:not([hidden])", timeout=5000)
            standalone.click("#p2kR538Viewer [data-r538-viewer-close]")
            assert standalone.locator("#dashboardAdministrationTab,[data-admin]").count() == 0
            assert standalone.evaluate("document.cookie") == ""
            assert not public_errors, public_errors
            public.close()
            context.close()
            browser.close()
    finally:
        server.shutdown(); server.server_close(); thread.join(timeout=5)
    assert not errors, errors
    print(json.dumps({"trophy_v2121_browser_gate": "passed", "actions": Fixture.actions,
                      "engraver_requests": master_requests}, indent=2))


if __name__ == "__main__":
    main()
