#!/usr/bin/env python3
"""Pixel-compare exact POC 1.9.1 with the externalized integrated editor."""
from __future__ import annotations
from io import BytesIO
import json, os, shutil
from pathlib import Path
from PIL import Image, ImageChops, ImageStat
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
REFERENCE=ROOT/"tools/poc/engraving/reference/P2K_1WL_Engraving_POC_v1.9.1.html"
EDITOR=ROOT/"assets/trophy-gallery/engraving/editor.html"
CHROMIUM=os.environ.get("P2K_CHROMIUM") or shutil.which("chromium") or "/usr/bin/chromium"

def capture(page,path:Path,kind:str,finish:str)->bytes:
    page.goto(path.as_uri(),wait_until="load")
    page.wait_for_function("document.fonts.status === 'loaded' && document.getElementById('infoSize').textContent.includes('×')")
    page.select_option("#awardType",kind);page.select_option("#finish",finish)
    page.wait_for_timeout(250)
    return page.locator("#canvas").screenshot(type="png")

def main():
    assert REFERENCE.is_file() and EDITOR.is_file()
    results=[]
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=["--no-sandbox","--allow-file-access-from-files"])
        page=browser.new_page(device_scale_factor=1)
        for kind,finish in (("medal","gold"),("cup","silver"),("crystal","bronze")):
            a=Image.open(BytesIO(capture(page,REFERENCE,kind,finish))).convert("RGBA")
            b=Image.open(BytesIO(capture(page,EDITOR,kind,finish))).convert("RGBA")
            assert a.size==b.size,(kind,a.size,b.size)
            diff=ImageChops.difference(a,b);stat=ImageStat.Stat(diff)
            mean=sum(stat.mean)/4;changed=sum(1 for px in diff.getdata() if px!=(0,0,0,0))/max(1,a.width*a.height)
            assert mean<=0.15 and changed<=0.002,(kind,finish,mean,changed)
            results.append({"kind":kind,"finish":finish,"mean_delta":mean,"changed_fraction":changed})
        browser.close()
    print(json.dumps({"engraving_equivalence":"passed","comparisons":results},indent=2))

if __name__=="__main__":main()
