#!/usr/bin/env python3
"""Qualify the installed Trophy overlay through the real v2.11.4 page loader."""

from __future__ import annotations

from contextlib import contextmanager
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from io import BytesIO
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from threading import Thread
from urllib.parse import urlparse
from urllib.parse import parse_qs
from PIL import Image

from playwright.sync_api import sync_playwright


ROOT = Path(__file__).resolve().parents[1]
BASE = "6706e619d310e2c74fe2734cfcef8dd2f83d70d1"
BUILD_ID = "trophy-installed-overlay-e2e"
RUNTIME = "e883881c083e1490335fff373bdeb8081ecc72cb"
CACHE = "poc-e883881c083e-20260910-r5"
CHROMIUM = os.environ.get("P2K_CHROMIUM") or shutil.which("chromium") or "/usr/bin/chromium"

MEDIA_BUFFER = BytesIO()
Image.new("RGB", (8, 8), (180, 100, 20)).save(MEDIA_BUFFER, format="PNG")
MEDIA_PNG = MEDIA_BUFFER.getvalue()


class FixtureHandler(SimpleHTTPRequestHandler):
    authenticated = True
    revision = 1
    record = {"id":"installed-trophy","status":"draft","league":"OWL","competition":"Cup","award":"Winner","title":"Installed Trophy","award_date":"2026-09-09","description_md":"Persistent","award_page":"","competition_page":"","result_table_url":"","vignette_media_id":"","modal_media_id":"","matches":[]}
    upload_count = 0
    def log_message(self, _format: str, *_args: object) -> None:
        return

    def fixture_api(self) -> bool:
        parsed = urlparse(self.path)
        if parsed.path.endswith("/server/team-points/public/oauth.php"):
            body = json.dumps({
                "ok": True,
                "enabled": True,
                "authenticated": type(self).authenticated,
                "csrf": "fixture-csrf",
                "admin_bootstrap": "fixture-bootstrap",
                "profile": {"username": "Ximoon", "admin": type(self).authenticated, "roles": ["admin"] if type(self).authenticated else []},
            }).encode()
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return True
        if parsed.path.endswith("/server/team-points/public/session.php"):
            if type(self).authenticated:
                body=b'{"ok":true,"username":"ximoon","csrf":"fixture-admin-csrf"}'
                status=200
            else:
                body=b'{"ok":false,"error":{"code":"OAUTH_SESSION_REQUIRED","message":"Authentication required"}}'
                status=401
            self.send_response(status);self.send_header("Content-Type","application/json");self.send_header("Content-Length",str(len(body)));self.end_headers();self.wfile.write(body);return True
        if parsed.path.endswith("/server/trophy-gallery/public/api.php"):
            action=parse_qs(parsed.query).get("action",["list"])[0]
            if action=="match-search": payload={"ok":True,"matches":[{"match_id":987,"match_name":"Promote to King vs Golden Phoenix","opponent_name":"Golden Phoenix","end_time":"2026-08-01"}]}
            elif action in ("list","admin-list"): payload={"ok":True,"revision":type(self).revision,"records":[type(self).record]}
            else: payload={"ok":True}
            body=json.dumps(payload).encode()
            self.send_response(200);self.send_header("Content-Type","application/json");self.send_header("Content-Length",str(len(body)));self.end_headers();self.wfile.write(body);return True
        if parsed.path.endswith("/server/trophy-gallery/public/media.php"):
            body=MEDIA_PNG
            self.send_response(200);self.send_header("Content-Type","image/png");self.send_header("Content-Length",str(len(body)));self.end_headers();self.wfile.write(body);return True
        if parsed.path.endswith(".php") or "/api/" in parsed.path:
            body = b'{"ok":true,"rows":[],"items":[],"matches":[],"members":[]}'
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return True
        return False

    def do_GET(self) -> None:  # noqa: N802 - stdlib handler API
        if self.fixture_api():
            return
        super().do_GET()

    def do_POST(self) -> None:  # noqa: N802 - stdlib handler API
        parsed=urlparse(self.path)
        if parsed.path.endswith("/server/trophy-gallery/public/api.php"):
            action=parse_qs(parsed.query).get("action",[""])[0];length=int(self.headers.get("Content-Length","0"));raw=self.rfile.read(length)
            cls=type(self);cls.revision+=1
            if action=="save":
                incoming=json.loads(raw or b"{}");incoming.pop("revision",None);cls.record={**cls.record,**incoming};payload={"ok":True,"record":cls.record,"revision":cls.revision}
            elif action=="upload":
                cls.upload_count+=1;slot="modal" if b'\r\nmodal\r\n' in raw else "vignette";media_id=("b" if slot=="modal" else "a")*31+str(cls.upload_count%10);cls.record[slot+"_media_id"]=media_id;payload={"ok":True,"media":{"id":media_id},"record":cls.record,"revision":cls.revision}
            else: payload={"ok":True,"record":cls.record,"revision":cls.revision}
            body=json.dumps(payload).encode();self.send_response(200);self.send_header("Content-Type","application/json");self.send_header("Content-Length",str(len(body)));self.end_headers();self.wfile.write(body);return
        if self.fixture_api(): return
        self.send_error(404)


