"""Headless regression for P2K Team > Widgets and six 260px public embeds."""
import contextlib
import http.server
import json
import socketserver
import threading
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
FIX={
 'club-pulse':{'enabled':True,'title':'Club Pulse','period':'7d','theme':'dark','showMembers':True},
 'recruitment-radar':{'enabled':True,'title':'Recruitment Radar','window':'48h','limit':4,'theme':'dark'},
 'trophy-spotlight':{'enabled':True,'title':'Trophy Spotlight','limit':5,'mode':'latest','theme':'dark'},
 'rivalry-spotlight':{'enabled':True,'title':'Rivalry Spotlight','limit':6,'opponent':'','theme':'dark'},
 'club-progress':{'enabled':True,'title':'Club Progress','metric':'points','period':'30d','theme':'dark'},
 'around-the-world':{'enabled':True,'title':'P2K Around the World','metric':'matches','limit':8,'theme':'dark'}
}
now=1794500000
class Handler(http.server.SimpleHTTPRequestHandler):
 def __init__(self,*args,**kwargs):super().__init__(*args,directory=str(ROOT),**kwargs)
 def log_message(self,*args):pass
class Server(socketserver.ThreadingTCPServer): allow_reuse_address=True

sources={
 'team':{'ok':True,'team':{'club_points':402830,'finished_matches':12345,'finished_boards':321000,'current_members':987}},
 'insights':{'ok':True,'data':{'graphs':{'scoreProgression':{'actual':[{'date':f'2026-10-{i:02d}','value':380000+i*100} for i in range(1,31)]},'dailyBoards':[{'date':f'2026-10-{i:02d}','finished':i,'active':20} for i in range(1,31)]}}},
 'catalog':{'ok':True,'matches':[{'matchId':'1','name':'WCGC Match','opponentName':'Chess Friend','startTime':1794520000,'url':'https://www.chess.com/club/matches/1','category':'league'}]},
 'trophies':{'ok':True,'records':[{'id':'a','title':'Test Trophy','award_date':'2026-10-20','league':'TCMAC','vignette_url':'','award_page':'https://www.chess.com/'},{'id':'b','title':'Another Trophy','award_date':'2026-10-10','league':'PCL'}]},
 'opponents':{'ok':True,'rows':[{'slug':'op1','name':'Rival Club','total':34,'wins':12,'draws':10,'losses':11,'ongoing':1,'registered':0,'url':'https://www.chess.com/club/rival-club'}]},
 'countries':{'ok':True,'countries':[{'country':'US','clubs':20,'matches':44},{'country':'FR','clubs':12,'matches':30}], 'unclassified':2}
}

def route(req):
 url=req.request.url
 if 'action=admin-state' in url:
  obj={'ok':True,'revision':0,'widgets':FIX}
 elif 'action=config' in url:
  from urllib.parse import urlparse, parse_qs
  widget=parse_qs(urlparse(url).query)['widget'][0];obj={'ok':True,'settings':FIX[widget]}
 elif 'action=countries' in url:obj=sources['countries']
 elif 'action=team' in url or 'public.php' in url:obj=sources['team']
 elif 'team-insights.php' in url:obj=sources['insights']
 elif 'action=catalog' in url:obj=sources['catalog']
 elif 'trophy-gallery/public/api.php' in url:obj=sources['trophies']
 elif 'opponents.php' in url:obj=sources['opponents']
 else:obj={'ok':False,'error':{'message':'Unknown fixture '+url}}
 req.fulfill(status=200,content_type='application/json',body=json.dumps(obj))


html_template="""<!doctype html><html><head><meta name='viewport' content='width=device-width'></head><body data-widget='WIDGET' data-preview='0'><main class='p2kw-root'><section class='p2kw-panel'><header class='p2kw-head'><h1 id='p2kwTitle'></h1><span id='p2kwStatus'></span></header><div id='p2kwContent'></div><footer id='p2kwFoot'></footer></section></main></body></html>"""
source=(ROOT/'assets/js/widgets/p2k-embed-v2153.js').read_text().replace('document.currentScript.src', "'https://www.promotetoking.org/assets/js/widgets/p2k-embed-v2153.js'")
admin_source=(ROOT/'assets/js/admin/widgets-v2153.js').read_text().replace('document.currentScript?.src||location.href', "'https://www.promotetoking.org/assets/js/admin/widgets-v2153.js'")
js_css=(ROOT/'assets/css/widgets/p2k-embed-v2153.css').read_text()
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
 page=browser.new_page(viewport={'width':260,'height':850})
 errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 fixtures={'config':FIX,**sources}
 for widget in FIX:
  page.set_content(html_template.replace('WIDGET',widget))
  page.add_style_tag(content=js_css)
  page.evaluate("""([widget,fx])=>{window.fetch=async u=>{let url=String(u);let obj;
   if(url.includes('action=config'))obj={ok:true,settings:fx.config[widget]};
   else if(url.includes('action=countries'))obj=fx.countries;
   else if(url.includes('team-insights.php'))obj=fx.insights;
   else if(url.includes('action=catalog'))obj=fx.catalog;
   else if(url.includes('trophy-gallery'))obj=fx.trophies;
   else if(url.includes('opponents.php'))obj=fx.opponents;
   else if(url.includes('public.php'))obj=fx.team;
   else throw Error('Unknown request: '+url);
   return {ok:true,json:async()=>obj,status:200};
  };} """,[widget,fixtures])
  page.add_script_tag(content=source)
  page.locator('#p2kwStatus').filter(has_text='LIVE').wait_for(timeout=6000)
  assert page.evaluate('document.documentElement.scrollWidth <= 260'),widget+' overflow'
  assert page.locator('#p2kwContent').inner_text().strip(),widget+' empty'
  print('PASS',widget,'260 px, live fixture')
 assert not errors,errors
 manager=browser.new_page(viewport={'width':1100,'height':750})
 manager.set_content('<html><body><div id="adminShellNativeDetailHost" data-native-detail="p2k-widget-club-pulse"></div></body></html>')
 manager.evaluate("""fx=>{window.fetch=async u=>({ok:true,status:200,json:async()=>({ok:true,revision:0,widgets:fx.config})});window.P2K_TEAM_POINTS_CLIENT={connect:async()=>{},endpointRequest:async()=>({ok:true,revision:1,widgets:fx.config})};}""",fixtures)
 manager.add_script_tag(content=admin_source)
 manager.wait_for_selector('[data-widgets-form]')
 assert manager.locator('[data-widgets-code]').input_value().count('<iframe')==1
 manager.locator('[data-setting=title]').fill('New Title')
 assert manager.locator('[data-widgets-form] button[type=submit]').is_enabled()
 manager.locator('[data-preview]').click()
 assert manager.locator('[data-preview-wrap]').is_visible()
 print('PASS native manager settings, iframe snippet and on-demand preview')
 browser.close()
