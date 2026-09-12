#!/usr/bin/env python3
"""Qualify the approved Trophy runtime over the current application release."""

from __future__ import annotations

from contextlib import contextmanager
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from io import BytesIO
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from threading import Thread
from urllib.parse import parse_qs, urlparse

from PIL import Image
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
BASE = "6706e619d310e2c74fe2734cfcef8dd2f83d70d1"
VERSION = (ROOT / "VERSION").read_text(encoding="utf-8").strip()
BUILD_KEY = "2.11.5-bf6a828490f57"
R538_JS = "r538-d52193a71712"
R538_CSS = "r538-fdcea54d62ba"
R5310_JS = "r5310-fd6d89209aa6"
CHROMIUM = os.environ.get("P2K_CHROMIUM") or shutil.which("chromium") or "/usr/bin/chromium"

MEDIA_BUFFER = BytesIO()
Image.new("RGB", (16, 16), (180, 100, 20)).save(MEDIA_BUFFER, format="PNG")
MEDIA_PNG = MEDIA_BUFFER.getvalue()

def active_runtime_path(path: str) -> bool:
    if path == "VERSION":
        return True
    if "/" not in path and Path(path).suffix.lower() in {".html", ".htm", ".js", ".css", ".php", ".json", ".webmanifest"}:
        return True
    return path.startswith(("assets/", "config/", "api/", "server/", "trophies/"))

def overlay_active_runtime(tree: Path) -> list[str]:
    changed: list[str] = []
    status = subprocess.check_output(
        ["git", "diff", "--name-status", f"{BASE}..HEAD"], cwd=ROOT, text=True
    )
    for line in status.splitlines():
        if not line.strip():
            continue
        parts = line.split("\t")
        code = parts[0]
        path = parts[-1]
        if not active_runtime_path(path):
            continue
        dst = tree / path
        if code.startswith("D"):
            if dst.exists() or dst.is_symlink():
                dst.unlink()
            changed.append(path)
            continue
        src = ROOT / path
        if not src.is_file():
            raise AssertionError(f"Runtime overlay source missing: {path}")
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(src, dst)
        changed.append(path)
    return changed

@contextmanager
def production_tree():
    with tempfile.TemporaryDirectory(prefix="p2k-trophy-final-") as tmp:
        tree = Path(tmp) / "site"
        subprocess.run(
            ["git", "worktree", "add", "--detach", str(tree), BASE],
            cwd=ROOT, check=True, capture_output=True, text=True,
        )
        try:
            changed = overlay_active_runtime(tree)
            assert "VERSION" in changed
            assert (tree / "VERSION").read_text().strip() == VERSION
            site = (tree / "assets/js/site-config.js").read_text(encoding="utf-8")
            ui = (tree / "ui-v2.html").read_text(encoding="utf-8")
            assert f'version: "{VERSION}"' in site
            assert BUILD_KEY in site and BUILD_KEY in ui
            assert R538_JS in ui and R538_CSS in ui
            js_hash = hashlib.sha256((tree / "assets/js/admin/trophy-gallery-r5fix3.8.js").read_bytes()).hexdigest()
            css_hash = hashlib.sha256((tree / "assets/trophy-gallery/trophy-gallery-r5fix3.8.css").read_bytes()).hexdigest()
            assert js_hash.startswith("d52193a71712"), js_hash
            assert css_hash.startswith("fdcea54d62ba"), css_hash
            yield tree
        finally:
            subprocess.run(
                ["git", "worktree", "remove", "--force", str(tree)],
                cwd=ROOT, check=False, capture_output=True, text=True,
            )