@contextmanager
def production_tree():
    with tempfile.TemporaryDirectory(prefix="p2k-trophy-installed-") as tmp:
        tree = Path(tmp) / "site"
        subprocess.run(["git", "worktree", "add", "--detach", str(tree), BASE], cwd=ROOT, check=True, capture_output=True, text=True)
        try:
            subprocess.run([
                "python3", str(ROOT / "tools/release/static_asset_cache_key.py"), "stamp",
                "--root", str(tree), "--version", "2.11.4", "--source-head", BASE, "--build-id", BUILD_ID,
            ], cwd=ROOT, check=True, capture_output=True, text=True)
            installed = subprocess.run([
                "bash", str(ROOT / "tools/poc/PromoteToKing_TrophyGallery_POC_2.11x.run"), str(tree), "install",
            ], cwd=ROOT, check=False, capture_output=True, text=True)
            if installed.returncode:
                raise AssertionError(installed.stdout + installed.stderr)
            yield tree
        finally:
            subprocess.run(["git", "worktree", "remove", "--force", str(tree)], cwd=ROOT, check=False, capture_output=True, text=True)


def track_trophy_chess_requests(context, page, sink: list[str]):
    """Record external Chess.com requests initiated by the Trophy runtime."""
    session = context.new_cdp_session(page)
    session.send("Network.enable")

    def inspect(event: dict) -> None:
        url = str(event.get("request", {}).get("url", ""))
        initiator = json.dumps(event.get("initiator", {}), separators=(",", ":"))
        if "chess.com" in url and "trophy-gallery-poc.js" in initiator:
            sink.append(url)

    session.on("Network.requestWillBeSent", inspect)
    return session


def track_failed_local_script(request, origin: str, sink: list[str]) -> None:
    """Reject real local script failures, excluding Chromium navigation cancellation."""
    if request.url.startswith(origin) and request.resource_type == "script" and request.failure != "net::ERR_ABORTED":
        sink.append(f"failed {request.url}: {request.failure}")


