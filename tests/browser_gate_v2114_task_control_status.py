#!/usr/bin/env python3
"""Render the v2.11.4 read-only stale invocation status through Task Control."""

from pathlib import Path
from playwright.sync_api import sync_playwright
import json, os, re, shutil

ROOT=Path(__file__).resolve().parents[1]
CHROMIUM=os.environ.get("P2K_CHROMIUM") or shutil.which("chromium")


def clean_html() -> str:
    html=(ROOT/"TaskControl.html").read_text(encoding="utf-8",errors="ignore")
    html=re.sub(r"<script\b[^>]*>.*?</script>","",html,flags=re.I|re.S)
    return re.sub(r'<meta[^>]+http-equiv=["\']Content-Security-Policy["\'][^>]*>',"",html,flags=re.I)


BOOTSTRAP=r'''
const NativeURL=window.URL;
function SafeURL(input,base){return new NativeURL(input,(!base||String(base)==='about:blank')?'https://p2k.test/TaskControl.html?tab=green':base)}
SafeURL.prototype=NativeURL.prototype;window.URL=SafeURL;
document.documentElement.classList.remove('admin-access-pending');history.replaceState=()=>{};history.pushState=()=>{};
window.P2K_ADMIN_ACCESS_READY=Promise.resolve(true);
window.P2K_AUTH={getSession(){return {username:'Ximoon'}}};window.P2K_ADMIN_USERNAME='Ximoon';
const invocation={invocation_id:77,cycle_no:12,stage_start:'quick_matches',stage_finish:'quick_matches',status:'running',operational_status:'stale_running',started_at:'2026-09-04 08:00:00',runtime_ms:0,request_count:0,summary_json:'{}'};
const green={ok:true,effective_public_source:'green',read_cutover_allowed:true,read_cutover_ready:true,compatibility:{parity:{ready:true,state:'complete'}},green:{state:{cycle_no:12,mode:'quick',stage:'quick_matches',migration_phase:'green_primary',worker_target:'green',client_ingest_target:'green'},progress:{},integrity:{},gqac:{},gab:{status:'running',percent:89.9,lanes:[],convergence:{}},gffl:{},heatmap_backfill:{},cycle_durations:{},recent_invocations:[invocation],phase_progress:[]}};
window.P2K_TEAM_POINTS_CLIENT={
 connect:async()=>({username:'Ximoon',schema_version:17}),
 endpointRequest:async(url,opt={})=>String(url).includes('team-points-green')?green:{ok:true,gateway:{health_status:'healthy'},tasks:[]}
};
'''


def main() -> None:
    if not CHROMIUM: raise RuntimeError("Chromium is required for the v2.11.4 Task Control gate")
    with sync_playwright() as playwright:
        browser=playwright.chromium.launch(headless=True,executable_path=CHROMIUM,args=["--no-sandbox","--disable-dev-shm-usage"])
        page=browser.new_page();errors=[];page.on("pageerror",lambda error:errors.append(str(error)))
        page.set_content(clean_html(),wait_until="domcontentloaded")
        page.add_script_tag(content=BOOTSTRAP)
        page.add_script_tag(path=str(ROOT/"assets/js/pages/task-control.js"))
        page.wait_for_function("document.querySelector('#greenInvocationRows td:nth-child(4)')?.textContent === 'stale_running'")
        result=page.evaluate("""() => ({
          rowStatus:document.querySelector('#greenInvocationRows td:nth-child(4)')?.textContent||'',
          headline:document.getElementById('greenSchedulerMetrics')?.textContent||'',
          adapter:document.getElementById('greenSchedulerMetrics')?.textContent||''
        })""")
        browser.close()
    if errors or result["rowStatus"]!="stale_running" or "#77 · stale_running" not in result["headline"] or "parity complete" not in result["adapter"]:
        raise AssertionError(json.dumps({"errors":errors,"result":result},indent=2))
    print(json.dumps({"task_control_stale_running":"passed","page_errors":0},indent=2))


if __name__=="__main__": main()