class FixtureHandler(SimpleHTTPRequestHandler):
    authenticated = True
    revision = 1
    record = {
        "id": "installed-trophy", "status": "draft", "league": "OWL",
        "competition": "Cup", "award": "Winner", "title": "Installed Trophy",
        "award_date": "2026-09-09", "description_md": "Persistent",
        "award_page": "", "competition_page": "", "result_table_url": "",
        "vignette_media_id": "", "modal_media_id": "", "matches": [],
    }
    meta: dict[str, dict] = {}
    upload_count = 0

    def log_message(self, _format: str, *_args: object) -> None:
        return

    def send_json(self, payload: dict, status: int = 200) -> None:
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def fixture_get(self) -> bool:
        parsed = urlparse(self.path)
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
            self.send_json({"ok": True, "version": 1, "records": cls.meta})
            return True
        if parsed.path.endswith("/server/trophy-gallery/public/api.php"):
            action = parse_qs(parsed.query).get("action", ["list"])[0]
            if action == "match-search":
                payload = {"ok": True, "matches": [{
                    "match_id": 987, "match_name": "Promote to King vs Golden Phoenix",
                    "opponent_name": "Golden Phoenix", "end_time": "2026-08-01",
                }]}
            elif action in ("list", "admin-list"):
                payload = {"ok": True, "revision": cls.revision, "records": [cls.record]}
            else:
                payload = {"ok": True}
            self.send_json(payload)
            return True
        if parsed.path.endswith("/server/trophy-gallery/public/media.php"):
            body = MEDIA_PNG
            self.send_response(200)
            self.send_header("Content-Type", "image/png")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
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
        cls = type(self)
        length = int(self.headers.get("Content-Length", "0"))
        raw = self.rfile.read(length)
        if parsed.path.endswith("/server/trophy-gallery/public/editor-meta.php"):
            incoming = json.loads(raw or b"{}")
            trophy_id = str(incoming.get("id") or "")
            cls.meta[trophy_id] = {
                "modal_media_mode": incoming.get("modal_media_mode", "vignette"),
                "result_tables": incoming.get("result_tables") or [],
                "updated_at": "2026-09-11T00:00:00Z",
            }
            self.send_json({"ok": True, "id": trophy_id, "meta": cls.meta[trophy_id]})
            return
        if parsed.path.endswith("/server/trophy-gallery/public/api.php"):
            action = parse_qs(parsed.query).get("action", [""])[0]
            cls.revision += 1
            if action == "save":
                incoming = json.loads(raw or b"{}")
                incoming.pop("revision", None)
                cls.record = {**cls.record, **incoming}
                payload = {"ok": True, "record": cls.record, "revision": cls.revision}
            elif action == "upload":
                cls.upload_count += 1
                slot = "modal" if b"\r\n\r\nmodal\r\n" in raw else "vignette"
                media_id = ("b" if slot == "modal" else "a") * 31 + str(cls.upload_count % 10)
                cls.record[slot + "_media_id"] = media_id
                payload = {"ok": True, "media": {"id": media_id}, "record": cls.record, "revision": cls.revision}
            else:
                payload = {"ok": True, "record": cls.record, "revision": cls.revision}
            self.send_json(payload)
            return
        if self.fixture_get():
            return
        self.send_error(404)

def track_trophy_chess_requests(context, page, sink: list[str]):
    session = context.new_cdp_session(page)
    session.send("Network.enable")
    def inspect(event: dict) -> None:
        url = str(event.get("request", {}).get("url", ""))
        initiator = json.dumps(event.get("initiator", {}), separators=(",", ":"))
        if "chess.com" in url and ("trophy-gallery-poc.js" in initiator or "trophy-gallery-r5fix3.8.js" in initiator):
            sink.append(url)
    session.on("Network.requestWillBeSent", inspect)
    return session

