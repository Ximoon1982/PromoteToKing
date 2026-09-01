from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]


def read(path):
    return (ROOT / path).read_text()


def write(path, text):
    (ROOT / path).write_text(text)


def replace_once(path, old, new, label):
    text = read(path)
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected 1 anchor, found {count}")
    write(path, text.replace(old, new, 1))


def sub_once(path, pattern, replacement, label, flags=re.S):
    text = read(path)
    regex = re.compile(pattern, flags)
    updated, count = regex.subn(lambda _m: replacement, text, count=1)
    if count != 1:
        raise SystemExit(f"{label}: expected 1 regex anchor, found {count}")
    write(path, updated)


def dashboard_changes():
    path = "assets/js/pages/dashboard-v2.js"
    text = read(path)

    pattern = re.compile(
        r'(\n\s*members:\s*\{\n'
        r'\s*depth:\s*\{[^\n]+\},\n'
        r'\s*chronology:\s*\{[^\n]+\},\n'
        r'\s*aliases:\s*\{[^\n]+\})(\n\s*\},\n\s*team:)',
        re.S,
    )
    replacement = (
        r'\1,\n'
        '    recruitment: { title:"Recruitment", tabs:[{key:"recruitment",label:"Recruitment",mode:"native",nativeKey:"recruitment"}]}'
        r'\2'
    )
    text, count = pattern.subn(replacement, text, count=1)
    if count != 1:
        raise SystemExit(f"canonical Recruitment detail definition: expected 1 anchor, found {count}")

    card_anchor = '  adminShellCard({key:"aliases",category:"members",eyebrow:"Members",title:"Aliases & name changes",description:"Canonical identity mappings, possible renames and review state.",metrics:[{label:"Known names",id:"adminShellAliasMappings"},{label:"Review queue",id:"adminShellAliasReview"},{label:"Confirmed",id:"adminShellAliasConfirmed"}],source:"MIAC identity graph",links:[{label:"Open aliases",tab:"aliases"}]})'
    if text.count(card_anchor) != 1:
        raise SystemExit(f"canonical Recruitment overview card: expected alias card once, found {text.count(card_anchor)}")
    text = text.replace(
        card_anchor,
        card_anchor + ',\n  adminShellCard({key:"recruitment",category:"members",eyebrow:"Members",title:"Recruitment",description:"Maintain the candidate pool and evaluate prospective members against Daily activity, reliability and membership criteria.",metrics:[{label:"Candidates",id:"adminRecruitmentCandidates"},{label:"Checked",id:"adminRecruitmentChecked"},{label:"Selected",id:"adminRecruitmentSelected"}],source:"Green Core + Chess.com OAuth",links:[{label:"Open Recruitment",tab:"recruitment"}]})',
        1,
    )

    frame_anchor = '<div class="dashboard-admin-detail-frame-wrap"><iframe class="dashboard-integrated-frame dashboard-admin-detail-frame" id="adminShellDetailFrame" title="Administration detail"></iframe></div>'
    if text.count(frame_anchor) != 1:
        raise SystemExit(f"native detail slot: expected frame wrapper once, found {text.count(frame_anchor)}")
    text = text.replace(
        frame_anchor,
        '<div class="dashboard-admin-detail-frame-wrap" id="adminShellDetailFrameWrap"><iframe class="dashboard-integrated-frame dashboard-admin-detail-frame" id="adminShellDetailFrame" title="Administration detail"></iframe></div>\n    <div class="dashboard-admin-detail-native" id="adminShellNativeDetailHost" hidden></div>',
        1,
    )

    render = '''function renderAdminShellDetail(){
  const detailHost=byId("adminShellDetail");if(!detailHost)return;
  const def=adminDetailDefinition();
  const frame=byId("adminShellDetailFrame");
  const frameWrap=byId("adminShellDetailFrameWrap")||frame?.parentElement;
  const nativeHost=byId("adminShellNativeDetailHost");
  if(!def){detailHost.hidden=true;setIntegratedFrameActivity("");if(frameWrap)frameWrap.hidden=false;if(frame)frame.hidden=false;if(nativeHost){nativeHost.hidden=true;nativeHost.replaceChildren();nativeHost.removeAttribute("data-native-detail");}return;}
  const validTab=def.tabs.find(item=>item.key===state.adminDetailTab)||def.tabs[0];state.adminDetailTab=validTab.key;
  detailHost.hidden=false;setText("adminShellDetailTitle",def.title);setText("adminShellDetailBreadcrumb",`Administration · ${state.category.replace("maintenance","Maintenance")}`);
  const tabs=byId("adminShellDetailTabs");if(tabs){tabs.hidden=def.tabs.length<=1;tabs.innerHTML=def.tabs.map(item=>`<a class="dashboard-admin-detail-tab${item.key===validTab.key?" is-active":""}" data-admin-detail-tab="${escapeHTML(item.key)}" href="${escapeHTML(adminShellHref(state.category,state.adminDetail,item.key,item.key===validTab.key?state.adminToolTab:""))}" aria-current="${item.key===validTab.key?"page":"false"}">${escapeHTML(item.label)}</a>`).join("");}
  const nativeKey=String(validTab.nativeKey||"");
  const nativeMode=validTab.mode==="native"||nativeKey!=="";
  if(nativeMode){
    setIntegratedFrameActivity("");
    if(frameWrap)frameWrap.hidden=true;
    if(frame){frame.hidden=true;frame.removeAttribute("data-p2k-r2-stable");}
    if(nativeHost){nativeHost.hidden=false;nativeHost.dataset.nativeDetail=nativeKey||validTab.key;}
    return;
  }
  if(nativeHost){nativeHost.hidden=true;nativeHost.replaceChildren();nativeHost.removeAttribute("data-native-detail");}
  if(frameWrap)frameWrap.hidden=false;
  if(frame){
    frame.hidden=false;
    const targetUrl=applyOAuthContext(new URL(validTab.src,location.href));
    targetUrl.searchParams.set("embedded","1");
    targetUrl.searchParams.set("active","1");
    if(state.adminToolTab&&targetUrl.searchParams.has("tab"))targetUrl.searchParams.set("tab",state.adminToolTab);
    const target=targetUrl.href;
    if(frame.src!==target){
      setIntegratedFrameActivity("");
      frame.dataset.p2kLoaded="0";frame.dataset.p2kRetried="0";frame.style.height="";
      frame.title=`${def.title} — ${validTab.label}`;frame.src=target;
    }
    ensureIntegratedFrame("adminShellDetailFrame");
  }
}
'''
    text, count = re.subn(r'function renderAdminShellDetail\(\)\{.*?\n\}\n(?=function adminShellActivate\(\)\{)', lambda _m: render, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit("canonical native detail renderer anchor not found")

    activate = '''function adminShellActivate(){
  const def=adminDetailDefinition();
  const detail=Boolean(def);
  document.querySelectorAll("[data-admin-shell-panel]").forEach(panel=>panel.hidden=detail||panel.dataset.adminShellPanel!==state.category);
  document.querySelectorAll("[data-admin-category]").forEach(button=>{const active=button.dataset.adminCategory===state.category;button.setAttribute("aria-pressed",String(active));button.classList.toggle("is-active",active);button.setAttribute("aria-selected",String(active));button.tabIndex=active?0:-1;});
  if(detail)renderAdminShellDetail();else if(byId("adminShellDetail"))byId("adminShellDetail").hidden=true;
  const activeTab=def?.tabs?.find(item=>item.key===state.adminDetailTab)||def?.tabs?.[0]||null;
  try{window.dispatchEvent(new CustomEvent("p2k-admin-shell-route",{detail:{category:state.category,detail:state.adminDetail,tab:state.adminDetailTab,nativeKey:activeTab?.nativeKey||""}}));}catch(_){ }
}
'''
    text, count = re.subn(r'function adminShellActivate\(\)\{.*?\n\}\n(?=async function adminShellJSON)', lambda _m: activate, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit("canonical admin route event anchor not found")

    listener_pattern = re.compile(
        r'document\.querySelectorAll\("\[data-public-page\]"\)\.forEach\(button => button\.addEventListener\("click", \(\) => \{\n'
        r'if \(button\.disabled\) return;\n'
        r'if \(button\.dataset\.publicPage === "hall"\) openHallOfFame\(\);\n'
        r'else showPublicPage\(button\.dataset\.publicPage \|\| "dashboard"\);\n'
        r'\}\)\);'
    )
    listener = '''document.querySelectorAll("[data-public-page]").forEach(button => button.addEventListener("click", () => {
if (button.disabled) return;
const page=button.dataset.publicPage||"dashboard";
if(page==="administration"){
  if(!state.admin)return;
  state.view="admin";state.publicPage="dashboard";renderView();writeNavigationState();
  return;
}
if (page === "hall") openHallOfFame();
else showPublicPage(page);
}));'''
    text, count = listener_pattern.subn(lambda _m: listener, text, count=1)
    if count != 1:
        raise SystemExit("historical Administration button listener anchor not found")

    write(path, text)


def recruitment_changes():
    path = "assets/js/pages/v2-11-0.js"
    text = read(path)
    text = text.replace("  const MEMBERSHIP_BATCH = 1000;\n  const CHECKPOINT_BATCH = 10;", "  const MEMBERSHIP_BATCH = 2000;\n  const CHECKPOINT_BATCH = 500;", 1)
    if "const CHECKPOINT_BATCH = 500" not in text:
        raise SystemExit("Recruitment batch constants not replaced")

    text, count = re.subn(r'\n\s*html\.p2k-recruitment-route #adminShellDetailFrame\{[^\n]+\}\n\s*html\.p2k-recruitment-route \[data-admin-shell-panel\]\{[^\n]+\}', '', text, count=1)
    if count != 1:
        raise SystemExit("Recruitment route CSS hacks not found")

    style_anchor = '.p2k-recruitment-summary span{display:block;color:#a9a096;font-size:10px;text-transform:uppercase;letter-spacing:.04em}.p2k-recruitment-summary strong{display:block;margin-top:2px;color:#f6b73c;font-size:20px}'
    if text.count(style_anchor) != 1:
        raise SystemExit("Recruitment summary style anchor not found")
    text = text.replace(style_anchor, style_anchor + '.p2k-recruitment-summary small{display:block;margin-top:3px;color:#91887f;font-size:10px;line-height:1.3}', 1)

    text, count = re.subn(
        r',\n\s*\["recruitmentWorkers", "Parallel candidates", "4"\]\n\s*\];\n\s*return fields\.map\(\(\[id, label, value, step\]\) => `<div class="p2k-recruitment-field"><label for="\$\{id\}">\$\{escapeHTML\(label\)\}</label><input class="p2k-recruitment-input" id="\$\{id\}" type="number" min="0"\$\{step \? ` step="\$\{step\}"` : ""\}\$\{id === "recruitmentWorkers" \? \' max="12"\' : ""\} value="\$\{escapeHTML\(value\)\}" placeholder="\$\{value \? "" : "Doesn\'t matter"\}"></div>`\)\.join\(""\);',
        '\n    ];\n    return fields.map(([id, label, value, step]) => `<div class="p2k-recruitment-field"><label for="${id}">${escapeHTML(label)}</label><input class="p2k-recruitment-input" id="${id}" type="number" min="0"${step ? ` step="${step}"` : ""} value="${escapeHTML(value)}" placeholder="${value ? "" : "Doesn\'t matter"}"></div>`).join("");',
        text,
        count=1,
    )
    if count != 1:
        # Fallback: remove the field and the conditional max separately.
        text, field_count = re.subn(r',\n\s*\["recruitmentWorkers", "Parallel candidates", "4"\]', '', text, count=1)
        text, max_count = re.subn(r'\$\{id === "recruitmentWorkers" \? \' max="12"\' : ""\}', '', text, count=1)
        if field_count != 1 or max_count != 1:
            raise SystemExit(f"remove parallel-candidate UI failed: field={field_count}, max={max_count}")

    summary_anchor = '<div class="p2k-recruitment-summary"><div><span>Candidates</span><strong id="recruitmentMetricTotal">0</strong></div><div><span>Checked</span><strong id="recruitmentMetricChecked">0</strong></div><div><span>Selected</span><strong id="recruitmentMetricSelected">0</strong></div><div><span>Errors / unavailable</span><strong id="recruitmentMetricErrors">0</strong></div></div>'
    telemetry_markup = '<div class="p2k-recruitment-summary"><div><span>Candidates</span><strong id="recruitmentMetricTotal">0</strong></div><div><span>Checked</span><strong id="recruitmentMetricChecked">0</strong></div><div><span>Selected</span><strong id="recruitmentMetricSelected">0</strong></div><div><span>Errors / unavailable</span><strong id="recruitmentMetricErrors">0</strong></div><div><span>Remaining</span><strong id="recruitmentMetricRemaining">0</strong></div><div><span>Candidates / second</span><strong id="recruitmentMetricRate">—</strong><small id="recruitmentMetricRateNote">Rolling 10 s</small></div><div><span>ETA</span><strong id="recruitmentMetricEta">—</strong><small id="recruitmentMetricEtaNote">Elapsed —</small></div><div><span>OAuth gateway</span><strong id="recruitmentMetricOauth">—</strong><small id="recruitmentMetricOauthNote">Shared full-throttle transport</small></div></div>'
    if text.count(summary_anchor) != 1:
        raise SystemExit("Recruitment telemetry markup anchor not found")
    text = text.replace(summary_anchor, telemetry_markup, 1)

    state_anchor = '  let adminState = { pool: null, run: null };\n  let activeScan = false;\n  let scanGeneration = 0;\n  let detailMounted = false;\n'
    if text.count(state_anchor) != 1:
        raise SystemExit("Recruitment state anchor not found")
    telemetry_engine = '''  let adminState = { pool: null, run: null };
  let activeScan = false;
  let scanGeneration = 0;
  let detailMounted = false;
  let scanAbortController = null;
  let scanTelemetry = null;
  let scanTelemetryTimer = 0;

  function durationLabel(seconds) {
    const value=Math.max(0,Number(seconds)||0);
    if(value<60)return `${Math.round(value)} s`;
    if(value<3600)return `${Math.floor(value/60)}m ${Math.round(value%60)}s`;
    return `${Math.floor(value/3600)}h ${Math.round((value%3600)/60)}m`;
  }
  function stopTelemetryTimer(){if(scanTelemetryTimer){clearInterval(scanTelemetryTimer);scanTelemetryTimer=0;}}
  function clearScanTelemetry(){stopTelemetryTimer();scanTelemetry=null;renderScanTelemetry();}
  function beginScanTelemetry(runId,total,baseChecked){
    stopTelemetryTimer();scanTelemetry={runId:String(runId||""),startedAt:Date.now(),finishedAt:0,total:Math.max(0,Number(total)||0),baseChecked:Math.max(0,Number(baseChecked)||0),settled:0,samples:[]};
    scanTelemetryTimer=setInterval(renderScanTelemetry,500);renderScanTelemetry();
  }
  function noteCandidateSettled(){
    if(!scanTelemetry)return;const now=Date.now();scanTelemetry.settled+=1;scanTelemetry.samples.push(now);scanTelemetry.samples=scanTelemetry.samples.filter(at=>now-at<=10000);renderScanTelemetry();
  }
  function finishScanTelemetry(){if(scanTelemetry&&!scanTelemetry.finishedAt)scanTelemetry.finishedAt=Date.now();stopTelemetryTimer();renderScanTelemetry();}
  function renderScanTelemetry(){
    const summary=adminState.run?.summary||{};const total=Math.max(0,Number(summary.total??scanTelemetry?.total??0)||0);const storedChecked=Math.max(0,Number(summary.checked??adminState.run?.results?.length??0)||0);
    const liveChecked=scanTelemetry?Math.min(total,Math.max(storedChecked,scanTelemetry.baseChecked+scanTelemetry.settled)):storedChecked;const remaining=Math.max(0,total-liveChecked);
    const now=scanTelemetry?.finishedAt||Date.now();const elapsed=scanTelemetry?Math.max(0,(now-scanTelemetry.startedAt)/1000):0;let rolling=0,average=0;
    if(scanTelemetry){const windowSeconds=Math.max(1,Math.min(10,elapsed||1));scanTelemetry.samples=scanTelemetry.samples.filter(at=>now-at<=10000);rolling=scanTelemetry.samples.length/windowSeconds;average=elapsed>0?scanTelemetry.settled/elapsed:0;}
    const rate=rolling>0?rolling:average;const eta=remaining===0&&total>0?"Done":rate>0?durationLabel(remaining/rate):"—";const diag=window.P2K_API_CLIENT?.diagnostics?.()||{};const gatewayRate=Math.max(0,Number(diag.oauthGatewayLastCps||0));const targetRate=Math.max(0,Number(diag.oauthGatewayRateTarget||0));
    if($("recruitmentMetricChecked"))$("recruitmentMetricChecked").textContent=liveChecked.toLocaleString("en-GB");
    if($("recruitmentMetricRemaining"))$("recruitmentMetricRemaining").textContent=remaining.toLocaleString("en-GB");
    if($("recruitmentMetricRate"))$("recruitmentMetricRate").textContent=rate>0?rate.toFixed(rate<10?2:1):"—";
    if($("recruitmentMetricRateNote"))$("recruitmentMetricRateNote").textContent=scanTelemetry?`Rolling 10 s · avg ${average.toFixed(2)}/s`:"Rolling 10 s";
    if($("recruitmentMetricEta"))$("recruitmentMetricEta").textContent=eta;
    if($("recruitmentMetricEtaNote"))$("recruitmentMetricEtaNote").textContent=scanTelemetry?`Elapsed ${durationLabel(elapsed)}`:"Elapsed —";
    if($("recruitmentMetricOauth"))$("recruitmentMetricOauth").textContent=diag.oauthBearerMode?(gatewayRate>0?`${gatewayRate.toFixed(1)} req/s`:"OAuth"):"Not active";
    if($("recruitmentMetricOauthNote"))$("recruitmentMetricOauthNote").textContent=diag.oauthBearerMode?`Shared gateway · target ${targetRate>0?targetRate.toFixed(1):"adaptive"} req/s`:"OAuth Bearer required";
    if($("recruitmentProgressBar")&&total)$("recruitmentProgressBar").style.width=`${Math.min(100,liveChecked*100/total)}%`;
  }
'''
    text = text.replace(state_anchor, telemetry_engine, 1)

    start_anchor = '    if ($("recruitmentStart")) $("recruitmentStart").disabled = activeScan;\n    const tbody = $("recruitmentRows");'
    if text.count(start_anchor) != 1:
        raise SystemExit("render telemetry anchor not found")
    text = text.replace(start_anchor, '    if ($("recruitmentStart")) $("recruitmentStart").disabled = activeScan;\n    renderScanTelemetry();\n    const tbody = $("recruitmentRows");', 1)

    text, count = re.subn(r',\["recruitmentWorkers","parallelWorkers"\]', '', text, count=1)
    if count != 1:
        raise SystemExit("parallel worker criteria-load mapping not found")
    text, count = re.subn(r', parallelWorkers:Math\.max\(1,Math\.min\(12,Number\(\$\("recruitmentWorkers"\)\?\.value\|\|4\)\)\)', '', text, count=1)
    if count != 1:
        raise SystemExit("parallel worker criteria-save mapping not found")

    chess = '''  async function chessJSON(url, attempts = 3, signal = null) {
    const client = window.P2K_API_CLIENT;
    if (client && typeof client.json === "function") return client.json(url, { attempts, cacheMode: "network-only", trafficClass: "foreground", signal });
    let lastError = null;
    for (let attempt = 1; attempt <= attempts; attempt += 1) {
      try {
        const response = await fetch(url, { mode:"cors", headers:{Accept:"application/json"}, cache:"no-store", signal });
        if (response.ok) return await response.json();
        const error = new Error(`Chess.com returned HTTP ${response.status}.`); error.status = response.status;
        if (![429,500,502,503,504].includes(response.status) || attempt >= attempts) throw error;
        lastError = error;
      } catch (error) { lastError = error; if (attempt >= attempts || signal?.aborted) throw error; }
      await sleep(350 * attempt);
    }
    throw lastError || new Error("Chess.com request failed.");
  }

  function daysSinceUnix'''
    text, count = re.subn(r'  async function chessJSON\(url, attempts = 3\) \{.*?\n  \}\n\n  function daysSinceUnix', lambda _m: chess, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit("signal-aware Chess.com transport anchor not found")

    scan_candidate = '''  async function scanCandidate(username, p2kState, criteria, signal = null) {
    if (p2kState === "current" || (criteria?.excludeFormer && p2kState === "former")) {
      return { username, data:{ closed:false, p2k_state:p2kState, error:"" } };
    }
    const base = `https://api.chess.com/pub/player/${encodeURIComponent(username)}`;
    let profile;
    try { profile = await chessJSON(base,3,signal); }
    catch (error) {
      if ([404,410].includes(Number(error?.status))) return {username,data:{closed:true,p2k_state:p2kState,error:""}};
      if(signal?.aborted)throw error;
      return {username,data:{closed:false,p2k_state:p2kState,error:error?.message||"Profile unavailable."}};
    }
    const closed = /closed/i.test(text(profile?.status));
    const common = {p2k_state:p2kState,last_online_days:daysSinceUnix(profile?.last_online),account_age_days:daysSinceUnix(profile?.joined)};
    if (closed) return {username,data:{...common,closed:true,error:""}};
    let stats,games;
    try { [stats,games] = await Promise.all([chessJSON(`${base}/stats`,3,signal),chessJSON(`${base}/games`,3,signal)]); }
    catch (error) { if(signal?.aborted)throw error; return {username,data:{...common,closed:false,error:error?.message||"Stats or current games unavailable."}}; }
    const daily=stats?.chess_daily||{}, record=daily?.record||{};
    const completed=[record.win,record.loss,record.draw].reduce((sum,value)=>sum+(number(value)??0),0);
    return {username,data:{...common,daily_rating:number(daily?.last?.rating??daily?.best?.rating),timeout_percent:number(record?.timeout_percent),current_daily_games:Array.isArray(games?.games)?games.games.length:null,daily_rd:number(daily?.last?.rd),completed_daily_games:completed,avg_seconds_per_move:number(record?.time_per_move),closed:false,error:""}};
  }

  async function checkpoint'''
    text, count = re.subn(r'  async function scanCandidate\(username, p2kState, criteria\) \{.*?\n  \}\n\n  async function checkpoint', lambda _m: scan_candidate, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit("Recruitment candidate transport anchor not found")

    text, count = re.subn(r'\n\s*if \(payload\?\.run\?\.status === "completed"\) activeScan = false;', '', text, count=1)
    if count != 1:
        raise SystemExit("checkpoint activeScan side effect not found")

    runner = '''  async function runScan(run) {
    const generation=++scanGeneration;activeScan=true;renderRun(run);
    const checked=new Set((run?.results||[]).map(row=>text(row.username).toLowerCase()));
    const pending=(run?.candidates||[]).filter(username=>!checked.has(text(username).toLowerCase()));
    if(!pending.length){activeScan=false;finishScanTelemetry();await loadState({populateEditor:false});return;}

    setStatus(`Preparing Green membership · 0 / ${pending.length.toLocaleString("en-GB")} candidates…`);
    setPreparationProgress(0,pending.length);
    let membership;
    try{membership=await loadMembershipStates(pending,generation);}catch(error){activeScan=false;renderRun();setStatus(`Unable to prepare Green membership: ${error?.message||error}`,true);return;}
    if(!activeScan||generation!==scanGeneration)return;

    if(window.P2K_REAL_OAUTH_READY){try{await window.P2K_REAL_OAUTH_READY;}catch(_){ }}
    const api=window.P2K_API_CLIENT;
    const diagnostics=api?.diagnostics?.()||{};
    if(!api?.processPriority||!api?.json||diagnostics.oauthBearerMode!==true){activeScan=false;renderRun();setStatus("Recruitment requires the authenticated OAuth Bearer API scheduler. Log in with Chess.com and retry.",true);return;}

    const controller=new AbortController();scanAbortController=controller;
    beginScanTelemetry(run?.id,Number(run?.summary?.total??run?.candidates?.length??0),Number(run?.summary?.checked??run?.results?.length??0));
    const checkpointBuffer=[];let checkpointChain=Promise.resolve();let checkpointError=null;let queuedCheckpointBatches=0;let completedCheckpointBatches=0;
    const updateCheckpointNote=()=>{const node=$("recruitmentRowsNote");if(node&&activeScan){const backlog=Math.max(0,queuedCheckpointBatches-completedCheckpointBatches);node.textContent=backlog?`${backlog.toLocaleString("en-GB")} secured checkpoint batch${backlog===1?"":"es"} queued for persistence.`:"Checkpoints caught up with live evaluation.";}};
    const scheduleCheckpoint=(force=false)=>{
      while(checkpointBuffer.length>=CHECKPOINT_BATCH||(force&&checkpointBuffer.length)){
        const batch=checkpointBuffer.splice(0,Math.min(CHECKPOINT_BATCH,checkpointBuffer.length));queuedCheckpointBatches+=1;updateCheckpointNote();
        checkpointChain=checkpointChain.then(async()=>{
          if(checkpointError)return;
          try{await checkpoint(run.id,batch);completedCheckpointBatches+=1;updateCheckpointNote();}catch(error){checkpointError=error;activeScan=false;controller.abort();}
        });
      }
      return checkpointChain;
    };
    const feeder=Number(diagnostics.recommendedBatchConcurrency||diagnostics.oauthLogicalFeederConcurrency||0);
    setStatus(`Scan running · OAuth Bearer full throttle · ${feeder?`${feeder} logical candidate feeders · `:""}gateway rate/concurrency are controlled automatically · checkpoints up to ${CHECKPOINT_BATCH}.`);
    renderRun();

    try{
      await api.processPriority(pending,async username=>{
        if(!activeScan||generation!==scanGeneration||controller.signal.aborted){const error=new Error("Recruitment scan cancelled.");error.name="AbortError";throw error;}
        const result=await scanCandidate(username,membership.get(text(username).toLowerCase())||"none",run?.criteria||{},controller.signal);
        if(!activeScan||generation!==scanGeneration||controller.signal.aborted){const error=new Error("Recruitment scan cancelled.");error.name="AbortError";throw error;}
        noteCandidateSettled();checkpointBuffer.push(result);scheduleCheckpoint(false);return result;
      },{signal:controller.signal,getKey:username=>text(username).toLowerCase(),getPriority:()=>60});
    }catch(error){if(!controller.signal.aborted&&!checkpointError)checkpointError=error;}

    if(generation!==scanGeneration)return;
    if(!controller.signal.aborted&&!checkpointError){setStatus("API evaluation complete · finalizing secured Recruitment checkpoints…");scheduleCheckpoint(true);}
    await checkpointChain;
    scanAbortController=null;
    if(checkpointError){finishScanTelemetry();renderRun();if(["RUN_CHANGED","RUN_COMPLETED","RUN_PAUSED"].includes(String(checkpointError?.code||""))){await loadState({populateEditor:false}).catch(()=>{});if(checkpointError.code==="RUN_PAUSED")setStatus("Paused on the server. Start / resume continues pending candidates.");return;}setStatus(`Checkpoint failed: ${checkpointError.message||checkpointError}`,true);return;}
    if(controller.signal.aborted||!activeScan){finishScanTelemetry();renderRun();return;}
    activeScan=false;finishScanTelemetry();renderRun();await loadState({populateEditor:false}).catch(error=>setStatus(error.message||String(error),true));
  }

'''
    text, count = re.subn(r'  async function runScan\(run\) \{.*?\n  \}\n\n(?=  async function startScan\(\))', lambda _m: runner, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit("OAuth full-throttle Recruitment runner anchor not found")

    old_pause = '  async function pauseScan(){scanGeneration+=1;activeScan=false;renderRun();try{const payload=await server("pause",{method:"POST",body:{}});if(adminState.run&&payload.run){Object.assign(adminState.run,payload.run);adminState.run.summary=payload.run.summary||adminState.run.summary;}renderRun();setStatus(`Paused · ${Number(payload.run?.summary?.pending||0).toLocaleString("en-GB")} candidates remain. Start / resume continues only pending candidates.`);}catch(error){setStatus(error.message||String(error),true);}}'
    new_pause = '  async function pauseScan(){scanGeneration+=1;activeScan=false;scanAbortController?.abort();scanAbortController=null;finishScanTelemetry();renderRun();try{const payload=await server("pause",{method:"POST",body:{}});if(adminState.run&&payload.run){Object.assign(adminState.run,payload.run);adminState.run.summary=payload.run.summary||adminState.run.summary;}renderRun();setStatus(`Paused · ${Number(payload.run?.summary?.pending||0).toLocaleString("en-GB")} candidates remain. Start / resume continues only pending candidates.`);}catch(error){setStatus(error.message||String(error),true);}}'
    if text.count(old_pause) != 1:
        raise SystemExit("pause scan anchor not found")
    text = text.replace(old_pause, new_pause, 1)

    text, count = re.subn(r'scanGeneration\+=1;activeScan=false;try\{await server\("restart"', 'scanGeneration+=1;activeScan=false;scanAbortController?.abort();scanAbortController=null;clearScanTelemetry();try{await server("restart"', text, count=1)
    if count != 1:
        raise SystemExit("restart scan anchor not found")

    tail = '''  function ensureRecruitmentDetail() {
    if(!isRecruitmentRoute())return;
    const host=$("adminShellNativeDetailHost");
    if(!host||host.hidden)return;
    let mount=$("p2kRecruitmentNativeHost");
    if(!mount||mount.parentElement!==host){mount?.remove();mount=document.createElement("div");mount.id="p2kRecruitmentNativeHost";mount.className="dashboard-admin-native-detail-host";host.replaceChildren(mount);detailMounted=false;}
    if(!detailMounted||!$("p2kRecruitmentNative")){detailMounted=true;mount.innerHTML=recruitmentMarkup();bindRecruitment();loadState().catch(error=>setStatus(`Unable to load Recruitment state: ${error.message||error}`,true));}
  }

  function reconcile(){
    installStyles();fixMembersInsightsHeader();
    const route=routeState();if(route.isAdmin)document.body?.classList.add("p2k-admin-canonical");
    if(isRecruitmentRoute())ensureRecruitmentDetail();else ensureRecruitmentCard();
  }
  function mount(){installStyles();window.addEventListener("p2k-admin-shell-route",reconcile);reconcile();}
'''
    text, count = re.subn(r'  function ensureRecruitmentDetail\(\) \{.*?\n  function mount\(\)\{.*?\n  \}\n', lambda _m: tail, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit("remove Recruitment DOM router workaround anchor not found")

    write(path, text)


def supporting_changes():
    server = "server/team-points/public/recruitment-admin.php"
    text = read(server)
    text, count = re.subn(r"\n\s*'parallelWorkers' => max\(1, min\(12, \(int\)\(\$body\['parallelWorkers'\] \?\? 4\)\)\),", '', text, count=1)
    if count != 1:
        raise SystemExit("server parallel worker criterion not found")
    text = text.replace('const P2K_RECRUITMENT_CHECKPOINT_MAX = 50;', 'const P2K_RECRUITMENT_CHECKPOINT_MAX = 500;', 1)
    if 'P2K_RECRUITMENT_CHECKPOINT_MAX = 500' not in text:
        raise SystemExit("server checkpoint max not updated")
    write(server, text)

    green = "assets/js/pages/v2-11-0-green-primary.js"
    text = read(green)
    text, count = re.subn(r'\n\s*const legacyTab = document\.getElementById\("dashboardAdministrationTab"\); if \(legacyTab\) legacyTab\.hidden = true;', '', text, count=1)
    if count != 1:
        raise SystemExit("historical Administration hiding line not found")
    write(green, text)

    r2 = "assets/js/pages/v2-11-0-r2-admin-stability.js"
    text = read(r2)
    old = 'html:not(.p2k-recruitment-route) .dashboard-admin-detail-frame[data-p2k-r2-stable="1"]{display:block!important;width:100%!important;min-height:520px!important;max-height:none!important;overflow:hidden!important}'
    if text.count(old) != 1:
        raise SystemExit("R2 Recruitment-specific frame CSS not found")
    text = text.replace(old, '.dashboard-admin-detail-frame[data-p2k-r2-stable="1"]{display:block!important;width:100%!important;min-height:520px!important;max-height:none!important;overflow:hidden!important}', 1)
    text, count = re.subn(
        r'\n\s*if \(recruitmentRoute\(\)\) \{\n\s*frame\.removeAttribute\("data-p2k-r2-stable"\);\n\s*return;\n\s*\}\n\s*const detail = byId\("adminShellDetail"\);',
        '\n    const frameWrap = byId("adminShellDetailFrameWrap") || frame.parentElement;\n    if (frame.hidden || frameWrap?.hidden) { frame.removeAttribute("data-p2k-r2-stable"); return; }\n    const detail = byId("adminShellDetail");',
        text,
        count=1,
    )
    if count != 1:
        raise SystemExit("R2 Recruitment route special case not found")
    write(r2, text)

    gate = "assets/js/pages/ui-version-gate.js"
    text = read(gate)
    text, count = re.subn(r'\n\s*// v2\.11\.0: Recruitment is a native Administration detail\..*?(?=\n\s*if \(!onDashboard && selected !== "v2"\) return;)', '', text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit("Recruitment first-paint special case not found")
    write(gate, text)

    site = "assets/js/site-config.js"
    text = read(site)
    if '2.11.0-r5' not in text:
        raise SystemExit("R5 site-config cache keys not found")
    text = text.replace('2.11.0-r5', '2.11.0-r6')
    text = re.sub(r'builtAt: "[^"]+"', 'builtAt: "2026-09-01T16:30:00Z"', text, count=1)
    write(site, text)

    ui = "ui-v2.html"
    text = read(ui)
    text, c1 = re.subn(r'assets/js/pages/ui-version-gate\.js\?v=[^"\s<]+', 'assets/js/pages/ui-version-gate.js?v=2.11.0-r6', text, count=1)
    text, c2 = re.subn(r'assets/js/site-config\.js\?v=[^"\s<]+', 'assets/js/site-config.js?v=2.11.0-r6', text, count=1)
    text, c3 = re.subn(r'assets/js/pages/dashboard-v2\.js\?v=[^"\s<]+', 'assets/js/pages/dashboard-v2.js?v=2.11.0-r6', text, count=1)
    if (c1, c2, c3) != (1, 1, 1):
        raise SystemExit(f"R6 cache keys failed: gate={c1}, site={c2}, dashboard={c3}")
    write(ui, text)


def validate_static():
    dashboard = read("assets/js/pages/dashboard-v2.js")
    recruitment = read("assets/js/pages/v2-11-0.js")
    green = read("assets/js/pages/v2-11-0-green-primary.js")
    gate = read("assets/js/pages/ui-version-gate.js")
    server = read("server/team-points/public/recruitment-admin.php")
    ui = read("ui-v2.html")
    checks = [
        ('mode:"native",nativeKey:"recruitment"' in dashboard, "canonical native Recruitment definition"),
        ('id="adminShellNativeDetailHost"' in dashboard, "native detail host"),
        ('p2k-admin-shell-route' in dashboard, "canonical route event"),
        ('data-public-page' in dashboard and 'page==="administration"' in dashboard, "historical Administration canonical routing"),
        ('processPriority(pending' in recruitment, "shared OAuth priority scheduler"),
        ('OAuth Bearer full throttle' in recruitment, "full-throttle status"),
        ('Candidates / second' in recruitment and 'recruitmentMetricEta' in recruitment, "scan telemetry"),
        ('recruitmentWorkers' not in recruitment, "no manual Recruitment worker control"),
        ('parallelWorkers' not in server, "no server worker criterion"),
        ('P2K_RECRUITMENT_CHECKPOINT_MAX = 500' in server, "large secured checkpoints"),
        ('legacyTab.hidden = true' not in green, "historical Administration button restored"),
        ('p2kRecruitmentFirstPaint' not in gate, "no Recruitment first-paint workaround"),
        ('assets/js/pages/dashboard-v2.js?v=2.11.0-r6' in ui, "dashboard R6 cache key"),
        ('assets/js/pages/ui-version-gate.js?v=2.11.0-r6' in ui, "gate R6 cache key"),
        ('assets/js/site-config.js?v=2.11.0-r6' in ui, "site-config R6 cache key"),
    ]
    failed = [name for ok, name in checks if not ok]
    if failed:
        raise SystemExit("Static R6 validation failed: " + ", ".join(failed))


if __name__ == "__main__":
    dashboard_changes()
    recruitment_changes()
    supporting_changes()
    validate_static()
    print("R6 source transformation complete.")
