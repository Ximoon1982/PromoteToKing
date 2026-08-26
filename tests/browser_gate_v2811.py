#!/usr/bin/env python3
from pathlib import Path
import json,re
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
CHROMIUM='/usr/bin/chromium'

def inline_scripts(name):
    html=(ROOT/name).read_text(encoding='utf-8',errors='ignore')
    return [m.group('body') for m in re.finditer(r'<script(?P<attrs>[^>]*)>(?P<body>.*?)</script>',html,re.I|re.S) if 'src=' not in m.group('attrs').lower() and m.group('body').strip()]

def html_without_scripts(name):
    html=(ROOT/name).read_text(encoding='utf-8',errors='ignore')
    return re.sub(r'<script\b[^>]*>.*?</script>','',html,flags=re.I|re.S)

DATA={
 'summary':{'ok':True,'data':{'coverage':{'start':'2026-08-01','end':'2026-08-10'},'range':{'start':'2026-08-01','end':'2026-08-10'},'summary':{'matches_started':12,'matches_finished':9,'boards':140,'club_points':320,'unique_players':40,'games':180},'comparison':{},'graphs':{'cumulativeActivity':[{'date':f'2026-08-{d:02d}','started':d,'finished':max(0,d-2),'inProgress':2} for d in range(1,11)]},'meta':{'schema_version':5}}},
 'progression':{'ok':True,'data':{'graphs':{
   'scoreProgression':{'today':'2026-08-09','actual':[{'date':f'2026-08-{d:02d}','value':100+d*10} for d in range(1,10)],'forecast':{'start_date':'2026-08-09','low':[{'date':f'2026-08-{d:02d}','value':180+d*4} for d in range(9,11)],'medium':[{'date':f'2026-08-{d:02d}','value':190+d*4} for d in range(9,11)],'high':[{'date':f'2026-08-{d:02d}','value':200+d*4} for d in range(9,11)],'end_values':{'low':220,'medium':230,'high':240}}},
   'yearlyComparison':[],
   'dailyBoards':[{'date':f'2026-08-{d:02d}','started':d*2,'finished':d,'club_points':d*5} for d in range(1,11)]
 }}},
 'mid':{'ok':True,'data':{'graphs':{'outcomes':{'win':4,'draw':2,'loss':3},'boardSizes':[{'label':'1–9','count':3},{'label':'10–24','count':6}],'monthlyPoints':[{'month':'2026-08','value':320}]}}},
 'deep':{'ok':True,'data':{'graphs':{'rolling30':[{'date':f'2026-08-{d:02d}','boards':d*3,'club_points':d*7} for d in range(1,11)],'monthlyUniquePlayers':[{'month':'2026-08','value':40}]},'forecast':{'today':'2026-08-09'},'concentration':[]}}
}

STUB='''
window.ResizeObserver=class{constructor(cb){this.cb=cb}observe(){}};
window.parent=window;window.P2K_PROGRESSIVE={snapshotGet(){return null},snapshotSet(){},observe(el,fn){setTimeout(fn,0);return()=>{}}};
window.fetch=async url=>{const u=String(url),m=/[?&]section=([^&]+)/.exec(u),section=m?decodeURIComponent(m[1]):'summary';const payload=window.__DATA[section]||window.__DATA.summary;return new Response(JSON.stringify(payload),{status:200,headers:{'Content-Type':'application/json'}})};
'''

SYNTH_PINCH='''(el)=>{const ev=(type,touches)=>{const e=new Event(type,{bubbles:true,cancelable:true});Object.defineProperty(e,'touches',{value:touches});el.dispatchEvent(e)};const t=(x,y)=>({clientX:x,clientY:y});ev('touchstart',[t(100,120),t(250,120)]);ev('touchmove',[t(50,120),t(310,120)]);ev('touchend',[]);}'''

