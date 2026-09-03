#!/usr/bin/env python3
"""v2.11.2 homepage runtime gate for modular Dashboard dependencies.
This is a hard (non-debt) browser gate for the authenticated-admin startup path
and Match Assistant recommendation rendering that regressed during extraction.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright
import json, os, re, shutil
ROOT = Path(__file__).resolve().parents[1]
CHROMIUM = os.environ.get("P2K_CHROMIUM") or shutil.which("chromium")
MODULES = [
    "assets/js/admin/admin-shell.js",
    "assets/js/admin/admin-session-controller.js",
    "assets/js/admin/embedded-detail-host.js",
    "assets/js/admin/tool-registry.js",
    "assets/js/dashboard/personal-home.js",
    "assets/js/dashboard/insights-controller.js",
    "assets/js/dashboard/team-summary.js",
    "assets/js/dashboard/match-assistant.js",
    "assets/js/dashboard/match-list-dialog.js",
    "assets/js/dashboard/dashboard-bootstrap.js",
    "assets/js/pages/dashboard-v2.js",
]
def clean_ui_html():
    html = (ROOT / "ui-v2.html").read_text(encoding="utf-8", errors="ignore")
    html = re.sub(r"<script\b[^>]*>.*?</script>", "", html, flags=re.I | re.S)
    html = re.sub(r"<meta[^>]+http-equiv=[\"']Content-Security-Policy[\"'][^>]*>", "", html, flags=re.I)
    html = re.sub(r'\sdata-src="[^"]*"', "", html)
    return html
BOOTSTRAP = r'''
const NativeURL = window.URL;
function SafeURL(input, base) {
  const fallback = 'https://p2k.test/ui-v2.html?oauth=1&name=Ximoon';
  try { return base ? new NativeURL(input, base) : new NativeURL(input, fallback); }
  catch (_) { return new NativeURL(input, fallback); }
}
SafeURL.prototype = NativeURL.prototype;
for (const k of ['createObjectURL','revokeObjectURL','canParse']) if (NativeURL[k]) SafeURL[k]=NativeURL[k].bind(NativeURL);
window.URL = SafeURL;
window.P2K_SITE_CONFIG = {
  clubSlug:'promote-to-king', clubUrl:'https://www.chess.com/club/promote-to-king',
  auth:{adminUsernames:['Ximoon']}, features:{simulatedOAuth:true},
  serverStorage:{acamrPlanEndpoint:'server/team-points/public/acamr-plan.php'}
};
window.P2K_AUTH = {
  enabled:true, mode:'simulated',
  getSession(){return {username:'Ximoon',authMode:'simulated'};},
  getDisplaySession(){return {username:'Ximoon',authMode:'simulated'};},
  getDisplayUsername(){return 'Ximoon';}
};
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
def main():
    if not CHROMIUM:
        raise RuntimeError("Chromium is required for the v2.11.2 homepage runtime gate")
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, executable_path=CHROMIUM, args=["--no-sandbox", "--disable-dev-shm-usage"])
        page = browser.new_page()
        errors = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.set_content(clean_ui_html(), wait_until="domcontentloaded")
        page.add_script_tag(content=BOOTSTRAP)
        for relative in MODULES:
            page.add_script_tag(path=str(ROOT / relative))
        page.wait_for_timeout(700)
        dashboard = page.evaluate("""() => ({
          status:document.getElementById('teamStatusBadge')?.textContent||'',
          members:document.getElementById('teamMembers')?.textContent||'',
          adminMode:window.P2K_ADMIN_MODE===true,
          adminTabHidden:document.getElementById('dashboardAdministrationTab')?.hidden??null,
          publicPressed:document.querySelector('[data-dashboard-view="public"]')?.getAttribute('aria-pressed')||'',
          adminPressed:document.querySelector('[data-dashboard-view="admin"]')?.getAttribute('aria-pressed')||'',
          priorityCard:!!document.getElementById('dashboardAdminPriorityCard'),
          adminHostChildren:document.getElementById('adminDashboardHost')?.children.length||0
        })""")
        page.wait_for_function("document.querySelector('iframe.dashboard-recommendation-engine')")
        page.evaluate("""() => {
          const frame=document.querySelector('iframe.dashboard-recommendation-engine');
          const data={type:'p2k-dashboard-recommendations',username:'Ximoon',terminal:true,recommendations:[{
            name:'Test recommended match',url:'https://www.chess.com/club/matches/test',league:'PCL',score:91,scoreLabel:'Recommended',ratingRange:'1200 - 1400',rules:'Daily',startTime:'2026-09-05T12:00:00Z',priority:true,reasons:['Good rating fit.']
          }],teamIndicators:{lineupReadiness:80,registrationTargets:70,startingSoon:1,priorityCalls:1},adminQueue:{metrics:{underfilled:1,starts48:1,leagueRecruitment:1,recruitsAdvised:2},queue:[],generatedAt:'2026-09-03T08:00:00Z'},failedCount:0};
          window.dispatchEvent(new MessageEvent('message',{data,origin:window.location.origin,source:frame.contentWindow}));
        }""")
        page.wait_for_timeout(80)
        recommendations = page.evaluate("""() => ({
          count:document.querySelectorAll('#recommendationsList .dashboard-recommendation').length,
          title:document.querySelector('#recommendationsList .dashboard-recommendation-title')?.textContent||''
        })""")
        page.locator('[data-dashboard-view="admin"]').click()
        page.wait_for_timeout(80)
        administration = page.evaluate("""() => ({
          hidden:document.getElementById('adminDashboardHost')?.hidden??null,
          categoryCount:document.querySelectorAll('#adminDashboardHost [data-admin-category]').length,
          cardCount:document.querySelectorAll('#adminDashboardHost [data-admin-shell-card]').length,
          publicPressed:document.querySelector('[data-dashboard-view="public"]')?.getAttribute('aria-pressed')||'',
          adminPressed:document.querySelector('[data-dashboard-view="admin"]')?.getAttribute('aria-pressed')||''
        })""")
        browser.close()
    result = {"errors": errors, "dashboard": dashboard, "recommendations": recommendations, "administration": administration}
    if (
        errors
        or dashboard["status"] != "Database ready"
        or dashboard["members"] != "4,000"
        or not dashboard["adminMode"]
        or dashboard["adminTabHidden"]
        or dashboard["publicPressed"] != "true"
        or dashboard["adminPressed"] != "false"
        or not dashboard["priorityCard"]
        or dashboard["adminHostChildren"] < 1
        or recommendations["count"] != 1
        or recommendations["title"] != "Test recommended match"
        or administration["hidden"]
        or administration["categoryCount"] != 6
        or administration["cardCount"] < 1
        or administration["publicPressed"] != "false"
        or administration["adminPressed"] != "true"
    ):
        raise AssertionError(json.dumps(result, indent=2))
    print(json.dumps(result, indent=2))
if __name__ == "__main__":
    main()
