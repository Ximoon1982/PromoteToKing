#!/usr/bin/env python3
from pathlib import Path
from playwright.sync_api import sync_playwright
import json
ROOT=Path(__file__).resolve().parents[1]
CHROMIUM='/usr/bin/chromium'
JS=(ROOT/'assets/js/shared/chart-maximize.js').read_text(encoding='utf-8')
HTML='''<!doctype html><html><head><meta charset="utf-8"><style>
body{margin:0;background:#171513;color:#eee;font-family:Arial}.card{margin:140px 20px 0;width:720px}.chart{width:700px;height:320px;background:#26211d}.legend{display:flex;gap:12px}.legend span{padding:4px}
</style></head><body><article class="card"><h2>Daily boards</h2><div id="chart" class="chart" tabindex="0"></div><div id="legend" class="legend"><span>Boards started</span><span>Boards finished</span><span>Current active boards</span></div></article><script>
window.zoomEvents=0;document.getElementById('chart').addEventListener('wheel',e=>{window.zoomEvents++;e.preventDefault()},{passive:false});
</script></body></html>'''

def main():
  with sync_playwright() as p:
    b=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=['--no-sandbox','--disable-dev-shm-usage'])
    page=b.new_page(viewport={'width':900,'height':620})
    errors=[];page.on('pageerror',lambda e: errors.append(str(e)))
    page.set_content(HTML);page.add_script_tag(content=JS);page.wait_for_timeout(60)
    page.locator('.p2k-global-chart-expand').click();page.wait_for_timeout(80)
    state=page.evaluate('''() => { const d=document.querySelector('.p2k-global-chart-dialog').getBoundingClientRect(), c=document.querySelector('#chart').getBoundingClientRect(), l=document.querySelector('#legend'); return {dialog:{left:d.left,top:d.top,right:d.right,bottom:d.bottom},chart:{left:c.left,top:c.top,right:c.right,bottom:c.bottom},vw:innerWidth,vh:innerHeight,legendInModal:!!l.closest('.p2k-global-chart-body'),legendVisible:getComputedStyle(l).display!=='none'} }''')
    page.locator('#chart').dispatch_event('wheel',{'deltaY':-120,'clientX':300,'clientY':250});page.wait_for_timeout(20)
    zoom=page.evaluate('window.zoomEvents')
    assert state['dialog']['left']>=-1 and state['dialog']['top']>=-1 and state['dialog']['right']<=state['vw']+1 and state['dialog']['bottom']<=state['vh']+1,state
    assert state['legendInModal'] and state['legendVisible'],state
    assert zoom==1,(zoom,state)
    page.locator('.p2k-global-chart-close').click();page.wait_for_timeout(30)
    restored=page.evaluate("() => !!document.querySelector('.card > #chart') && !!document.querySelector('.card > #legend')")
    assert restored and not errors,(restored,errors)
    b.close();print(json.dumps({'state':state,'zoomEvents':zoom,'restored':restored,'pageErrors':len(errors)},indent=2))
if __name__=='__main__': main()
