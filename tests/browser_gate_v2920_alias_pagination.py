#!/usr/bin/env python3
"""v2.9.20 browser gate: 25-row Club Intelligence pagination and embedded MIAC modal visibility."""
from pathlib import Path
import json
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
CHROMIUM = '/usr/bin/chromium'


def main():
    js = (ROOT/'assets/js/pages/club-intelligence.js').read_text(encoding='utf-8', errors='ignore')
    css = (ROOT/'assets/css/club-intelligence.css').read_text(encoding='utf-8', errors='ignore')
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, executable_path=CHROMIUM, args=['--no-sandbox','--disable-dev-shm-usage'])
        page = browser.new_page(viewport={'width':1100,'height':720})
        errors=[]; page.on('pageerror', lambda e: errors.append(str(e)))
        # Same-origin parent + tall iframe: scroll parent so the table is partly above viewport,
        # then open an evidence modal and require it to remain in the visible viewport slice.
        page.set_content('''<!doctype html><html><body style="margin:0;height:2400px;background:#111">
          <div style="height:900px"></div><iframe id="ci" style="display:block;width:100%;height:1200px;border:0"></iframe>
        </body></html>''')
        frame_el=page.locator('#ci').element_handle(); frame=frame_el.content_frame()
        frame.set_content(f'''<!doctype html><html><head><style>{css}</style></head><body>
          <div id="ciStatus"></div><button id="ciRefresh"></button><div id="ciTabs"></div><main id="ciContent"></main>
        </body></html>''')
        frame.add_script_tag(content='''
          const NativeUSP=window.URLSearchParams; window.URLSearchParams=class extends NativeUSP{constructor(arg){super(arg===location.search&&arg===''?'?tab=aliases':arg)}};
          window.P2K_ADMIN_ACCESS_READY=Promise.resolve(true);
          window.P2K_EMBEDDED_PAGE={notifyHeight(){}};
          const edges=Array.from({length:61},(_,i)=>({old_username_key:`old${i}`,new_username_key:`new${i}`,status:'candidate',confidence:'Strong',shared_boards:i%3,same_joined:false,roster_handover:false,evidence:{sample_boards:[`board-${i}`]}}));
          window.fetch=async()=>new Response(JSON.stringify({ok:true,data:{seed_summary:{chains:0},edge_counts:{candidate:61},generation:1,edges,chains:[]}}),{status:200,headers:{'Content-Type':'application/json'}});
        ''')
        frame.add_script_tag(content=js)
        frame.wait_for_selector('table.ci-table tbody tr')
        # Alias table has 61 rows but exactly 25 visible on page 1.
        first=frame.evaluate('''()=>({all:document.querySelectorAll('table.ci-table tbody tr').length,visible:[...document.querySelectorAll('table.ci-table tbody tr')].filter(r=>!r.hidden).length,pager:document.querySelector('.ci-table-pagination')?.innerText||''})''')
        assert first['all']==61, first
        assert first['visible']==25, first
        assert 'Page 1 / 3' in first['pager'], first
        frame.locator('[data-ci-page="next"]').click(); frame.wait_for_timeout(30)
        second=frame.evaluate('''()=>({visible:[...document.querySelectorAll('table.ci-table tbody tr')].filter(r=>!r.hidden).length,pager:document.querySelector('.ci-table-pagination')?.innerText||'',first:[...document.querySelectorAll('table.ci-table tbody tr')].findIndex(r=>!r.hidden)})''')
        assert second['visible']==25 and second['first']==25 and 'Page 2 / 3' in second['pager'], second
        page.evaluate('window.scrollTo(0,1150)'); page.wait_for_timeout(60)
        # Open a visible row after scroll.
        frame.locator('[data-open-evidence]:visible').first.click(); frame.wait_for_timeout(80)
        modal=frame.evaluate('''()=>{const m=document.getElementById('miacEvidenceModal'),d=m?.querySelector('.miac-modal');const mr=m?.getBoundingClientRect(),dr=d?.getBoundingClientRect();const fr=window.frameElement.getBoundingClientRect();return{hidden:m?.hidden,modal:{top:mr?.top,bottom:mr?.bottom,height:mr?.height,position:getComputedStyle(m).position},dialog:{top:dr?.top,bottom:dr?.bottom},frameParentTop:fr.top,parentHeight:window.parent.innerHeight};}''')
        # iframe top is above parent viewport. Child-modal top should compensate by -frame.top,
        # which maps it into the visible parent viewport instead of the off-screen iframe origin.
        assert modal['hidden'] is False, modal
        assert modal['modal']['position']=='absolute', modal
        parent_dialog_top=modal['frameParentTop']+modal['dialog']['top']
        parent_dialog_bottom=modal['frameParentTop']+modal['dialog']['bottom']
        assert parent_dialog_top >= -8, (modal,parent_dialog_top)
        assert parent_dialog_top < modal['parentHeight'], (modal,parent_dialog_top)
        assert parent_dialog_bottom > 0, (modal,parent_dialog_bottom)
        assert not errors, errors

        # DMBHF runtime: club-index rows without boards are hydrated from detail and
        # totals are derived as boards / boards*2 on the same objects used by callers.
        dpage=browser.new_page(); de=[]; dpage.on('pageerror',lambda e:de.append(str(e)))
        dpage.set_content('<!doctype html><body></body>')
        dpage.add_script_tag(path=str(ROOT/'assets/js/shared/dashboard-match-board-hydration.js'))
        dmbhf=dpage.evaluate('''async()=>{
          const lists={registered:[{'@id':'https://api.chess.com/pub/match/101',name:'R'}],ongoing:[{match_id:202,name:'O'}]};
          const client={processPriority:async(items,worker)=>{const succeeded=[];for(const item of items)succeeded.push({value:await worker(item)});return{succeeded,failures:[]}}};
          const loadJSON=async url=>url.endsWith('/101')?{boards:12,status:'registered'}:{boards:7,status:'in_progress'};
          const h=await P2K_DASHBOARD_MATCH_BOARD_HYDRATION.hydrate(lists,{client,loadJSON});
          return{h,registered:P2K_DASHBOARD_MATCH_BOARD_HYDRATION.totals(lists.registered),ongoing:P2K_DASHBOARD_MATCH_BOARD_HYDRATION.totals(lists.ongoing),rboards:lists.registered[0].boards,oboards:lists.ongoing[0].boards};
        }''')
        assert dmbhf['h']=={'requested':2,'loaded':2,'failed':0},dmbhf
        assert dmbhf['registered']['boards']==12 and dmbhf['registered']['games']==24,dmbhf
        assert dmbhf['ongoing']['boards']==7 and dmbhf['ongoing']['games']==14,dmbhf
        assert dmbhf['rboards']==12 and dmbhf['oboards']==7,dmbhf
        assert not de,de
        print(json.dumps({'page1':first,'page2':second,'modal':modal,'mappedDialogTop':parent_dialog_top,'dmbhf':dmbhf,'pageErrors':0},indent=2))
        browser.close()

if __name__=='__main__': main()