def main():
  with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=['--no-sandbox','--disable-dev-shm-usage'])
    page=browser.new_page(viewport={'width':390,'height':844},has_touch=True,is_mobile=True)
    errs=[];page.on('pageerror',lambda e:errs.append(str(e)))
    page.set_content(html_without_scripts('TeamInsights.html'),wait_until='domcontentloaded')
    page.evaluate('(d)=>window.__DATA=d',DATA)
    page.add_script_tag(content=STUB)
    page.add_script_tag(content=inline_scripts('TeamInsights.html')[-1])
    page.wait_for_timeout(450)
    # all sections load immediately via mocked progressive observer
    metrics=page.evaluate('''() => {
      const ids=['activity','scoreProgression','boards','outcomes','sizes','points','rolling','unique'];
      const boxes=Object.fromEntries(ids.map(id=>{const e=document.getElementById(id),r=e.getBoundingClientRect(),p=e.closest('.card')?.getBoundingClientRect();return[id,{w:r.width,scroll:e.scrollWidth,parent:p?.width||0,svg:e.querySelector('svg')?.getBoundingClientRect().width||0}]}));
      return {boxes, future:!!document.querySelector('#scoreProgression .future-zone'), legend:[...document.querySelectorAll('#boardsLegend button')].map(x=>x.textContent.trim()), errors:document.getElementById('error')?.textContent||''};
    }''')
    for id,b in metrics['boxes'].items():
      if b['w']>b['parent']+1 or b['scroll']>b['w']+2 or b['svg']>b['w']+1: raise AssertionError(f'chart spill {id}: {b}')
    if not metrics['future'] or len(metrics['legend'])!=3: raise AssertionError(json.dumps(metrics,indent=2))
    # tap tooltip on mobile
    overlay=page.locator('#boards svg rect[fill="transparent"]').last
    overlay.click(position={'x':120,'y':80}); page.wait_for_timeout(50)
    tip=page.locator('#boards .tooltip')
    if tip.count()!=1 or tip.evaluate("e=>getComputedStyle(e).display")=='none': raise AssertionError('mobile tap tooltip did not open')
    # hide one series
    before=page.locator('#boards path.line').count(); page.locator('#boardsLegend button').last.click();page.wait_for_timeout(60);after=page.locator('#boards path.line').count()
    if not after<before: raise AssertionError(f'series toggle failed {before}->{after}')
    # pinch zoom changes visible x labels
    labels_before=page.locator('#boards text.axis').all_text_contents()
    overlay=page.locator('#boards svg rect[fill="transparent"]').last
    overlay.evaluate(SYNTH_PINCH);page.wait_for_timeout(80)
    labels_after=page.locator('#boards text.axis').all_text_contents()
    if labels_before==labels_after: raise AssertionError('pinch zoom did not change visible range')

    # Native opponent chart: readable numbers and logo after name, no spill.
    opp=browser.new_page(viewport={'width':390,'height':844},has_touch=True,is_mobile=True);oe=[];opp.on('pageerror',lambda e:oe.append(str(e)))
    css=(ROOT/'assets/css/dashboard-v2.css').read_text(encoding='utf-8',errors='ignore')
    opp.set_content(f'<html><head><style>{css}</style></head><body><div id="opponentsTopChart" class="p2k-native-chart" style="width:370px"></div></body></html>')
    opp.add_script_tag(content="window.__opened=[];window.P2K_CREATE_INSIGHTS_CHARTS=undefined;")
    opp.add_script_tag(path=str(ROOT/'assets/js/pages/dashboard-insights-charts.js'))
    opp.evaluate('''() => {const charts=window.P2K_CREATE_INSIGHTS_CHARTS({byId:id=>document.getElementById(id),number:v=>String(v),escapeHTML:v=>String(v),openOpponentProfile:s=>window.__opened.push(s)});charts.renderOpponentTopChart([{name:'Мрія АН-225 Cossack',slug:'mriya-an-225-cossack',icon:'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"/>',total:42,wins:20,draws:10,losses:12,ongoing:2}]);}''')
    om=opp.evaluate('''() => {const h=document.getElementById('opponentsTopChart'),svg=h.querySelector('svg'),name=h.querySelector('.p2k-chart-opponent-name'),logo=h.querySelector('.p2k-opponent-logo'),value=h.querySelector('.p2k-chart-value'),r=h.getBoundingClientRect(),sr=svg.getBoundingClientRect();return {spill:sr.width-r.width,name:name.textContent,logoX:Number(logo.getAttribute('x')),nameX:Number(name.getAttribute('x')),valueFill:getComputedStyle(value).fill,nameFill:getComputedStyle(name).fill}}''')
    if oe or om['spill']>1 or om['logoX']<=om['nameX'] or om['valueFill'] in ('rgb(0, 0, 0)','black') or om['nameFill'] in ('rgb(0, 0, 0)','black'): raise AssertionError(json.dumps({'errors':oe,'opponent':om},ensure_ascii=False,indent=2))
    browser.close()
    if errs: raise AssertionError(json.dumps(errs,indent=2))
    print(json.dumps({'teamInsights':metrics,'pinchChanged':labels_before!=labels_after,'seriesLines':[before,after],'opponent':om,'pageErrors':0},ensure_ascii=False,indent=2))

if __name__=='__main__': main()
