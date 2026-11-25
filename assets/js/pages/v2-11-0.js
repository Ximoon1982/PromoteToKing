/* Promote to King v2.11.0: canonical Administration + Recruitment application. */
(() => {
  "use strict";

  const VERSION = "2.11.0";
  const MEMBERSHIP_BATCH = 2000;
  const CHECKPOINT_BATCH = 100;
  const RESULT_PAGE_SIZE = 25;
  const $ = id => document.getElementById(id);
  const text = value => String(value ?? "");
  const number = value => {
    if (value === null || value === undefined || value === "") return null;
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : null;
  };
  const escapeHTML = value => text(value).replace(/[&<>"']/g, char => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[char]));
  const sleep = ms => new Promise(resolve => window.setTimeout(resolve, ms));

  window.P2K_ADMIN_REGISTRY = Object.freeze({
    canonicalView: "admin-toggle",
    release: VERSION,
    categories: Object.freeze(["competitions", "members", "team", "opponents", "maintenance", "misc"]),
    recruitmentDetail: Object.freeze({ title: "Recruitment", source: "Green Core + Chess.com OAuth", mode: "Embedded" })
  });

  function routeState() {
    const params = new URL(location.href).searchParams;
    return {
      isAdmin: params.get("view") === "admin",
      category: params.get("adminCategory") || "competitions",
      detail: params.get("adminDetail") || ""
    };
  }

  function isRecruitmentRoute() {
    const route = routeState();
    return route.isAdmin && route.category === "members" && route.detail === "recruitment";
  }


  function adminHref(adminCategory, adminDetail = "", adminDetailTab = "") {
    const url = new URL("ui-v2.html", window.location.href);
    ["page", "adminTool", "adminContext", "hall", "insights", "assistant", "assistantFilter"].forEach(key => url.searchParams.delete(key));
    url.searchParams.set("ui", "v2");
    url.searchParams.set("view", "admin");
    url.searchParams.set("adminCategory", adminCategory || "competitions");
    if (adminDetail) url.searchParams.set("adminDetail", adminDetail); else url.searchParams.delete("adminDetail");
    if (adminDetailTab) url.searchParams.set("adminDetailTab", adminDetailTab); else url.searchParams.delete("adminDetailTab");
    url.searchParams.delete("adminToolTab");
    url.hash = "";
    return url.href;
  }

  function recruitmentBase() {
    return String(window.P2K_SITE_CONFIG?.serverStorage?.recruitmentAdminEndpoint || "server/team-points/public/recruitment-admin.php");
  }

  function recruitmentEndpoint(action = "state") {
    const url = new URL(recruitmentBase(), window.location.href);
    url.searchParams.set("action", action);
    return url;
  }

  async function server(action, { method = "GET", body = null, params = {} } = {}) {
    const verb = String(method || "GET").toUpperCase();
    if (!["GET", "HEAD", "OPTIONS"].includes(verb)) {
      const client = window.P2K_TEAM_POINTS_CLIENT;
      if (!client?.endpointRequest) throw new Error("The secured Team Points administration client is unavailable.");
      return client.endpointRequest(recruitmentBase(), {
        action,
        method: verb,
        body,
        timeoutMs: 120000,
        serverTrafficClass: "foreground"
      });
    }
    const url = recruitmentEndpoint(action);
    for (const [key,value] of Object.entries(params)) url.searchParams.set(key,String(value));
    const response = await fetch(url, {
      method: verb,
      credentials: "same-origin",
      headers: { Accept: "application/json" },
      cache: "no-store"
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.ok === false) {
      const error = new Error(payload?.error?.message || `Recruitment server request failed (${response.status}).`);
      error.code = payload?.error?.code || "SERVER_REQUEST_FAILED";
      error.status = response.status;
      throw error;
    }
    return payload;
  }

  function installStyles() {
    if ($("p2kV2110Styles")) return;
    const style = document.createElement("style");
    style.id = "p2kV2110Styles";
    style.textContent = `
      body.p2k-admin-canonical #dashboardAdminToggleHost button[aria-pressed="true"],
      body.p2k-admin-canonical [data-admin-category][aria-pressed="true"],
      body.p2k-admin-canonical .dashboard-admin-detail-tab.is-active{border-color:#d45b50!important;color:#ffd9d5!important;background:rgba(177,48,39,.18)!important}
      body.p2k-admin-canonical [data-admin-category][aria-pressed="true"] svg{stroke:#ff8e82!important}
      #p2kRecruitmentNativeHost{display:block!important;width:100%;min-height:0;margin:0;padding:0}
      .p2k-recruitment-native{display:grid;gap:16px}
      .p2k-recruitment-native-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
      .p2k-recruitment-native-head h3{margin:4px 0 6px}.p2k-recruitment-native-head p{margin:0;max-width:900px;color:var(--muted,#b7aea4)}
      .p2k-recruitment-native-grid{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);gap:14px}
      .p2k-recruitment-native fieldset{min-width:0;margin:0;padding:14px;border:1px solid rgba(255,255,255,.12);border-radius:10px;background:rgba(0,0,0,.08)}
      .p2k-recruitment-native legend{padding:0 6px;color:#f2dfbf;font-weight:700}
      .p2k-recruitment-field{display:grid;gap:5px;margin-bottom:9px}.p2k-recruitment-field label{font-size:12px;color:#cfc6bb;font-weight:700}
      .p2k-recruitment-input,.p2k-recruitment-textarea{width:100%;box-sizing:border-box;border:1px solid rgba(255,255,255,.15);border-radius:7px;background:#151311;color:#f1eee9;padding:9px 10px}
      .p2k-recruitment-textarea{min-height:176px;resize:vertical;line-height:1.35}
      .p2k-recruitment-criteria{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 10px}
      .p2k-recruitment-checks{display:grid;gap:7px;margin-top:5px}.p2k-recruitment-checks label{font-size:12px;color:#cfc6bb}
      .p2k-recruitment-help{margin-top:7px;color:#9e958b;font-size:11px;line-height:1.45}
      .p2k-recruitment-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
      .p2k-recruitment-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
      .p2k-recruitment-summary>div{padding:10px;border:1px solid rgba(255,255,255,.10);border-radius:8px;background:rgba(0,0,0,.12)}
      .p2k-recruitment-summary span{display:block;color:#a9a096;font-size:10px;text-transform:uppercase;letter-spacing:.04em}.p2k-recruitment-summary strong{display:block;margin-top:2px;color:#f6b73c;font-size:20px}.p2k-recruitment-summary small{display:block;margin-top:3px;color:#91887f;font-size:10px;line-height:1.3}
      .p2k-recruitment-status{padding:10px 12px;border-radius:8px;background:rgba(255,255,255,.06);color:#d8d1c8}.p2k-recruitment-status.is-error{color:#ffb2aa;background:rgba(177,48,39,.14)}
      .p2k-recruitment-progress{height:7px;overflow:hidden;border-radius:999px;background:rgba(255,255,255,.08)}.p2k-recruitment-progress span{display:block;width:0;height:100%;background:#d98d18;transition:width .2s ease}
      .p2k-recruitment-table-wrap{width:100%;overflow:auto;border:1px solid rgba(255,255,255,.09);border-radius:9px}.p2k-recruitment-table{width:100%;border-collapse:collapse}.p2k-recruitment-table th,.p2k-recruitment-table td{padding:8px 9px;border-bottom:1px solid rgba(255,255,255,.07);white-space:nowrap;text-align:left}.p2k-recruitment-table td:last-child{white-space:normal;min-width:240px}
      .p2k-recruitment-decision{display:inline-block;padding:2px 7px;border-radius:999px;border:1px solid rgba(255,255,255,.17);font-size:11px}.p2k-recruitment-decision.is-selected{color:#83d99a;border-color:#3e8150}.p2k-recruitment-decision.is-rejected{color:#ff9586;border-color:#8f463d}
      .p2k-recruitment-overview-card .dashboard-admin-shell-status{white-space:nowrap}
      @media(max-width:900px){.p2k-recruitment-native-grid{grid-template-columns:1fr}}
      @media(max-width:620px){.p2k-recruitment-criteria,.p2k-recruitment-summary{grid-template-columns:1fr}.p2k-recruitment-native-head{display:grid}.p2k-recruitment-actions .dashboard-button{flex:1 1 auto}}
    `;
    document.head.appendChild(style);
  }

  function fixMembersInsightsHeader() {
    const root = $("membersDataTable");
    if (!root) return;
    const winRate = root.querySelector('thead th[data-key="win_rate"]');
    if (!winRate || root.querySelector('thead th[data-key="result_coverage_percent"]')) return;
    const coverage = document.createElement("th");
    coverage.dataset.key = "result_coverage_percent";
    coverage.textContent = "Result coverage";
    winRate.before(coverage);
  }

  let cardLoading = false;
  function ensureRecruitmentCard() {
    const route = routeState();
    if (!route.isAdmin || route.category !== "members" || route.detail) return;
    const panel = document.querySelector('[data-admin-shell-panel="members"]');
    const grid = panel?.querySelector(".dashboard-admin-shell-grid");
    if (!grid || panel.hidden) return;
    let card = grid.querySelector('[data-admin-shell-card="recruitment"]');
    if (!card) {
      card = document.createElement("article");
      card.className = "dashboard-admin-shell-card p2k-recruitment-overview-card";
      card.dataset.adminShellCard = "recruitment";
      card.innerHTML = `
        <header class="dashboard-admin-shell-card-head"><div><span class="dashboard-admin-shell-eyebrow">Members</span><h3>Recruitment</h3></div><span class="dashboard-admin-shell-status is-loading" id="adminShellStatus_recruitment">Loading</span></header>
        <p>Create and resume independent recruitment analyses against Daily activity, reliability and membership criteria.</p>
        <div class="dashboard-admin-shell-metrics">
          <div class="dashboard-admin-shell-metric"><span>Candidates</span><strong id="adminRecruitmentCandidates">—</strong><small>Across saved analyses</small></div>
          <div class="dashboard-admin-shell-metric"><span>Checked</span><strong id="adminRecruitmentChecked">—</strong><small>Across saved analyses</small></div>
          <div class="dashboard-admin-shell-metric"><span>Selected</span><strong id="adminRecruitmentSelected">—</strong><small>Across saved analyses</small></div>
        </div>
        <div class="dashboard-admin-shell-meta"><span><b>Freshness</b><em id="adminShellFresh_recruitment">Checking…</em></span><span><b>Source</b><em>Green Core + Chess.com</em></span></div>
        <footer class="dashboard-admin-shell-actions"><a class="dashboard-button" href="${escapeHTML(adminHref("members", "recruitment"))}">Open Recruitment</a></footer>`;
      grid.appendChild(card);
    }
    if (cardLoading) return;
    cardLoading = true;
    server("state").then(payload => {
      const summary = payload?.analyses?.aggregate || {};
      const poolCount = Number(summary.total || 0);
      if ($("adminRecruitmentCandidates")) $("adminRecruitmentCandidates").textContent = String(poolCount);
      if ($("adminRecruitmentChecked")) $("adminRecruitmentChecked").textContent = String(Number(summary.checked || 0));
      if ($("adminRecruitmentSelected")) $("adminRecruitmentSelected").textContent = String(Number(summary.selected || 0));
      const badge = $("adminShellStatus_recruitment");
      if (badge) { badge.textContent = payload?.analyses?.active ? "Running" : `${Number(summary.analyses || 0)} saved`; badge.className = "dashboard-admin-shell-status is-good"; }
      if ($("adminShellFresh_recruitment")) $("adminShellFresh_recruitment").textContent = "Server state · live";
    }).catch(error => {
      const badge = $("adminShellStatus_recruitment");
      if (badge) { badge.textContent = "Unavailable"; badge.className = "dashboard-admin-shell-status is-bad"; }
      if ($("adminShellFresh_recruitment")) $("adminShellFresh_recruitment").textContent = error?.message || "Unable to read state";
    }).finally(() => { cardLoading = false; });
  }

  let adminState = { run: null, analyses: null };
  let composing = false, busy = false, listPage = 1, resultPage = 1, resultRequest = 0, listRequest = 0;
  let scanControl = null;
  const DEFAULT_CRITERIA = { minRating:1300,maxRating:null,maxTimeout:8,maxRd:100,minGames:2,maxGames:25,minCompleted:50,maxOffline:14,maxSpm:null,minAge:null,excludeFormer:false };
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
    const liveChecked=activeScan&&scanTelemetry?Math.min(total,Math.max(storedChecked,scanTelemetry.baseChecked+scanTelemetry.settled)):storedChecked;const remaining=Math.max(0,total-liveChecked);
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

  function displayDays(value) {
    const numeric = number(value);
    if (numeric === null) return "—";
    if (numeric < 1) return "today";
    return `${Math.round(numeric)} d`;
  }

  function criteriaMarkup() {
    const fields = [
      ["recruitmentMinRating", "Minimum Daily rating", "1300"], ["recruitmentMaxRating", "Maximum Daily rating", ""],
      ["recruitmentMaxTimeout", "Maximum timeout rate (%)", "8", "0.1"], ["recruitmentMaxRd", "Maximum rating deviation (RD)", "100"],
      ["recruitmentMinGames", "Current Daily games · minimum", "2"], ["recruitmentMaxGames", "Current Daily games · maximum", "25"],
      ["recruitmentMinCompleted", "Minimum completed Daily games", "50"], ["recruitmentMaxOffline", "Last online · maximum days ago", "14"],
      ["recruitmentMaxSpm", "Average seconds per move · maximum", ""], ["recruitmentMinAge", "Minimum account age (days)", ""]
    ];
    return fields.map(([id, label, value, step]) => `<div class="p2k-recruitment-field"><label for="${id}">${escapeHTML(label)}</label><input class="p2k-recruitment-input" id="${id}" type="number" min="0"${step ? ` step="${step}"` : ""} value="${escapeHTML(value)}" placeholder="${value ? "" : "Doesn't matter"}"></div>`).join("");
  }

  function recruitmentMarkup() {
    return `<section class="p2k-recruitment-native" id="p2kRecruitmentNative" data-release="2.16.2">
      <div class="p2k-recruitment-native-head"><div><h3>Recruitment analyses</h3><p>Each analysis has its own candidate list, eligibility criteria and checkpointed results. Only one scan can run at a time.</p></div><button class="dashboard-button" id="recruitmentNew" type="button">+ New analysis</button></div>
      <div class="p2k-recruitment-actions"><input class="p2k-recruitment-input" style="max-width:300px" id="recruitmentAnalysisSearch" type="search" placeholder="Search analyses" aria-label="Search analyses"><button class="dashboard-button is-secondary" id="recruitmentRefresh" type="button">Refresh list</button><span id="recruitmentActiveNote" class="p2k-recruitment-help"></span></div>
      <div class="p2k-recruitment-table-wrap"><table class="p2k-recruitment-table"><thead><tr><th>Analysis</th><th>Created</th><th>Administrator</th><th>Status</th><th>Candidates</th><th>Checked</th><th>Selected</th><th>Errors</th><th></th></tr></thead><tbody id="recruitmentAnalysisRows"><tr><td colspan="9">Loading analyses…</td></tr></tbody></table></div>
      <div class="p2k-recruitment-actions"><button id="recruitmentListPrev" class="dashboard-button is-secondary" type="button">Previous</button><span id="recruitmentListPage"></span><button id="recruitmentListNext" class="dashboard-button is-secondary" type="button">Next</button></div>
      <div class="p2k-recruitment-status" id="recruitmentStatus" role="status" aria-live="polite">Choose an analysis or create a new one.</div>
      <section id="recruitmentDetailPanel" hidden>
      <div class="p2k-recruitment-native">
      <div class="p2k-recruitment-native-head"><div><h3 id="recruitmentDetailTitle">New analysis</h3><p id="recruitmentDetailMeta"></p></div><button id="recruitmentClose" class="dashboard-button is-secondary" type="button">Close analysis</button></div>
      <div class="p2k-recruitment-field"><label for="recruitmentName">Analysis name</label><input class="p2k-recruitment-input" id="recruitmentName" maxlength="120" placeholder="e.g. December Daily recruitment"></div>
      <div class="p2k-recruitment-native-grid">
        <fieldset><legend>Candidate list</legend><div class="p2k-recruitment-field"><label for="recruitmentCandidates">Usernames or Chess.com player URLs</label><textarea class="p2k-recruitment-textarea" id="recruitmentCandidates" spellcheck="false" placeholder="candidate_alpha&#10;https://www.chess.com/member/candidate_beta"></textarea></div><div id="recruitmentSavedStatus" class="p2k-recruitment-help"></div><div class="p2k-recruitment-help">Up to 100,000 unique usernames per analysis. Existing analyses are never merged automatically.</div></fieldset>
        <fieldset><legend>Eligibility settings</legend><div class="p2k-recruitment-criteria">${criteriaMarkup()}</div><div class="p2k-recruitment-checks"><label><input type="checkbox" checked disabled> Exclude current P2K members.</label><label><input id="recruitmentExcludeFormer" type="checkbox"> Exclude former P2K members as well.</label><label><input type="checkbox" checked disabled> Reject closed / unavailable accounts automatically.</label></div><div class="p2k-recruitment-help">Empty numeric fields mean “doesn’t matter”. Criteria and candidates are immutable once a scan has started. Duplicate to change them.</div></fieldset>
      </div>
      <div class="p2k-recruitment-actions"><button class="dashboard-button" id="recruitmentSaveDraft" type="button">Save draft</button><button class="dashboard-button" id="recruitmentStart" type="button">Start analysis</button><button class="dashboard-button is-secondary" id="recruitmentPause" type="button" disabled>Pause</button><button class="dashboard-button is-secondary" id="recruitmentRetry" type="button">Retry API errors</button><button class="dashboard-button is-secondary" id="recruitmentDuplicate" type="button">Duplicate</button><button class="dashboard-button is-secondary" id="recruitmentDelete" type="button">Delete analysis</button></div>
      <div class="p2k-recruitment-summary"><div><span>Candidates</span><strong id="recruitmentMetricTotal">0</strong></div><div><span>Checked</span><strong id="recruitmentMetricChecked">0</strong></div><div><span>Selected</span><strong id="recruitmentMetricSelected">0</strong></div><div><span>Errors / unavailable</span><strong id="recruitmentMetricErrors">0</strong></div><div><span>Remaining</span><strong id="recruitmentMetricRemaining">0</strong></div><div><span>Candidates / second</span><strong id="recruitmentMetricRate">—</strong><small id="recruitmentMetricRateNote">Rolling 10 s</small></div><div><span>ETA</span><strong id="recruitmentMetricEta">—</strong><small id="recruitmentMetricEtaNote">Elapsed —</small></div><div><span>OAuth gateway</span><strong id="recruitmentMetricOauth">—</strong><small id="recruitmentMetricOauthNote">Shared full-throttle transport</small></div></div>
      
      <div class="p2k-recruitment-progress"><span id="recruitmentProgressBar"></span></div>
      <div class="p2k-recruitment-actions"><input class="p2k-recruitment-input" id="recruitmentResultSearch" type="search" style="max-width:240px" placeholder="Find username" aria-label="Find username"><select class="p2k-recruitment-input" style="max-width:180px" id="recruitmentResultFilter" aria-label="Result decision"><option value="all">All evaluated</option><option value="selected">Selected</option><option value="rejected">Rejected</option><option value="error">API errors</option><option value="pending">Pending</option></select><button class="dashboard-button is-secondary" id="recruitmentCsv" type="button">Selected CSV</button><button class="dashboard-button is-secondary" id="recruitmentCsvAll" type="button">All evaluated CSV</button></div>
      <div class="p2k-recruitment-table-wrap"><table class="p2k-recruitment-table"><thead><tr><th>Candidate</th><th>Decision</th><th>Daily rating</th><th>RD</th><th>Timeout %</th><th>Current games</th><th>Completed games</th><th>Move time (s)</th><th>Last online</th><th>Account age</th><th>P2K state</th><th>Reason</th></tr></thead><tbody id="recruitmentRows"><tr><td colspan="12">No scan results yet.</td></tr></tbody></table></div>
      <div class="p2k-recruitment-actions"><button id="recruitmentResultPrev" class="dashboard-button is-secondary" type="button">Previous</button><span id="recruitmentRowsNote" class="p2k-recruitment-help"></span><button id="recruitmentResultNext" class="dashboard-button is-secondary" type="button">Next</button></div>
      <p class="p2k-recruitment-help"><strong>Data path:</strong> Green Core membership and aliases, Chess.com OAuth player profile, Daily stats and current games. Results are checkpointed independently per analysis. Keep this page open while scanning.</p></div></section>
    </section>`;
  }

  function setStatus(message, error = false) {
    const host = $("recruitmentStatus");
    if (!host) return;
    host.textContent = message;
    host.classList.toggle("is-error", error);
  }

  function setPreparationProgress(done, total) {
    const bar = $("recruitmentProgressBar");
    if (bar) bar.style.width = `${total ? Math.min(100, 100 * done / total) : 0}%`;
  }

  function localDate(value) {
    const date = new Date(value);
    return Number.isFinite(date.getTime()) ? date.toLocaleString(undefined,{dateStyle:"medium",timeStyle:"short"}) : "—";
  }
  const statusLabel = run => run?.status === "completed" ? "Completed" : run?.status === "running" ? "Running" : run?.status === "paused" ? "Paused" : "Draft";
  function syncControls() {
    const run = adminState.run;
    const blocked = busy || activeScan;
    const locked = run?.status === "running";
    const draft = composing || run?.status === "draft";
    const allowEdit = draft && !blocked;
    const disabled = (id,value) => { if ($(id)) $(id).disabled=Boolean(value); };
    for (const id of ["recruitmentName","recruitmentCandidates","recruitmentMinRating","recruitmentMaxRating","recruitmentMaxTimeout","recruitmentMaxRd","recruitmentMinGames","recruitmentMaxGames","recruitmentMinCompleted","recruitmentMaxOffline","recruitmentMaxSpm","recruitmentMinAge","recruitmentExcludeFormer"]) disabled(id,!allowEdit);
    disabled("recruitmentNew",blocked);
    disabled("recruitmentClose",blocked);
    disabled("recruitmentSaveDraft",!allowEdit);
    disabled("recruitmentStart",blocked||locked||(!draft&&!Number(run?.summary?.pending)));
    disabled("recruitmentPause",!activeScan||Boolean(scanControl?.pauseRequested));
    disabled("recruitmentRetry",blocked||locked||!Number(run?.summary?.errors));
    disabled("recruitmentDuplicate",blocked||!run);
    disabled("recruitmentDelete",blocked||locked||!run);
    disabled("recruitmentCsv",!Number(run?.summary?.selected));
    disabled("recruitmentCsvAll",!Number(run?.summary?.checked));
    if ($("recruitmentStart")) $("recruitmentStart").textContent=draft?"Start analysis":"Resume analysis";
    document.querySelectorAll("[data-recruitment-open]").forEach(button=>{button.disabled=blocked;});
  }
  function renderRun(run = adminState.run) {
    adminState.run=run||null;
    const summary=run?.summary||{};
    for(const [id,key] of [["Total","total"],["Checked","checked"],["Selected","selected"],["Errors","errors"]]){
      if($("recruitmentMetric"+id))$("recruitmentMetric"+id).textContent=Number(summary[key]||0).toLocaleString();
    }
    if($("recruitmentProgressBar"))$("recruitmentProgressBar").style.width=(summary.total?Math.min(100,summary.checked*100/summary.total):0)+"%";
    if($("recruitmentDetailPanel"))$("recruitmentDetailPanel").hidden=!run&&!composing;
    if($("recruitmentDetailTitle"))$("recruitmentDetailTitle").textContent=run?.name||"New analysis";
    if($("recruitmentDetailMeta"))$("recruitmentDetailMeta").textContent=run?statusLabel(run)+" · Created "+localDate(run.createdAt)+" · "+run.createdBy+(run.leaseUntil&&!activeScan?" · Scan owned by another page until "+localDate(run.leaseUntil*1000):""):"Create a separate campaign.";
    if($("recruitmentSavedStatus"))$("recruitmentSavedStatus").textContent=run?Number(summary.total||0).toLocaleString()+" saved unique candidates":"Candidate list not yet saved.";
    syncControls();renderScanTelemetry();
  }
  function renderList(list=adminState.analyses) {
    if(!list||!$("recruitmentAnalysisRows"))return;
    const rows=list.items||[];
    $("recruitmentAnalysisRows").innerHTML=rows.length?rows.map(a=>{
      const s=a.summary||{};return '<tr><td><strong>'+escapeHTML(a.name)+'</strong></td><td>'+escapeHTML(localDate(a.createdAt))+'</td><td>'+escapeHTML(a.createdBy)+'</td><td>'+statusLabel(a)+'</td><td>'+Number(s.total||0).toLocaleString()+'</td><td>'+Number(s.checked||0).toLocaleString()+'</td><td>'+Number(s.selected||0).toLocaleString()+'</td><td>'+Number(s.errors||0).toLocaleString()+'</td><td><button class="dashboard-button is-secondary" data-recruitment-open="'+escapeHTML(a.id)+'" type="button">Open</button></td></tr>';
    }).join(""):'<tr><td colspan="9">No saved analyses found.</td></tr>';
    $("recruitmentListPage").textContent="Page "+list.page+" / "+list.pages+" · "+list.total+" analyses";
    $("recruitmentListPrev").disabled=list.page<=1;
    $("recruitmentListNext").disabled=list.page>=list.pages;
    $("recruitmentActiveNote").textContent=list.active?"Active: "+list.active.name+" · lease until "+localDate(Number(list.active.lease_until)*1000):"No active scan";
    $("recruitmentAnalysisRows").querySelectorAll("[data-recruitment-open]").forEach(button=>button.addEventListener("click",()=>void guarded(()=>openAnalysis(button.dataset.recruitmentOpen))));
    syncControls();
  }
  async function loadList() {
    const request=++listRequest;
    const payload=await server("state",{params:{page:listPage,search:$("recruitmentAnalysisSearch")?.value||""}});
    if(request!==listRequest)return;
    adminState.analyses=payload.analyses;listPage=payload.analyses.page;renderList();
  }
  function renderResultsPage(page) {
    const tbody=$("recruitmentRows");if(!tbody)return;
    const rows=page?.items||[];
    tbody.innerHTML=rows.length?rows.map(row=>{
      const d=row.data||{},decision=row.decision==="error"?"API error":row.decision==="pending"?"Pending":row.selected?"Selected":"Rejected";
      return '<tr><td><a target="_blank" rel="noopener noreferrer" href="https://www.chess.com/member/'+encodeURIComponent(row.username)+'">'+escapeHTML(row.username)+'</a></td><td><span class="p2k-recruitment-decision '+(row.selected?"is-selected":row.decision==="rejected"?"is-rejected":"")+'">'+decision+'</span></td>'+[d.daily_rating,d.daily_rd,d.timeout_percent,d.current_daily_games,d.completed_daily_games,d.avg_seconds_per_move,displayDays(d.last_online_days),displayDays(d.account_age_days),d.p2k_state,row.reason].map(v=>'<td>'+escapeHTML(v??"—")+'</td>').join("")+'</tr>';
    }).join(""):'<tr><td colspan="12">No matching results.</td></tr>';
    $("recruitmentRowsNote").textContent=page?"Page "+page.page+" / "+page.pages+" · "+Number(page.total).toLocaleString()+" matching candidates":"No results";
    $("recruitmentResultPrev").disabled=!page||page.page<=1;
    $("recruitmentResultNext").disabled=!page||page.page>=page.pages;
  }
  async function loadResults() {
    const id=adminState.run?.id,request=++resultRequest;
    if(!id){renderResultsPage(null);return;}
    const payload=await server("results",{params:{id,page:resultPage,size:RESULT_PAGE_SIZE,filter:$("recruitmentResultFilter")?.value||"all",search:$("recruitmentResultSearch")?.value||""}});
    if(request!==resultRequest||adminState.run?.id!==id)return;
    resultPage=payload.results.page;renderResultsPage(payload.results);
  }
  function populateAnalysis(run) {
    composing=false;clearScanTelemetry();adminState.run=run;
    if($("recruitmentName"))$("recruitmentName").value=run.name;
    if($("recruitmentCandidates"))$("recruitmentCandidates").value=(run.candidates||[]).join("\n");
    applyCriteria(run.criteria||{});resultPage=1;
    if($("recruitmentResultFilter"))$("recruitmentResultFilter").value="all";
    if($("recruitmentResultSearch"))$("recruitmentResultSearch").value="";
    renderRun(run);
  }
  async function openAnalysis(id) {
    const payload=await server("detail",{params:{id,candidates:1}});
    populateAnalysis(payload.run);await loadResults();
    setStatus(statusLabel(payload.run)+" · "+Number(payload.run.summary.pending||0).toLocaleString()+" pending candidates.");
  }
  function newAnalysis() {
    composing=true;adminState.run=null;clearScanTelemetry();
    $("recruitmentName").value="";$("recruitmentCandidates").value="";
    applyCriteria(DEFAULT_CRITERIA);resultPage=1;$("recruitmentResultFilter").value="all";$("recruitmentResultSearch").value="";
    renderRun(null);renderResultsPage(null);setStatus("Enter a name and candidate list, then save or start this independent analysis.");
    $("recruitmentName").focus();
  }
  async function guarded(work) {
    if(busy||activeScan)return;
    busy=true;syncControls();
    try{await work();}catch(error){setStatus(error?.message||String(error),true);}
    finally{busy=false;syncControls();}
  }
  async function saveDraft() {
    const run=adminState.run;
    if(!composing&&run?.status!=="draft")return run;
    const payload=await server(run?"update-draft":"create",{method:"POST",body:{id:run?.id,name:$("recruitmentName").value,candidates:$("recruitmentCandidates").value,criteria:criteriaFromForm()}});
    populateAnalysis(payload.run);await loadList();await loadResults();
    setStatus("Draft saved · "+Number(payload.run.summary.total).toLocaleString()+" unique candidates.");return payload.run;
  }

  function applyCriteria(criteria = {}) {
    const fields = [["recruitmentMinRating","minRating"],["recruitmentMaxRating","maxRating"],["recruitmentMaxTimeout","maxTimeout"],["recruitmentMaxRd","maxRd"],["recruitmentMinGames","minGames"],["recruitmentMaxGames","maxGames"],["recruitmentMinCompleted","minCompleted"],["recruitmentMaxOffline","maxOffline"],["recruitmentMaxSpm","maxSpm"],["recruitmentMinAge","minAge"]];
    fields.forEach(([id, key]) => { const node = $(id); if (node && Object.prototype.hasOwnProperty.call(criteria, key)) node.value = criteria[key] === null ? "" : String(criteria[key]); });
    if ($("recruitmentExcludeFormer") && Object.prototype.hasOwnProperty.call(criteria, "excludeFormer")) $("recruitmentExcludeFormer").checked = Boolean(criteria.excludeFormer);
  }

  function criteriaFromForm() {
    const numeric = id => { const raw = $(id)?.value?.trim() ?? ""; return raw === "" ? null : Number(raw); };
    return { minRating:numeric("recruitmentMinRating"), maxRating:numeric("recruitmentMaxRating"), maxTimeout:numeric("recruitmentMaxTimeout"), maxRd:numeric("recruitmentMaxRd"), minGames:numeric("recruitmentMinGames"), maxGames:numeric("recruitmentMaxGames"), minCompleted:numeric("recruitmentMinCompleted"), maxOffline:numeric("recruitmentMaxOffline"), maxSpm:numeric("recruitmentMaxSpm"), minAge:numeric("recruitmentMinAge"), excludeFormer:Boolean($("recruitmentExcludeFormer")?.checked) };
  }

  async function chessJSON(url, attempts = 3, signal = null) {
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

  function daysSinceUnix(value) { const timestamp = number(value); return timestamp !== null && timestamp > 0 ? Math.max(0,(Date.now()/1000-timestamp)/86400) : null; }

  async function loadMembershipStates(usernames, generation) {
    const map = new Map();
    let prepared = 0;
    for (let index = 0; index < usernames.length; index += MEMBERSHIP_BATCH) {
      if (!activeScan || generation !== scanGeneration || scanControl?.pauseRequested) break;
      const chunk = usernames.slice(index, index + MEMBERSHIP_BATCH);
      setStatus(`Preparing Green membership · ${prepared.toLocaleString("en-GB")} / ${usernames.length.toLocaleString("en-GB")} candidates…`);
      setPreparationProgress(prepared, usernames.length);
      const payload = await server("membership-batch", { method:"POST", body:{ usernames:chunk } });
      for (const [key, state] of Object.entries(payload?.states || {})) map.set(String(key).toLowerCase(), String(state || "none"));
      prepared += chunk.length;
      setPreparationProgress(prepared, usernames.length);
    }
    usernames.forEach(username => { const key = username.toLowerCase(); if (!map.has(key)) map.set(key,"none"); });
    return map;
  }

  async function scanCandidate(username, p2kState, criteria, signal = null) {
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

  async function runScan(claim) {
    const run=claim.run,pending=claim.pending||[],generation=++scanGeneration;
    activeScan=true;adminState.run={...adminState.run,...run};
    const controller=new AbortController();
    const control={id:run.id,lease:claim.lease,pauseRequested:false,controller,failure:null};
    scanControl=control;scanAbortController=controller;
    const buffer=[];let chain=Promise.resolve(),inFlight=0,stopped=false,heartbeatBusy=false;
    const fail=error=>{if(!control.failure)control.failure=error;controller.abort();};
    const leaseBody={id:control.id,lease:control.lease};
    function updateRun(meta){if(adminState.run?.id===meta?.id&&Number(meta?.summary?.checked||0)>=Number(adminState.run?.summary?.checked||0)&&!(adminState.run?.status==="completed"&&meta.status!=="completed")){adminState.run={...adminState.run,...meta};renderRun();}}
    const flush=(force=false)=>{
      while(buffer.length>=CHECKPOINT_BATCH||(force&&buffer.length)){
        const batch=buffer.splice(0,Math.min(CHECKPOINT_BATCH,buffer.length));inFlight++;
        chain=chain.then(async()=>{
          try{
            if(control.failure)return;
            const payload=await server("checkpoint",{method:"POST",body:{...leaseBody,results:batch}});
            updateRun(payload.run);
            void loadResults().catch(()=>{});
          }catch(error){fail(error);}
          finally{inFlight--;}
        });
      }
      return chain;
    };
    const heartbeat=setInterval(async()=>{
      if(stopped||heartbeatBusy||control.failure||adminState.run?.status==="completed")return;
      heartbeatBusy=true;
      try{const payload=await server("heartbeat",{method:"POST",body:leaseBody});if(!stopped&&adminState.run?.status!=="completed")updateRun(payload.run);}
      catch(error){if(!stopped&&adminState.run?.status!=="completed"){try{const fresh=await server("detail",{params:{id:run.id}});if(fresh.run?.status==="completed")updateRun(fresh.run);else fail(error);}catch(_){fail(error);}}}
      finally{heartbeatBusy=false;}
    },30000);
    const periodic=setInterval(()=>{if(!control.failure)flush(true);},8000);
    beginScanTelemetry(run.id,Number(run.summary.total),Number(run.summary.checked));renderRun();
    try{
      setStatus("Preparing Green membership · 0 / "+pending.length.toLocaleString()+" candidates…");
      const membership=await loadMembershipStates(pending,generation);
      if(!control.pauseRequested&&!control.failure){
        if(window.P2K_REAL_OAUTH_READY)await window.P2K_REAL_OAUTH_READY;
        const api=window.P2K_API_CLIENT,diag=api?.diagnostics?.()||{};
        if(!api?.processPriority||!api?.json||diag.oauthBearerMode!==true)throw Error("Recruitment requires the authenticated OAuth Bearer API scheduler. Log in with Chess.com and retry.");
        setStatus("Scanning · OAuth Bearer scheduler · incremental secured checkpoints.");
        await api.processPriority(pending,async username=>{
          if(controller.signal.aborted)throw new DOMException("Scan paused","AbortError");
          const row=await scanCandidate(username,membership.get(text(username).toLowerCase())||"none",run.criteria||{},controller.signal);
          // A fully evaluated candidate must survive a concurrent Pause click.
          if(control.failure||generation!==scanGeneration)throw new DOMException("Scan interrupted","AbortError");
          buffer.push(row);noteCandidateSettled();flush(false);
          // Bound queued batches if SQLite/Green is temporarily slower than acquisition.
          if(inFlight>=5)await chain;
          return row;
        },{signal:controller.signal,getKey:username=>text(username).toLowerCase(),getPriority:()=>60});
      }
    }catch(error){if(!controller.signal.aborted)fail(error);}
    finally{
      clearInterval(periodic);
      if(!control.failure)await flush(true);
      else await chain;
      stopped=true;clearInterval(heartbeat);
      try{
        if(adminState.run?.status!=="completed"){
          const payload=await server("pause",{method:"POST",body:leaseBody});
          updateRun(payload.run);
        }
      }catch(error){if(!control.failure)control.failure=error;}
      activeScan=false;scanAbortController=null;scanControl=null;finishScanTelemetry();
      try{
        const payload=await server("detail",{params:{id:run.id}});
        updateRun(payload.run);await loadList();await loadResults();
      }catch(error){if(!control.failure)control.failure=error;}
      renderRun();
      if(control.failure)setStatus("Scan interrupted: "+(control.failure.message||control.failure)+". Saved checkpoints are retained; resume pending candidates.",true);
      else setStatus(statusLabel(adminState.run)+" · "+Number(adminState.run?.summary?.checked||0).toLocaleString()+" checked · "+Number(adminState.run?.summary?.pending||0).toLocaleString()+" pending.");
    }
  }

  async function startScan() {
    if(busy||activeScan)return;
    busy=true;syncControls();let claim=null;
    try{
      const run=await saveDraft();
      if(!run)throw Error("Create or open an analysis first.");
      claim=await server("resume",{method:"POST",body:{id:run.id}});
      activeScan=true;adminState.run={...adminState.run,...claim.run};
    }catch(error){setStatus(error?.message||String(error),true);}
    finally{busy=false;syncControls();}
    if(claim)await runScan(claim);
  }
  function pauseScan(){
    if(!scanControl||scanControl.pauseRequested)return;
    scanControl.pauseRequested=true;setStatus("Pausing… saving all completed candidate evaluations.");
    syncControls();scanControl.controller.abort();
  }
  function downloadCsv(scope) {
    const id=adminState.run?.id;if(!id)return;
    const url=recruitmentEndpoint("csv");url.searchParams.set("id",id);url.searchParams.set("scope",scope);
    const anchor=document.createElement("a");anchor.href=url.href;anchor.download="";document.body.appendChild(anchor);anchor.click();anchor.remove();
  }
  function bindRecruitment(){
    let listTimer=0,resultTimer=0;
    const refreshList=()=>loadList().catch(error=>setStatus(error?.message||String(error),true));
    const refreshResults=()=>loadResults().catch(error=>setStatus(error?.message||String(error),true));
    $("recruitmentNew")?.addEventListener("click",()=>{if(!busy&&!activeScan)newAnalysis();});
    $("recruitmentClose")?.addEventListener("click",()=>{if(busy||activeScan)return;composing=false;adminState.run=null;clearScanTelemetry();renderRun(null);setStatus("Choose an analysis or create a new one.");});
    $("recruitmentRefresh")?.addEventListener("click",()=>{void refreshList();if(adminState.run&&!activeScan&&!busy)void guarded(()=>openAnalysis(adminState.run.id));});
    $("recruitmentAnalysisSearch")?.addEventListener("input",()=>{clearTimeout(listTimer);listPage=1;listTimer=setTimeout(refreshList,250);});
    $("recruitmentListPrev")?.addEventListener("click",()=>{listPage=Math.max(1,listPage-1);void refreshList();});
    $("recruitmentListNext")?.addEventListener("click",()=>{listPage++;void refreshList();});
    $("recruitmentSaveDraft")?.addEventListener("click",()=>void guarded(saveDraft));
    $("recruitmentStart")?.addEventListener("click",()=>void startScan());
    $("recruitmentPause")?.addEventListener("click",pauseScan);
    $("recruitmentRetry")?.addEventListener("click",()=>void guarded(async()=>{
      const id=adminState.run?.id;if(!id)return;
      const payload=await server("retry-errors",{method:"POST",body:{id}});
      clearScanTelemetry();renderRun({...adminState.run,...payload.run});await loadList();await loadResults();setStatus("API errors returned to pending. Resume this analysis to retry only pending candidates.");
    }));
    $("recruitmentDuplicate")?.addEventListener("click",()=>void guarded(async()=>{
      const run=adminState.run;if(!run)return;
      const name=window.prompt("Name of the new analysis",("Copy of "+run.name).slice(0,120));if(name===null)return;
      const payload=await server("duplicate",{method:"POST",body:{id:run.id,name}});
      populateAnalysis(payload.run);await loadList();await loadResults();setStatus("Independent draft created. Edit candidates or criteria before starting.");
    }));
    $("recruitmentDelete")?.addEventListener("click",()=>void guarded(async()=>{
      const run=adminState.run;if(!run||!window.confirm('Permanently delete analysis "'+run.name+'" and its results? Other analyses are unaffected.'))return;
      await server("delete",{method:"POST",body:{id:run.id,confirm:true}});
      composing=false;adminState.run=null;clearScanTelemetry();renderRun(null);await loadList();setStatus("Analysis deleted.");
    }));
    $("recruitmentResultFilter")?.addEventListener("change",()=>{resultPage=1;void refreshResults();});
    $("recruitmentResultSearch")?.addEventListener("input",()=>{clearTimeout(resultTimer);resultPage=1;resultTimer=setTimeout(refreshResults,250);});
    $("recruitmentResultPrev")?.addEventListener("click",()=>{resultPage=Math.max(1,resultPage-1);void refreshResults();});
    $("recruitmentResultNext")?.addEventListener("click",()=>{resultPage++;void refreshResults();});
    $("recruitmentCsv")?.addEventListener("click",()=>downloadCsv("selected"));
    $("recruitmentCsvAll")?.addEventListener("click",()=>downloadCsv("all"));
    renderRun();
  }

  function ensureRecruitmentDetail() {
    if(!isRecruitmentRoute())return;
    const host=$("adminShellNativeDetailHost");
    if(!host||host.hidden)return;
    let mount=$("p2kRecruitmentNativeHost");
    if(!mount||mount.parentElement!==host){mount?.remove();mount=document.createElement("div");mount.id="p2kRecruitmentNativeHost";mount.className="dashboard-admin-native-detail-host";host.replaceChildren(mount);detailMounted=false;}
    if(!detailMounted||!$("p2kRecruitmentNative")){detailMounted=true;mount.innerHTML=recruitmentMarkup();bindRecruitment();loadList().catch(error=>setStatus(`Unable to load Recruitment analyses: ${error.message||error}`,true));if(adminState.run){if(!activeScan)void guarded(()=>openAnalysis(adminState.run.id));else{const run=adminState.run;if($("recruitmentName"))$("recruitmentName").value=run.name;if($("recruitmentCandidates"))$("recruitmentCandidates").value=(run.candidates||[]).join("\n");applyCriteria(run.criteria);renderRun();void loadResults().catch(()=>{});}}}
  }

  function reconcile(){
    installStyles();fixMembersInsightsHeader();
    const route=routeState();if(route.isAdmin)document.body?.classList.add("p2k-admin-canonical");
    if(isRecruitmentRoute())ensureRecruitmentDetail();else ensureRecruitmentCard();
  }
  function mount(){installStyles();window.addEventListener("p2k-admin-shell-route",reconcile);reconcile();}

  if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",mount,{once:true});else mount();
})();
