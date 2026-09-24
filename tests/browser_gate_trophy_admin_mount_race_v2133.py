#!/usr/bin/env python3
"""Regression for Trophy admin host replacement while admin-index is in flight."""
from __future__ import annotations

from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
import os
from pathlib import Path
import shutil
from threading import Thread

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
CHROMIUM = os.environ.get("P2K_CHROMIUM") or shutil.which("chromium") or "/usr/bin/chromium"

HTML = r'''<!doctype html><html><head><meta charset="utf-8"></head><body>
<div id="adminShellNativeDetailHost" data-native-detail="trophy-gallery"></div>
<script>
window.P2K_ADMIN_MODE=true;
window.P2K_TROPHY_ADMIN_VIEW_V2121={form(){return '<form data-v2121-form></form>'}};
window.P2K_TROPHY_MATCHES_V2121={bind(){},render(){}};
window.__adminIndexCalls=0;
window.P2K_TEAM_POINTS_CLIENT={endpointRequest(_url,options={}){
  const action=String(options.action||'');
  if(action==='admin-index'){
    window.__adminIndexCalls++;
    return new Promise(resolve=>setTimeout(()=>resolve({records:[],revision:1}),250));
  }
  if(action==='get')return Promise.resolve({record:{id:'x',status:'draft',matches:[]},revision:1});
  return Promise.resolve({meta:{},revision:1});
}};
</script>
<script src="/assets/js/admin/trophy-gallery-admin-v2121.js"></script>
</body></html>'''


class Fixture(SimpleHTTPRequestHandler):
    def log_message(self, *_args):
        return

    def do_GET(self):  # noqa: N802
        if self.path.split("?", 1)[0] == "/race.html":
            body = HTML.encode()
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        return super().do_GET()


def main() -> None:
    if not Path(CHROMIUM).exists():
        raise RuntimeError("Chromium is required for Trophy mount-race regression")
    server = ThreadingHTTPServer(("127.0.0.1", 0), partial(Fixture, directory=str(ROOT)))
    thread = Thread(target=server.serve_forever, daemon=True)
    thread.start()
    origin = f"http://127.0.0.1:{server.server_port}"
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True, executable_path=CHROMIUM,
                                        args=["--no-sandbox", "--disable-dev-shm-usage"])
            page = browser.new_page()
            errors: list[str] = []
            page.on("pageerror", lambda error: errors.append(error.stack or str(error)))
            page.goto(f"{origin}/race.html", wait_until="domcontentloaded")
            page.wait_for_function("window.P2K_TROPHY_ADMIN_V2121 !== undefined")
            page.wait_for_function("window.__adminIndexCalls >= 1")
            page.evaluate("""() => {
              const old=document.querySelector('#adminShellNativeDetailHost');
              const fresh=document.createElement('div');
              fresh.id='adminShellNativeDetailHost';
              fresh.dataset.nativeDetail='trophy-gallery';
              old.replaceWith(fresh);
              window.P2K_TROPHY_ADMIN_V2121.mount();
            }""")
            page.wait_for_function("window.__adminIndexCalls >= 2")
            page.wait_for_selector("#adminShellNativeDetailHost [data-v2121-admin-root]", timeout=5000)
            page.wait_for_selector("#adminShellNativeDetailHost [data-v2121-filter]", timeout=5000)
            page.wait_for_timeout(400)
            assert page.locator("#adminShellNativeDetailHost [data-v2121-admin-root]").count() == 1
            assert page.locator("#adminShellNativeDetailHost [data-v2121-filter]").count() == 1
            page.fill("#adminShellNativeDetailHost [data-v2121-filter]", "anything")
            assert not errors, errors
            browser.close()
    finally:
        server.shutdown()
        server.server_close()
    print("Trophy admin mount-race browser gate passed.")


if __name__ == "__main__":
    main()
