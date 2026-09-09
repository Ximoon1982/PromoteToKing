#!/usr/bin/env python3
"""Executable r5 public/admin/runtime contract in Chromium."""
from __future__ import annotations
import json, os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
CHROMIUM=os.environ.get("P2K_CHROMIUM") or shutil.which("chromium") or "/usr/bin/chromium"
RECORD={"id":"browser-trophy","status":"published","league":"OWL","competition":"Cup","award":"Gold","title":"Browser Trophy","award_date":"2026-09-09","description_md":"**Persistent** trophy","source_url":"https://www.chess.com/","competition_url":"","award_url":"","vignette_media_id":"","modal_media_id":"","matches":[123],"created_at":"2026-09-09T00:00:00Z","updated_at":"2026-09-09T00:00:00Z"}

def main():
 if not Path(CHROMIUM).exists(): raise RuntimeError("Chromium is required")
 with sync_playwright() as p:
  browser=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=["--no-sandbox"]);page=browser.new_page();errors=[];page.on("pageerror",lambda e:errors.append(str(e)))
  page.set_content('<main id="public"></main><main id="admin"></main>')
  page.route("**/server/trophy-gallery/public/api.php?action=list",lambda r:r.fulfill(status=200,content_type="application/json",body=json.dumps({"ok":True,"records":[RECORD]})))
  page.evaluate("""record => { window.P2K_TEAM_POINTS_CLIENT={endpointRequest:async(_api,o)=>{if(o.action==='admin-list')return {ok:true,records:[record]};if(o.action==='save')return {ok:true,record:{...o.body,id:o.body.id||'new-id'}};if(o.action==='delete')return {ok:true};if(o.action==='audit')return {ok:true,audit:{orphan_count:0}};if(o.action==='match-search')return {ok:true,matches:[{match_id:123,match_name:'Known match',opponent_name:'Opponent'}]};throw Error(o.action)}} }""",RECORD)
  page.add_script_tag(path=str(ROOT/"assets/js/admin/trophy-gallery-poc.js"))
  page.evaluate("Promise.all([P2K_TROPHY_GALLERY_POC.mountPublic(document.getElementById('public')),P2K_TROPHY_GALLERY_POC.mountAdmin(document.getElementById('admin'))])")
  page.wait_for_selector("#public .p2k-trophy-card");page.click("#public [data-open]");page.wait_for_selector("#p2kTrophyModal:not([hidden])")
  assert page.locator("#public .p2k-trophy-card").count()==1
  assert page.locator("#admin form").count()==1 and page.locator("#admin [data-engrave]").count()==2
  page.fill("#admin [data-match-search]","Known");page.wait_for_selector("#admin [data-link='123']")
  assert errors==[],errors
  result={"public_published":1,"admin_crud_form":True,"match_search":True,"engraving_slots":2,"errors":errors};browser.close()
 print(json.dumps({"trophy_gallery_r5_browser":"passed",**result},indent=2))

if __name__=="__main__": main()
