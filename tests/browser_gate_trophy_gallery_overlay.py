#!/usr/bin/env python3
"""Qualify the installed Trophy overlay through the real v2.11.4 page loader."""

from __future__ import annotations

from contextlib import contextmanager
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from threading import Thread
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright


ROOT = Path(__file__).resolve().parents[1]
BASE = "6706e619d310e2c74fe2734cfcef8dd2f83d70d1"
BUILD_ID = "trophy-installed-overlay-e2e"
RUNTIME = "a7555ea1e512e99261c4b2ae6451b9496cf89450"
CACHE = "poc-a7555ea1e512-20260909-r5"
CHROMIUM = os.environ.get("P2K_CHROMIUM") or shutil.which("chromium") or "/usr/bin/chromium"


class FixtureHandler(SimpleHTTPRequestHandler):
    def log_message(self, _format: str, *_args: object) -> None:
        return

    def fixture_api(self) -> bool:
        parsed = urlparse(self.path)
        if parsed.path.endswith("/server/team-points/public/oauth.php"):
            body = json.dumps({
                "ok": True,
                "enabled": True,
                "authenticated": True,
                "csrf": "fixture-csrf",
                "admin_bootstrap": "fixture-bootstrap",
                "profile": {"username": "Ximoon", "admin": True, "roles": ["admin"]},
            }).encode()
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return True
        if parsed.path.endswith("/server/team-points/public/session.php"):
            body=b'{"ok":true,"username":"ximoon","csrf":"fixture-admin-csrf"}'
            self.send_response(200);self.send_header("Content-Type","application/json");self.send_header("Content-Length",str(len(body)));self.end_headers();self.wfile.write(body);return True
        if parsed.path.endswith("/server/trophy-gallery/public/api.php"):
            body=b'{"ok":true,"records":[]}'
            self.send_response(200);self.send_header("Content-Type","application/json");self.send_header("Content-Length",str(len(body)));self.end_headers();self.wfile.write(body);return True
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
        if self.fixture_api():
            return
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
            archive = Path(tmp) / "r5.tar.gz"
            subprocess.run(["git","archive","--format=tar.gz",f"--prefix=PromoteToKing-{RUNTIME}/","-o",str(archive),RUNTIME],cwd=ROOT,check=True)
            env = os.environ.copy()
            env["P2K_TROPHY_ARCHIVE_FILE"] = str(archive)
            installed = subprocess.run([
                "bash", str(ROOT / "tools/poc/PromoteToKing_TrophyGallery_POC_2.11x.run"), str(tree), "install",
            ], cwd=ROOT, env=env, check=False, capture_output=True, text=True)
            if installed.returncode:
                raise AssertionError(installed.stdout + installed.stderr)
            yield tree
        finally:
            subprocess.run(["git", "worktree", "remove", "--force", str(tree)], cwd=ROOT, check=False, capture_output=True, text=True)


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
        try:
            with sync_playwright() as playwright:
                browser = playwright.chromium.launch(headless=True, executable_path=CHROMIUM, args=["--no-sandbox", "--disable-dev-shm-usage"])
                page = browser.new_page(bypass_csp=True)
                page.on("pageerror", lambda error: errors.append(str(error)))
                page.on("requestfailed", lambda request: bad_local.append(f"failed {request.url}") if request.url.startswith(origin) and request.resource_type == "script" else None)
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
                card.locator("a").first.click()
                page.wait_for_selector("#adminShellNativeDetailHost[data-native-detail='trophy-gallery']:not([hidden]) form")
                assert card.count() == 1 and panel.count() == 1

                registry_requests = [u for u in script_requests if "/assets/js/admin/tool-registry.js?" in u]
                trophy_requests = [u for u in script_requests if f"/assets/js/admin/trophy-gallery-poc.js?v={CACHE}" in u]
                result = {
                    "runtime_defined": page.evaluate("window.P2K_TROPHY_GALLERY_POC !== undefined"),
                    "admin_active": page.evaluate("window.P2K_ADMIN_MODE === true && document.getElementById('adminDashboardHost')?.hidden === false"),
                    "team_active": page.locator("[data-admin-category='team']").get_attribute("aria-pressed") == "true",
                    "cards": card.count(), "panels": panel.count(), "admin_form":page.locator("#adminShellNativeDetailHost form").count(),
                    "registry_requests": registry_requests, "trophy_requests": trophy_requests,
                    "page_errors": errors, "bad_local": bad_local,
                }
                browser.close()
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=5)

    assert result["runtime_defined"] and result["admin_active"] and result["team_active"], result
    assert result["cards"] == 1 and result["panels"] == 1 and result["admin_form"] == 1, result
    assert len(result["registry_requests"]) == 1 and len(result["trophy_requests"]) == 1, result
    assert result["page_errors"] == [] and result["bad_local"] == [], result
    print(json.dumps({"trophy_gallery_installed_overlay": "passed", **result}, indent=2))


if __name__ == "__main__":
    main()
