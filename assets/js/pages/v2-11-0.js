/* Promote to King v2.11.0: canonical toggle Administration + native Recruitment. */
(() => {
  "use strict";

  const VERSION = "2.11.0";
  const MEMBERSHIP_BATCH = 2000;
  const CHECKPOINT_BATCH = 500;
  const RESULT_RENDER_LIMIT = 500;
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
    nativeDetails: Object.freeze({
      members: Object.freeze({ recruitment: Object.freeze({ title: "Recruitment", source: "Green Core + Chess.com", mode: "Native" }) })
    })
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

  function syncEarlyRouteClass() {
    document.documentElement.classList.toggle("p2k-recruitment-route", isRecruitmentRoute());
  }
  syncEarlyRouteClass();

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

  async function server(action, { method = "GET", body = null } = {}) {
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
    const response = await fetch(recruitmentEndpoint(action), {
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
        <p>Maintain the candidate pool and evaluate prospective members against Daily activity, reliability and membership criteria.</p>
        <div class="dashboard-admin-shell-metrics">
          <div class="dashboard-admin-shell-metric"><span>Candidates</span><strong id="adminRecruitmentCandidates">—</strong><small>Saved pool</small></div>
          <div class="dashboard-admin-shell-metric"><span>Checked</span><strong id="adminRecruitmentChecked">—</strong><small>Current checkpoint</small></div>
          <div class="dashboard-admin-shell-metric"><span>Selected</span><strong id="adminRecruitmentSelected">—</strong><small>Current checkpoint</small></div>
        </div>
        <div class="dashboard-admin-shell-meta"><span><b>Freshness</b><em id="adminShellFresh_recruitment">Checking…</em></span><span><b>Source</b><em>Green Core + Chess.com</em></span></div>
        <footer class="dashboard-admin-shell-actions"><a class="dashboard-button" href="${escapeHTML(adminHref("members", "recruitment"))}">Open Recruitment</a></footer>`;
      grid.appendChild(card);
    }
    if (cardLoading) return;
    cardLoading = true;
    server("state").then(payload => {
      const poolCount = Array.isArray(payload?.pool?.candidates) ? payload.pool.candidates.length : 0;
      const summary = payload?.run?.summary || {};
      if ($("adminRecruitmentCandidates")) $("adminRecruitmentCandidates").textContent = String(poolCount);
      if ($("adminRecruitmentChecked")) $("adminRecruitmentChecked").textContent = String(Number(summary.checked || 0));
      if ($("adminRecruitmentSelected")) $("adminRecruitmentSelected").textContent = String(Number(summary.selected || 0));
      const badge = $("adminShellStatus_recruitment");
      if (badge) { badge.textContent = payload?.run?.status ? text(payload.run.status).replace(/^./, c => c.toUpperCase()) : "Ready"; badge.className = "dashboard-admin-shell-status is-good"; }
      if ($("adminShellFresh_recruitment")) $("adminShellFresh_recruitment").textContent = "Server state · live";
    }).catch(error => {
      const badge = $("adminShellStatus_recruitment");
      if (badge) { badge.textContent = "Unavailable"; badge.className = "dashboard-admin-shell-status is-bad"; }
      if ($("adminShellFresh_recruitment")) $("adminShellFresh_recruitment").textContent = error?.message || "Unable to read state";
    }).finally(() => { cardLoading = false; });
  }

  let adminState = { pool: null, run: null };
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
    return `<section class="p2k-recruitment-native" id="p2kRecruitmentNative" data-release="${VERSION}">
      <div class="p2k-recruitment-native-grid">
        <fieldset><legend>Candidate pool</legend><div class="p2k-recruitment-field"><label for="recruitmentCandidates">Usernames or Chess.com player URLs</label><textarea class="p2k-recruitment-textarea" id="recruitmentCandidates" spellcheck="false" placeholder="candidate_alpha&#10;https://www.chess.com/member/candidate_beta"></textarea></div><div id="recruitmentSavedStatus" class="p2k-recruitment-help">No saved list loaded.</div><div class="p2k-recruitment-actions"><button class="dashboard-button" id="recruitmentSavePool" type="button">Save candidate list</button><button class="dashboard-button is-secondary" id="recruitmentLoadPool" type="button">Load saved list</button><button class="dashboard-button is-secondary" id="recruitmentClearEditor" type="button">Clear editor</button></div><div class="p2k-recruitment-help">Up to 100,000 normalized unique usernames can be stored. Duplicate names and supported Chess.com profile URLs are normalized server-side.</div></fieldset>
        <fieldset><legend>Eligibility settings</legend><div class="p2k-recruitment-criteria">${criteriaMarkup()}</div><div class="p2k-recruitment-checks"><label><input type="checkbox" checked disabled> Exclude current P2K members.</label><label><input id="recruitmentExcludeFormer" type="checkbox"> Exclude former P2K members as well.</label><label><input type="checkbox" checked disabled> Reject closed / unavailable accounts automatically.</label></div><div class="p2k-recruitment-help">Empty numeric fields mean “doesn’t matter”. A resumed run keeps its original criteria snapshot.</div></fieldset>
      </div>
      <div class="p2k-recruitment-actions"><button class="dashboard-button" id="recruitmentStart" type="button">Start / resume scan</button><button class="dashboard-button is-secondary" id="recruitmentPause" type="button" disabled>Pause</button><button class="dashboard-button is-secondary" id="recruitmentRestart" type="button">Restart scan</button><button class="dashboard-button is-secondary" id="recruitmentCsv" type="button" disabled>Download selected CSV</button></div>
      <div class="p2k-recruitment-summary"><div><span>Candidates</span><strong id="recruitmentMetricTotal">0</strong></div><div><span>Checked</span><strong id="recruitmentMetricChecked">0</strong></div><div><span>Selected</span><strong id="recruitmentMetricSelected">0</strong></div><div><span>Errors / unavailable</span><strong id="recruitmentMetricErrors">0</strong></div><div><span>Remaining</span><strong id="recruitmentMetricRemaining">0</strong></div><div><span>Candidates / second</span><strong id="recruitmentMetricRate">—</strong><small id="recruitmentMetricRateNote">Rolling 10 s</small></div><div><span>ETA</span><strong id="recruitmentMetricEta">—</strong><small id="recruitmentMetricEtaNote">Elapsed —</small></div><div><span>OAuth gateway</span><strong id="recruitmentMetricOauth">—</strong><small id="recruitmentMetricOauthNote">Shared full-throttle transport</small></div></div>
      <div class="p2k-recruitment-progress"><span id="recruitmentProgressBar"></span></div><div class="p2k-recruitment-status" id="recruitmentStatus">Ready. Save the candidate pool, then start a scan.</div>
      <div class="p2k-recruitment-table-wrap"><table class="p2k-recruitment-table"><thead><tr><th>Candidate</th><th>Decision</th><th>Daily rating</th><th>Timeout %</th><th>Current games</th><th>Completed games</th><th>Last online</th><th>Account age</th><th>P2K state</th><th>Reason</th></tr></thead><tbody id="recruitmentRows"><tr><td colspan="10">No scan results yet.</td></tr></tbody></table></div>
      <div id="recruitmentRowsNote" class="p2k-recruitment-help"></div>
      <p class="p2k-recruitment-help"><strong>Data path:</strong> P2K membership is resolved in Green Core in large batches before Chess.com scanning and revalidated at every server checkpoint. Chess.com profile supplies account state, joined and last-online timestamps; Daily stats supply rating, RD, completed record, timeout percentage and average move time when available; current Daily games supply ongoing-game count.</p>
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

  function setPoolStatus(pool) {
    const host = $("recruitmentSavedStatus");
    if (!host) return;
    const count = Array.isArray(pool?.candidates) ? pool.candidates.length : 0;
    const max = Number(pool?.maximumCandidates || 100000);
    host.textContent = count ? `${count.toLocaleString("en-GB")} normalized unique username${count === 1 ? "" : "s"} · revision ${Number(pool?.revision || 0)} · maximum ${max.toLocaleString("en-GB")}` : `No saved candidate list · maximum ${max.toLocaleString("en-GB")}.`;
  }

  function mergeCheckpoint(meta, accepted = []) {
    const current = adminState.run || { results: [], candidates: [] };
    const byKey = new Map((current.results || []).map((row, index) => [text(row?.username).toLowerCase(), index]));
    for (const row of accepted || []) {
      const key = text(row?.username).toLowerCase();
      if (!key) continue;
      if (byKey.has(key)) current.results[byKey.get(key)] = row;
      else { byKey.set(key, current.results.length); current.results.push(row); }
    }
    const previousChecked = Number(current.summary?.checked || current.results.length || 0);
    const nextChecked = Number(meta?.summary?.checked || 0);
    if (nextChecked >= previousChecked) {
      Object.assign(current, meta || {});
      current.summary = meta?.summary || current.summary;
    }
    adminState.run = current;
    renderRun(current);
  }

  function renderRun(run = adminState.run) {
    adminState.run = run && Object.keys(run).length ? run : null;
    const summary = adminState.run?.summary || {};
    const total = Number(summary.total ?? adminState.run?.candidates?.length ?? 0);
    const checked = Number(summary.checked ?? adminState.run?.results?.length ?? 0);
    const selected = Number(summary.selected || 0), errors = Number(summary.errors || 0);
    if ($("recruitmentMetricTotal")) $("recruitmentMetricTotal").textContent = total.toLocaleString("en-GB");
    if ($("recruitmentMetricChecked")) $("recruitmentMetricChecked").textContent = checked.toLocaleString("en-GB");
    if ($("recruitmentMetricSelected")) $("recruitmentMetricSelected").textContent = selected.toLocaleString("en-GB");
    if ($("recruitmentMetricErrors")) $("recruitmentMetricErrors").textContent = errors.toLocaleString("en-GB");
    if ($("recruitmentProgressBar")) $("recruitmentProgressBar").style.width = `${total ? Math.min(100, checked * 100 / total) : 0}%`;
    if ($("recruitmentCsv")) $("recruitmentCsv").disabled = selected < 1;
    if ($("recruitmentPause")) $("recruitmentPause").disabled = !activeScan;
    if ($("recruitmentStart")) $("recruitmentStart").disabled = activeScan;
    renderScanTelemetry();
    const tbody = $("recruitmentRows");
    if (!tbody) return;
    const rows = Array.isArray(adminState.run?.results) ? adminState.run.results : [];
    if (!rows.length) { tbody.innerHTML = '<tr><td colspan="10">No scan results yet.</td></tr>'; if ($("recruitmentRowsNote")) $("recruitmentRowsNote").textContent = ""; return; }
    const visible = rows.length > RESULT_RENDER_LIMIT ? rows.slice(-RESULT_RENDER_LIMIT) : rows;
    tbody.innerHTML = visible.map(row => {
      const data = row?.data || {};
      const decision = row?.selected ? "Selected" : "Rejected";
      return `<tr><td>${escapeHTML(row?.username)}</td><td><span class="p2k-recruitment-decision ${row?.selected ? "is-selected" : "is-rejected"}">${decision}</span></td><td>${data.daily_rating ?? "—"}</td><td>${data.timeout_percent ?? "—"}</td><td>${data.current_daily_games ?? "—"}</td><td>${data.completed_daily_games ?? "—"}</td><td>${displayDays(data.last_online_days)}</td><td>${displayDays(data.account_age_days)}</td><td>${escapeHTML(data.p2k_state || "none")}</td><td>${escapeHTML(row?.reason || "—")}</td></tr>`;
    }).join("");
    if ($("recruitmentRowsNote")) $("recruitmentRowsNote").textContent = rows.length > visible.length ? `Showing the most recent ${visible.length.toLocaleString("en-GB")} of ${rows.length.toLocaleString("en-GB")} checkpointed results. The CSV export remains complete.` : `${rows.length.toLocaleString("en-GB")} checkpointed result${rows.length === 1 ? "" : "s"}.`;
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

  async function loadState({ populateEditor = true } = {}) {
    const payload = await server("state");
    adminState.pool = payload.pool || { candidates: [], maximumCandidates: 100000 };
    adminState.run = payload.run && Object.keys(payload.run).length ? payload.run : null;
    setPoolStatus(adminState.pool);
    if (populateEditor && $("recruitmentCandidates")) $("recruitmentCandidates").value = (adminState.pool.candidates || []).join("\n");
    if (adminState.run?.criteria) applyCriteria(adminState.run.criteria);
    renderRun();
    const status = adminState.run?.status;
    if (status === "completed") setStatus(`Completed · ${Number(adminState.run.summary?.checked || 0).toLocaleString("en-GB")} candidates checked · ${Number(adminState.run.summary?.selected || 0).toLocaleString("en-GB")} selected.`);
    else if (status === "paused") setStatus(`Paused · ${Number(adminState.run.summary?.pending || 0).toLocaleString("en-GB")} candidates remain. Start / resume continues only pending candidates.`);
    else if (status === "running") setStatus(`Checkpoint recovered · ${Number(adminState.run.summary?.pending || 0).toLocaleString("en-GB")} candidates remain. Start / resume continues the run.`);
    return payload;
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
      if (!activeScan || generation !== scanGeneration) break;
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

  async function checkpoint(runId, batch) {
    if (!batch.length) return;
    const payload = await server("checkpoint", { method:"POST", body:{ runId, results:batch } });
    mergeCheckpoint(payload?.run || {}, payload?.results || []);
  }

  async function runScan(run) {
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

  async function startScan(){if(activeScan)return;try{setStatus("Starting or resuming the server checkpoint…");const payload=await server("start",{method:"POST",body:{criteria:criteriaFromForm()}});adminState.run=payload.run;if(payload.run?.criteria)applyCriteria(payload.run.criteria);renderRun(payload.run);await runScan(payload.run);}catch(error){activeScan=false;renderRun();setStatus(error.message||String(error),true);}}
  async function pauseScan(){scanGeneration+=1;activeScan=false;scanAbortController?.abort();scanAbortController=null;finishScanTelemetry();renderRun();try{const payload=await server("pause",{method:"POST",body:{}});if(adminState.run&&payload.run){Object.assign(adminState.run,payload.run);adminState.run.summary=payload.run.summary||adminState.run.summary;}renderRun();setStatus(`Paused · ${Number(payload.run?.summary?.pending||0).toLocaleString("en-GB")} candidates remain. Start / resume continues only pending candidates.`);}catch(error){setStatus(error.message||String(error),true);}}

  function bindRecruitment(){
    $("recruitmentLoadPool")?.addEventListener("click",()=>loadState({populateEditor:true}).catch(error=>setStatus(error.message||String(error),true)));
    $("recruitmentClearEditor")?.addEventListener("click",()=>{if($("recruitmentCandidates"))$("recruitmentCandidates").value="";});
    $("recruitmentSavePool")?.addEventListener("click",async()=>{try{setStatus("Normalizing and saving candidate pool…");const payload=await server("save-pool",{method:"POST",body:{candidates:$("recruitmentCandidates")?.value||""}});adminState.pool=payload.pool;setPoolStatus(payload.pool);if($("recruitmentCandidates"))$("recruitmentCandidates").value=(payload.pool?.candidates||[]).join("\n");setStatus(`Candidate pool saved · ${Number(payload.pool?.candidates?.length||0).toLocaleString("en-GB")} normalized unique usernames.`);}catch(error){setStatus(error.message||String(error),true);}});
    $("recruitmentStart")?.addEventListener("click",startScan);$("recruitmentPause")?.addEventListener("click",pauseScan);
    $("recruitmentRestart")?.addEventListener("click",async()=>{if(!window.confirm("Clear the current Recruitment run checkpoint? The saved candidate pool will be kept."))return;scanGeneration+=1;activeScan=false;scanAbortController?.abort();scanAbortController=null;clearScanTelemetry();try{await server("restart",{method:"POST",body:{}});adminState.run=null;renderRun(null);setStatus("Run checkpoint cleared. The saved candidate pool is unchanged.");}catch(error){setStatus(error.message||String(error),true);}});
    $("recruitmentCsv")?.addEventListener("click",()=>{const anchor=document.createElement("a");anchor.href=recruitmentEndpoint("csv").href;anchor.download="p2k-recruitment-selected.csv";document.body.appendChild(anchor);anchor.click();anchor.remove();});
  }

  function ensureRecruitmentDetail() {
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

  if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",mount,{once:true});else mount();
})();