def main() -> None:
    if not Path(CHROMIUM).exists():
        raise RuntimeError("Chromium is required for the installed Trophy overlay browser gate")
    with production_tree() as tree:
        server = ThreadingHTTPServer(("127.0.0.1", 0), partial(FixtureHandler, directory=str(tree)))
        thread = Thread(target=server.serve_forever, daemon=True)
        thread.start()
        origin = f"http://127.0.0.1:{server.server_port}"
        errors: list[str] = []
        bad_local: list[str] = []
        script_requests: list[str] = []
        trophy_chess_requests: list[str] = []
        try:
            with sync_playwright() as playwright:
                browser = playwright.chromium.launch(headless=True, executable_path=CHROMIUM, args=["--no-sandbox", "--disable-dev-shm-usage"])
                page = browser.new_page(bypass_csp=True)
                track_trophy_chess_requests(page.context, page, trophy_chess_requests)
                page.on("pageerror", lambda error: errors.append(error.stack or str(error)))
                page.on("requestfailed", lambda request: track_failed_local_script(request, origin, bad_local))
                page.on("response", lambda response: bad_local.append(f"HTTP {response.status} {response.url}") if response.url.startswith(origin) and response.status >= 400 else None)
                page.on("request", lambda request: script_requests.append(request.url) if request.resource_type == "script" else None)
                url = f"{origin}/ui-v2.html?ui=v2&page=administration&adminCategory=team&trophy=1"
                page.goto(url, wait_until="domcontentloaded")
                page.wait_for_function("window.P2K_TROPHY_GALLERY_POC !== undefined", timeout=15000)
                page.wait_for_function("document.getElementById('dashboardAdministrationTab')?.hidden === false", timeout=15000)
                page.wait_for_function("document.querySelector(\"[data-admin-category='team']\")?.getAttribute('aria-pressed') === 'true'", timeout=15000)
                page.wait_for_selector("#adminShellNativeDetailHost[data-native-detail='trophy-gallery']:not([hidden]) form", timeout=15000)

                card = page.locator("[data-admin-shell-card='trophies']")
                panel = page.locator("#adminShellNativeDetailHost[data-native-detail='trophy-gallery']")
                assert card.count() == 1 and panel.count() == 1
                page.locator("#adminShellDetailBack").click()
                page.wait_for_selector("[data-admin-shell-panel='team']:not([hidden]) [data-admin-shell-card='trophies']")
                page.wait_for_function("!new URL(location.href).searchParams.has('trophy')")
                card.locator("a").first.click()
                page.wait_for_selector("#adminShellNativeDetailHost[data-native-detail='trophy-gallery']:not([hidden]) form")
                assert card.count() == 1 and panel.count() == 1

                page.locator("#adminShellDetailBack").click()
                page.locator("[data-admin-category='members']").click()
                page.locator("[data-admin-category='team']").click()
                page.wait_for_selector("[data-admin-shell-panel='team']:not([hidden]) [data-admin-shell-card='trophies']")
                card.locator("a").first.click()
                page.wait_for_selector("#adminShellNativeDetailHost form")
                page.fill("#adminShellNativeDetailHost [name='description_md']","Persistent after reload")
                page.select_option("#adminShellNativeDetailHost [name='status']","published")
                page.click("#adminShellNativeDetailHost form button[type='submit']")
                page.wait_for_function("document.querySelector(\"#adminShellNativeDetailHost [name='description_md']\")?.value === 'Persistent after reload'")
                page.fill("#adminShellNativeDetailHost [data-match-search]","Golden")
                page.wait_for_selector("#adminShellNativeDetailHost [data-link='987']")
                page.click("#adminShellNativeDetailHost [data-link='987']")
                page.wait_for_selector("#adminShellNativeDetailHost [data-unlink='987']")
                upload=tree/"test-upload.png";Image.new("RGB",(8,8),(180,100,20)).save(upload)
                page.set_input_files("#adminShellNativeDetailHost [data-upload='vignette']",str(upload))
                page.wait_for_selector("#adminShellNativeDetailHost [data-preview='vignette'] img")
                page.click("#adminShellNativeDetailHost [data-engrave='modal']")
                page.evaluate("""() => window.dispatchEvent(new MessageEvent('message',{origin:location.origin,data:{type:'p2k-trophy-engraving',name:'engraved.png',blob:new Blob(['png'],{type:'image/png'})}}))""")
                page.wait_for_selector("#adminShellNativeDetailHost [data-preview='modal'] img")
                page.reload(wait_until="domcontentloaded")
                page.wait_for_selector("#adminShellNativeDetailHost[data-native-detail='trophy-gallery']:not([hidden]) form",timeout=15000)
                assert page.input_value("#adminShellNativeDetailHost [name='description_md']")=="Persistent after reload"
                assert page.locator("#adminShellNativeDetailHost [data-unlink='987']").count()==1

                FixtureHandler.authenticated=False
                public_context=browser.new_context(bypass_csp=True);public_page=public_context.new_page();public_errors=[];public_page.on("pageerror",lambda error:public_errors.append(str(error)))
                track_trophy_chess_requests(public_context, public_page, trophy_chess_requests)
                public_page.goto(f"{origin}/ui-v2.html?ui=v2&page=administration&adminCategory=team&trophy=1",wait_until="domcontentloaded")
                public_page.wait_for_function("window.P2K_ADMIN_MODE === false",timeout=15000)
                compatibility_denied=public_page.locator("#adminDashboardHost:not([hidden])").count()==0 and public_page.locator("#dashboardAdministrationTab:not([hidden])").count()==0
                public_page.goto(f"{origin}/ui-v2.html?ui=v2&page=hall&hall=trophies",wait_until="domcontentloaded")
                public_page.wait_for_selector("#p2kTrophyHallTab",timeout=15000);public_page.click("#p2kTrophyHallTab")
                public_page.wait_for_selector("#p2kTrophyHallPanel:not([hidden]) .p2k-trophy-card",timeout=15000)
                hall_public=public_page.locator("#p2kTrophyHallPanel .p2k-trophy-card").count()==1 and public_page.locator("#dashboardAdministrationTab:not([hidden])").count()==0
                public_context.close()

                registry_requests = [u for u in script_requests if "/assets/js/admin/tool-registry.js?" in u]
                trophy_requests = [u for u in script_requests if f"/assets/js/admin/trophy-gallery-poc.js?v={CACHE}" in u]
                result = {
                    "runtime_defined": page.evaluate("window.P2K_TROPHY_GALLERY_POC !== undefined"),
                    "admin_active": page.evaluate("window.P2K_ADMIN_MODE === true && document.getElementById('adminDashboardHost')?.hidden === false"),
                    "team_active": page.locator("[data-admin-category='team']").get_attribute("aria-pressed") == "true",
                    "cards": card.count(), "panels": panel.count(), "admin_form":page.locator("#adminShellNativeDetailHost form").count(),
                    "registry_requests": registry_requests, "trophy_requests": trophy_requests,
                    "persistent_reload":True,"match_search":True,"upload":True,"engraving_save":True,"compatibility_denied":compatibility_denied,"hall_public":hall_public,"hall_public_errors":public_errors,"trophy_chess_requests":trophy_chess_requests,
                    "page_errors": errors, "bad_local": bad_local,
                }
                browser.close()
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=5)

    assert result["runtime_defined"] and result["admin_active"] and result["team_active"], result
    assert result["cards"] == 1 and result["panels"] == 1 and result["admin_form"] == 1, result
    assert result["compatibility_denied"], result
    assert len(result["registry_requests"]) == 2 and len(result["trophy_requests"]) == 2, result
    assert result["trophy_chess_requests"] == [], result
    assert result["hall_public"] and result["hall_public_errors"] == [], result
    assert result["page_errors"] == [] and result["bad_local"] == [], result
    print(json.dumps({"trophy_gallery_installed_overlay": "passed", **result}, indent=2))


if __name__ == "__main__":
    main()
