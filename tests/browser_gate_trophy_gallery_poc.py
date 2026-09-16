#!/usr/bin/env python3
"""Fresh unauthenticated standalone Trophy Gallery browser qualification."""
from __future__ import annotations
from functools import partial
from http.server import SimpleHTTPRequestHandler,ThreadingHTTPServer
import json,os,shutil
from io import BytesIO
from pathlib import Path
from threading import Thread
from urllib.parse import urlparse
from PIL import Image
from playwright.sync_api import TimeoutError as PlaywrightTimeoutError, sync_playwright

ROOT=Path(__file__).resolve().parents[1]
CHROMIUM=os.environ.get("P2K_CHROMIUM") or shutil.which("chromium") or "/usr/bin/chromium"
RECORDS=[
 {"id":"new","league":"OWL","competition":"Grand Prix","award":"Winner","title":"Newest Trophy","award_date":"2026-09-09","description_md":"**Bold** and *italic*\n\n- First\n- Second\n\n[Safe](https://example.test/)\n\n<script>bad()</script>","award_page":"https://example.test/award","competition_page":"","result_table_url":"","vignette_media_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","modal_media_id":"","matches":[]},
 {"id":"old","league":"PCL","competition":"Cup","award":"Silver","title":"Older Trophy","award_date":"2025-01-02","description_md":"Older","award_page":"","competition_page":"https://example.test/competition","result_table_url":"https://example.test/results","vignette_media_id":"","modal_media_id":"","matches":[]},
 {"id":"undated","league":"OWL","competition":"Classic","award":"Bronze","title":"Undated Trophy","award_date":"","description_md":"Undated","award_page":"","competition_page":"","result_table_url":"","vignette_media_id":"","modal_media_id":"","matches":[]},
]

class Handler(SimpleHTTPRequestHandler):
 def log_message(self,*_):pass
 def do_GET(self):
  parsed=urlparse(self.path)
  if parsed.path.endswith("/server/trophy-gallery/public/editor-meta.php"):
   body=json.dumps({"ok":True,"version":1,"records":{}}).encode();self.send_response(200);self.send_header("Content-Type","application/json");self.send_header("Content-Length",str(len(body)));self.end_headers();self.wfile.write(body);return
  if parsed.path.endswith("/server/trophy-gallery/public/api.php"):
   body=json.dumps({"ok":True,"schema_version":2,"revision":4,"records":RECORDS}).encode();self.send_response(200);self.send_header("Content-Type","application/json");self.send_header("Content-Length",str(len(body)));self.end_headers();self.wfile.write(body);return
  if parsed.path.endswith("/server/trophy-gallery/public/media.php"):
   buffer=BytesIO();Image.new("RGB",(10,8),(200,120,20)).save(buffer,"PNG");body=buffer.getvalue();self.send_response(200);self.send_header("Content-Type","image/png");self.send_header("Content-Length",str(len(body)));self.end_headers();self.wfile.write(body);return
  super().do_GET()

def main():
 if not Path(CHROMIUM).exists():raise RuntimeError("Chromium is required")
 server=ThreadingHTTPServer(("127.0.0.1",0),partial(Handler,directory=str(ROOT)));thread=Thread(target=server.serve_forever,daemon=True);thread.start();origin=f"http://127.0.0.1:{server.server_port}";errors=[];bad=[];console=[];requests=[];failed=[]
 try:
  with sync_playwright() as p:
   browser=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=["--no-sandbox"]);context=browser.new_context();page=context.new_page()
   page.on("pageerror",lambda e:errors.append(str(e)))
   page.on("console",lambda m:console.append(f"{m.type}: {m.text}"))
   page.on("request",lambda r:requests.append(r.url) if r.url.startswith(origin) else None)
   page.on("requestfailed",lambda r:failed.append((r.url,r.failure)) if r.url.startswith(origin) else None)
   page.on("response",lambda r:bad.append((r.status,r.url)) if r.url.startswith(origin) and r.status>=400 else None)
   response=page.goto(origin+"/trophies/",wait_until="commit");assert response and response.status==200
   try:
    page.wait_for_selector(".p2k-trophy-card",state="attached",timeout=5000)
   except PlaywrightTimeoutError:
    diagnostic=page.evaluate("""() => ({readyState:document.readyState, runtime:!!window.P2K_TROPHY_GALLERY_POC, r538:!!window.__P2K_TROPHY_R5FIX38, r5310:!!window.__P2K_TROPHY_R5FIX3_10, host:document.querySelector('#p2kTrophyStandalone')?.innerHTML||'', scripts:[...document.scripts].map(s=>({src:s.src,defer:s.defer})), styles:[...document.styleSheets].map(s=>s.href||'inline')})""")
    print(json.dumps({"standalone_readiness":"failed","diagnostic":diagnostic,"page_errors":errors,"console":console,"failed_requests":failed,"bad_responses":bad,"requests":requests},indent=2))
    raise
   cards=page.locator(".p2k-trophy-card")
   assert cards.count()==3
   assert cards.first.is_visible()
   assert page.locator("[data-group-name='2026'] .p2k-trophy-card").first.get_attribute("data-trophy-id")=="new"
   assert page.locator("[data-group-name='Undated']").count()==1
   page.fill("[data-search]","Older");assert page.locator(".p2k-trophy-card").count()==1
   page.fill("[data-search]","");page.select_option("[data-league]","OWL");assert page.locator(".p2k-trophy-card").count()==2
   page.select_option("[data-league]","all");page.select_option("[data-year]","2025");assert page.locator(".p2k-trophy-card").count()==1
   page.select_option("[data-year]","all");page.select_option("[data-group]","league");assert page.locator("[data-group-name='OWL']").count()==1
   page.locator("[data-trophy-id='new'] [data-open]").click();page.wait_for_selector("#p2kR538Modal:not([hidden])")
   modal_text=page.locator("#p2kR538Modal .p2k-r538-lead p").inner_text()
   assert "**Bold** and *italic*" in modal_text and "- First" in modal_text and "<script>bad()</script>" in modal_text
   assert page.locator("#p2kR538Modal script").count()==0
   assert page.locator("#p2kR538Modal .p2k-r538-links a[href='https://example.test/award']").count()==1
   page.click("#p2kR538Modal .p2k-r538-view-art");page.wait_for_selector("#p2kR538Viewer:not([hidden]) img");page.keyboard.press("Escape");assert page.locator("#p2kR538Viewer").is_hidden()
   assert page.locator("#dashboardAdministrationTab,#hallOfFamePage,[data-admin]").count()==0
   assert page.evaluate("document.cookie")=="" and errors==[] and bad==[] and failed==[]
   result={"http":response.status,"cards":3,"search":True,"league_filter":True,"year_filter":True,"groups":True,"markdown_safe":True,"enlargement":True,"no_chrome":True,"errors":errors,"failed_assets":bad};browser.close()
 finally:server.shutdown();server.server_close();thread.join(timeout=5)
 print(json.dumps({"trophy_gallery_standalone":"passed",**result},indent=2))

if __name__=="__main__":
 # The r5fix3.8 modal/enlargement assertions qualify the legacy installed overlay.
 # The replacement v2.12.1 public/admin runtime has its own current browser gate.
 from browser_gate_trophy_gallery_overlay import production_tree
 with production_tree() as ROOT:
  main()