def main() -> None:
    if not Path(CHROMIUM).exists():
        raise RuntimeError("Chromium is required for the installed Trophy overlay browser gate")
    with production_tree() as tree:
        server = ThreadingHTTPServer(("127.0.0.1", 0), partial(FixtureHandler, directory=str(tree)))
        thread = Thread(target=server.serve_forever, daemon=True)
        thread.start()
        origin = f"http://127.0.0.1:{server.server_port}"
        errors: list[str] = []
        trophy_chess_requests: list[str] = []
        try:
            with sync_playwright() as playwright:
                browser = playwright.chromium.launch(
                    headless=True, executable_path=CHROMIUM,
                    args=["--no-sandbox", "--disable-dev-shm-usage"],
                )
                context = browser.new_context(bypass_csp=True, accept_downloads=True)
                page = context.new_page()
                track_trophy_chess_requests(context, page, trophy_chess_requests)
                page.on("pageerror", lambda error: errors.append(error.stack or str(error)))
                page.goto(f"{origin}/ui-v2.html?ui=v2&page=administration&adminCategory=team&trophy=1", wait_until="domcontentloaded")
                page.wait_for_function("window.P2K_TROPHY_GALLERY_POC !== undefined", timeout=15000)
                page.wait_for_function("window.__P2K_TROPHY_R5FIX3_8 === true", timeout=15000)
                page.wait_for_selector("#adminShellNativeDetailHost[data-native-detail='trophy-gallery']:not([hidden]) form[data-r538-enhanced='1']", timeout=15000)
                assert page.evaluate("window.P2K_SITE_CONFIG?.version") == VERSION
                assert page.locator(f'script[src*="site-config.js?v={BUILD_KEY}"]').count() == 1
                assert page.locator(f'script[src*="trophy-gallery-r5fix3.8.js?v={R538_JS}"]').count() == 1
                assert page.locator(f'link[href*="trophy-gallery-r5fix3.8.css?v={R538_CSS}"]').count() == 1
                assert page.locator(f'script[src*="trophy-gallery-r5fix3.10.js?v={R5310_JS}"]').count() == 1

                host = "#adminShellNativeDetailHost"
                page.evaluate("""() => {
                    const style = document.createElement('style');
                    style.id = 'preview-escape-regression';
                    style.textContent = `
                      #adminShellNativeDetailHost .p2k-media-preview { height:100vh!important; overflow:visible!important; }
                      #adminShellNativeDetailHost .p2k-media-preview img { position:fixed!important; inset:0!important; width:100vw!important; height:100vh!important; max-width:none!important; max-height:none!important; }
                    `;
                    document.head.appendChild(style);
                }""")
                page.fill(f"{host} [name='description_md']", "Persistent after final overlay")
                page.select_option(f"{host} [name='status']", "published")
                page.click(f"{host} form button[type='submit']")
                page.wait_for_selector(f"{host} form[data-r538-enhanced='1']")
                page.wait_for_function("document.querySelector(\"#adminShellNativeDetailHost [name='description_md']\")?.value === 'Persistent after final overlay'")

                page.fill(f"{host} [data-match-search]", "Golden")
                page.wait_for_selector(f"{host} [data-r538-match][value='987']", timeout=15000)
                page.check(f"{host} [data-r538-match][value='987']")
                page.click(f"{host} [data-r538-apply-matches]")
                page.wait_for_selector(f"{host} [data-unlink='987']", timeout=15000)

                page.wait_for_selector(f"{host} [data-r538-result-row]")
                rows = page.locator(f"{host} [data-r538-result-row]")
                rows.nth(0).locator("[data-r538-result-label]").fill("Primary table")
                rows.nth(0).locator("[data-r538-result-url]").fill("https://example.test/primary")
                page.click(f"{host} [data-r538-result-add]")
                rows = page.locator(f"{host} [data-r538-result-row]")
                assert rows.count() == 2
                rows.nth(1).locator("[data-r538-result-label]").fill("Secondary table")
                rows.nth(1).locator("[data-r538-result-url]").fill("https://example.test/secondary")
                page.click(f"{host} form button[type='submit']")
                page.wait_for_selector(f"{host} form[data-r538-enhanced='1']")
                page.wait_for_function("document.querySelectorAll(\"#adminShellNativeDetailHost [data-r538-result-row]\").length === 2")

                page.click(f"{host} [data-r538-mode='none']")
                page.wait_for_function("document.querySelector(\"#adminShellNativeDetailHost [data-r538-mode-note]\")?.textContent === 'No modal image'")
                page.click(f"{host} [data-r538-mode='vignette']")
                page.wait_for_function("document.querySelector(\"#adminShellNativeDetailHost [data-r538-mode-note]\")?.textContent === 'Using vignette image'")

                upload = tree / "test-upload.png"
                Image.new("RGB", (16, 16), (180, 100, 20)).save(upload)
                before_vignette = FixtureHandler.upload_count
                with page.expect_response(lambda r: "server/trophy-gallery/public/api.php?action=upload" in r.url and r.request.method == "POST", timeout=15000):
                    page.set_input_files(f"{host} [data-upload='vignette']", str(upload))
                assert FixtureHandler.upload_count == before_vignette + 1
                page.wait_for_selector(f"{host} [data-preview='vignette'] img", timeout=15000)
                page.wait_for_function("""document.querySelector("#adminShellNativeDetailHost [data-preview='vignette']")?.dataset.r5310Contained === '1'""")
                preview = page.locator(f"{host} [data-preview='vignette']")
                preview_image = preview.locator("img")
                preview_box = preview.bounding_box()
                preview_image_box = preview_image.bounding_box()
                assert preview_box is not None and preview_image_box is not None
                assert 279 <= preview_box["height"] <= 281, preview_box
                assert preview_image.evaluate("el => getComputedStyle(el).position") == "absolute"
                assert preview.evaluate("el => getComputedStyle(el).overflow") == "hidden"
                assert preview_image_box["x"] >= preview_box["x"] - 1
                assert preview_image_box["y"] >= preview_box["y"] - 1
                assert preview_image_box["x"] + preview_image_box["width"] <= preview_box["x"] + preview_box["width"] + 1
                assert preview_image_box["y"] + preview_image_box["height"] <= preview_box["y"] + preview_box["height"] + 1

                page.click(f"{host} [data-engrave='modal']")
                page.wait_for_selector(f"{host} .p2k-engraver-modal:not([hidden]) iframe.p2k-engraver", timeout=15000)
                frame = page.frame_locator(f"{host} .p2k-engraver-modal:not([hidden]) iframe.p2k-engraver")
                frame.locator("#p2kR538UsePrize").wait_for(timeout=15000)
                assert frame.locator("#sampleBtn").count() == 0
                assert frame.locator("#middleText").input_value() == "Promote to King"
                assert frame.locator("#cupPlaque").input_value() == "Promote to King"
                assert frame.locator("#crystalTop").input_value() == "Promote to King"
                with page.expect_download(timeout=15000) as download_info:
                    frame.locator("#downloadBtn").click()
                assert download_info.value.suggested_filename == "p2k-trophy-prize.png"
                before_prize = FixtureHandler.upload_count
                with page.expect_response(lambda r: "server/trophy-gallery/public/api.php?action=upload" in r.url and r.request.method == "POST", timeout=15000):
                    frame.locator("#p2kR538UsePrize").click()
                assert FixtureHandler.upload_count == before_prize + 1
                page.wait_for_function("!document.body.classList.contains('p2k-engraver-open')")
                page.wait_for_selector(f"{host} form[data-r538-enhanced='1']", timeout=15000)
                page.wait_for_function("document.querySelector('[data-r538-mode-note]')?.textContent === 'Custom modal image'")
                page.wait_for_selector(f"{host} [data-preview='modal'] img", timeout=15000)
                assert FixtureHandler.meta["installed-trophy"]["modal_media_mode"] == "custom"
                assert len(FixtureHandler.meta["installed-trophy"]["result_tables"]) == 2

                page.reload(wait_until="domcontentloaded")
                page.wait_for_selector(f"{host}[data-native-detail='trophy-gallery']:not([hidden]) form[data-r538-enhanced='1']", timeout=15000)
                assert page.input_value(f"{host} [name='description_md']") == "Persistent after final overlay"
                assert page.locator(f"{host} [data-unlink='987']").count() == 1
                page.wait_for_function("document.querySelectorAll(\"#adminShellNativeDetailHost [data-r538-result-row]\").length === 2")

                FixtureHandler.authenticated = False
                public_context = browser.new_context(bypass_csp=True, viewport={"width": 390, "height": 844})
                public_page = public_context.new_page()
                public_errors: list[str] = []
                public_page.on("pageerror", lambda error: public_errors.append(str(error)))
                track_trophy_chess_requests(public_context, public_page, trophy_chess_requests)
                public_page.goto(f"{origin}/ui-v2.html?ui=v2&page=hall&hall=trophies", wait_until="domcontentloaded")
                public_page.wait_for_selector("#p2kTrophyHallTab", timeout=15000)
                public_page.click("#p2kTrophyHallTab")
                public_page.wait_for_selector("#p2kTrophyHallPanel:not([hidden]) .p2k-trophy-card[data-r538-card]", timeout=15000)
                card_button = public_page.locator("#p2kTrophyHallPanel .p2k-trophy-card[data-r538-card] > button[data-r538-button]").first
                assert card_button.evaluate("el => el.children.length") == 2
                assert card_button.locator("small, span, p").count() == 0
                card_button.click()
                public_page.wait_for_selector("#p2kR538Modal:not([hidden])", timeout=15000)
                box = public_page.locator("#p2kR538Modal:not([hidden]) .p2k-r538-modal").bounding_box()
                assert box is not None
                assert box["x"] >= -1 and box["x"] + box["width"] <= 391
                assert public_page.locator("#p2kR538Modal a[href='https://example.test/primary']").count() == 1
                assert public_page.locator("#p2kR538Modal a[href='https://example.test/secondary']").count() == 1
                public_context.close()
                context.close()
                browser.close()
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=5)

    assert not trophy_chess_requests, trophy_chess_requests
    assert not errors, errors
    print(json.dumps({
        "final_installed_overlay": "passed",
        "version": VERSION,
        "asset_build_key": BUILD_KEY,
        "r5fix3_8_js_key": R538_JS,
        "r5fix3_8_css_key": R538_CSS,
        "r5fix3_10_js_key": R5310_JS,
        "admin_preview_contained": True,
        "multi_match": True,
        "multi_result_tables": True,
        "modal_modes": True,
        "download_png": True,
        "use_prize": True,
        "mobile_modal_contained": True,
        "trophy_chess_requests": trophy_chess_requests,
    }, indent=2))

if __name__ == "__main__":
    main()
