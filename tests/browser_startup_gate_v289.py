#!/usr/bin/env python3
"""v2.8.9 browser execution gate.

This is intentionally separate from pytest so a deployment machine does not need
Playwright. Release packaging runs it when Chromium + Playwright are available.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright
import json, re, sys

ROOT = Path(__file__).resolve().parents[1]
CHROMIUM = "/usr/bin/chromium"

def clean_ui_html():
    html=(ROOT/'ui-v2.html').read_text(encoding='utf-8',errors='ignore')
    html=re.sub(r'<script\b[^>]*>.*?</script>','',html,flags=re.I|re.S)
    html=re.sub(r'<meta[^>]+http-equiv=["\']Content-Security-Policy["\'][^>]*>','',html,flags=re.I)
    # Keep frame nodes but prevent test-network navigation.
    html=re.sub(r'\sdata-src="[^"]*"','',html)
    return html

SAFE_URL = r'''
const NativeURL = window.URL;
function SafeURL(input, base) {
  const b = (!base || String(base)==='about:blank') ? 'https://p2k.test/ui-v2.html?oauth=1' : base;
  return new NativeURL(input,b);
}
SafeURL.prototype = NativeURL.prototype;
for (const k of ['createObjectURL','revokeObjectURL','canParse']) if (NativeURL[k]) SafeURL[k]=NativeURL[k].bind(NativeURL);
window.URL = SafeURL;
'''

DASH_BOOTSTRAP = SAFE_URL + r'''
window.P2K_SITE_CONFIG = {
  clubSlug:'promote-to-king', clubUrl:'https://www.chess.com/club/promote-to-king',
  auth:{adminUsernames:['Ximoon']}, features:{simulatedOAuth:true},
  serverStorage:{acamrPlanEndpoint:'server/team-points/public/acamr-plan.php'}
};
window.P2K_AUTH = { enabled:true, mode:'simulated', getSession(){return {username:'Ximoon',authMode:'simulated'};} };
window.P2K_TEAM_POINTS_CLIENT = {
  publicRequest: async () => ({team:{current_members:4000,club_points:12345,finished_matches_available:true,finished_matches:100,finished_boards:1000,finished_games:2000}}),
  endpointRequest: async () => ({ok:true,tasks:[]})
};
const fake={data:{},cacheState:'network',transport:'mock'};
window.P2K_API_CLIENT={json:async url=>{
  const s=String(url);
  if(s.includes('/members')) return {...fake,data:{weekly:[],monthly:[],all_time:[]}};
  if(s.endsWith('/matches')) return {...fake,data:{registered:[],in_progress:[],finished:[]}};
  if(s.includes('/pub/club/')) return {...fake,data:{name:'Promote to King',admin:['Ximoon']}};
  return {...fake,data:{}};
}};
window.P2K_PROGRESSIVE={snapshotGet(){return null},snapshotSet(){},afterFirstPaint(fn){setTimeout(fn,0)},afterIdle(){},canPrefetch(){return false},schedule(fn){return Promise.resolve().then(fn)}};
window.fetch=async()=>({ok:true,status:200,json:async()=>({ok:true,team:{}})});
'''

ACAMR_BOOTSTRAP = SAFE_URL + r'''
window.P2K_SITE_CONFIG={features:{simulatedOAuth:true},serverStorage:{acamrPlanEndpoint:'server/team-points/public/acamr-plan.php'}};
window.P2K_AUTH={enabled:true,mode:'simulated',getSession(){return {username:'Ximoon',authMode:'simulated'};}};
window.P2K_TAB_ACTIVITY={isActive(){return true;}};
try{Object.defineProperty(document,'visibilityState',{value:'visible',configurable:true});}catch(_){}
window.__acamrCalls=[];
window.P2K_API_CLIENT={json:async(url,options)=>{window.__acamrCalls.push({url:String(url),source:options?.observationSource||''});return {data:{},url:String(url)};}};
window.fetch=async()=>({ok:true,status:200,json:async()=>({ok:true,claim:{username:'Ximoon',next_cursor:1,tasks:[
  {kind:'stats',url:'https://api.chess.com/pub/player/Ximoon/stats'},
  {kind:'tournament',url:'https://api.chess.com/pub/tournament/forbidden'},
  {kind:'registration',url:'https://api.chess.com/pub/club/promote-to-king/matches'}
]},policy:{pulse_ms:10000}})});
'''

def main():
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=['--no-sandbox','--disable-dev-shm-usage'])
        page=browser.new_page(); errors=[]; page.on('pageerror',lambda e: errors.append(str(e)))
        page.set_content(clean_ui_html(),wait_until='domcontentloaded')
        page.add_script_tag(content=DASH_BOOTSTRAP)
        page.add_script_tag(path=str(ROOT/'assets/js/pages/dashboard-v2.js'))
        page.wait_for_timeout(350)
        dashboard=page.evaluate('''() => ({
          status:document.getElementById('teamStatusBadge')?.textContent||'',
          members:document.getElementById('teamMembers')?.textContent||'',
          adminMode:window.P2K_ADMIN_MODE===true,
          adminTabHidden:document.getElementById('dashboardAdministrationTab')?.hidden??null
        })''')
        if page.locator('#dashboardAdministrationTab').count():
            page.locator('#dashboardAdministrationTab').click()
            page.wait_for_timeout(80)
        administration=page.evaluate("() => ({hidden:document.getElementById('administrationPage')?.hidden??null, upcomingHidden:document.querySelector('[data-admin-panel=\"upcoming\"]')?.hidden??null})")
        if errors or dashboard['status']!='Database ready' or dashboard['members']!='4,000' or not dashboard['adminMode'] or dashboard['adminTabHidden'] or administration['hidden']:
            raise AssertionError(json.dumps({'errors':errors,'dashboard':dashboard,'administration':administration},indent=2))

        ac=browser.new_page(); ac_errors=[]; ac.on('pageerror',lambda e: ac_errors.append(str(e)))
        ac.set_content('<!doctype html><html><body></body></html>')
        ac.add_script_tag(content=ACAMR_BOOTSTRAP)
        ac.add_script_tag(path=str(ROOT/'assets/js/shared/authenticated-member-refresh.js'))
        ac.wait_for_timeout(1500)
        acamr=ac.evaluate("() => ({active:window.P2K_ACAMR?.active?.()===true,calls:window.__acamrCalls||[]})")
        if ac_errors or not acamr['active'] or len(acamr['calls'])!=1 or '/stats' not in acamr['calls'][0]['url'] or acamr['calls'][0]['source']!='acamr':
            raise AssertionError(json.dumps({'errors':ac_errors,'acamr':acamr},indent=2))
        browser.close()
        print(json.dumps({'dashboard':dashboard,'administration':administration,'acamr':acamr,'pageErrors':0},indent=2))

if __name__=='__main__': main()
