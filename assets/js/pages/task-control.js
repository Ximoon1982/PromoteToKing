(() => {
  "use strict";
  const API = "server/control/public/api.php";
  const GREEN_API = "server/team-points-green/public/api.php";
  const SERVER_TASK_FALLBACKS = [
    ["match-tracking","Match monitoring",3600,"api/track-upcoming-league-matches/"],
    ["tournaments","Tournaments",600,"server/tournaments/public/cron.php"],
  ].map(([task_key,label,expected_interval_seconds,legacy_endpoint])=>({task_key,label,expected_interval_seconds,legacy_endpoint,status:"unavailable",health:"unavailable",health_message:"Server status is still loading or temporarily unavailable.",last_message:"Server status unavailable.",last_success_at:null,work:{summary:{},deferred:true},server_available:false}));
  const state = { green: null, payload: null, selected: "", connected: false, timer: null, trackedMatches: [], taskDetails: new Map(), taskDetailLoads: new Map(), reconciliationRefreshTimer:null, reconciliationRefreshBusy:false, reconciliationLastRefreshAt:0, playerReconciliationLastRefreshAt:0, reconciliationSort:{club:{key:"point_delta",direction:"desc"},player:{key:"point_delta",direction:"desc"}}, reconciliationEffect:{club:"all",player:"all"} };
  const RECONSTRUCTION_TASK = "fresh-points-reconstruction";
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? "").replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&#039;");
  const number = value => Number(value || 0).toLocaleString("en-GB", { maximumFractionDigits: 0 });
  const rateNumber = value => Number(value || 0).toLocaleString("en-GB", { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const setText = (id, value) => { const node = $(id); if (node) node.textContent = String(value ?? ""); return node; };
  const setHTML = (id, value) => { const node = $(id); if (node) node.innerHTML = String(value ?? ""); return node; };
  const setHidden = (id, value) => { const node = $(id); if (node) node.hidden = Boolean(value); return node; };

  function sessionUsername() {
    return String(window.P2K_AUTH?.getSession?.()?.username || window.P2K_ADMIN_USERNAME || window.parent?.P2K_AUTH?.getSession?.()?.username || "").trim();
  }
  function serverUtcDate(value) {
    if (!value) return null;
    let raw=String(value).trim();
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(raw)) raw=raw.replace(" ","T")+"Z";
    else if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(raw)) raw+="Z";
    const date=new Date(raw);return Number.isFinite(date.getTime())?date:null;
  }
  function localGreenTimestamp(value) {
    if (!value) return "—";
    const date=serverUtcDate(value);if(!date)return String(value);
    try{return new Intl.DateTimeFormat("en-GB",{day:"2-digit",month:"short",year:"numeric",hour:"2-digit",minute:"2-digit",second:"2-digit",timeZone:"UTC",timeZoneName:"short"}).format(date);}catch(_){return date.toLocaleString("en-GB",{timeZone:"UTC",hour12:false});}
  }
  function relative(value) {
    if (!value) return "Never";
    const date = serverUtcDate(value);
    if (!Number.isFinite(date.getTime())) return String(value);
    const seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return `${seconds}s ago`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    return `${Math.floor(seconds / 86400)}d ago`;
  }
  function cadence(seconds) {
    if (seconds < 3600) return `every ${Math.round(seconds / 60)} minutes`;
    if (seconds < 86400) return `every ${Math.round(seconds / 3600)} hours`;
    return `every ${Math.round(seconds / 86400)} day`;
  }
  function healthClass(value) {
    return value === "healthy" ? "is-good" : value === "critical" || value === "unavailable" || value === "failed" ? "is-bad" : value === "paused" || value === "disabled" ? "is-paused" : value === "unknown" ? "is-loading" : "is-warn";
  }
  function setFeedback(id, text, type = "") {
    const node = $(id); if (!node) return;
    node.textContent = text; node.className = `control-feedback${type ? ` ${type}` : ""}`;
  }
  async function endpoint(action, { method = "GET", body = null, params = {}, timeoutMs = 30000 } = {}) {
    return window.P2K_TEAM_POINTS_CLIENT.endpointRequest(API, { action, method, body, params, username: sessionUsername(), timeoutMs });
  }
  async function greenEndpoint(action, { method = "GET", body = null, params = {}, timeoutMs = 15000 } = {}) {
    return window.P2K_TEAM_POINTS_CLIENT.endpointRequest(GREEN_API, { action, method, body, params, username: sessionUsername(), timeoutMs, serverTrafficClass: "foreground" });
  }
  function greenMetric(label,value,detail=""){return `<article class="metric"><span>${esc(label)}</span><strong>${esc(value??"—")}</strong>${detail?`<small>${esc(detail)}</small>`:""}</article>`;}
  function formatDuration(seconds){const n=Number(seconds);if(!Number.isFinite(n)||n<0)return "—";if(n<60)return `${n.toFixed(1)} s`;if(n<3600)return `${Math.floor(n/60)}m ${Math.round(n%60)}s`;return `${Math.floor(n/3600)}h ${Math.floor((n%3600)/60)}m`;}
  function quickMatchesEstimate(g){const p=g?.progress||{},inProgress=Number(p?.matches?.in_progress||0),due=Number(g?.integrity?.current_maintenance_due||0);let maintenance=0,detail=0,retry=0;for(const r of (Array.isArray(g?.request_metrics)?g.request_metrics:[])){const type=String(r.request_type||""),outcome=String(r.outcome||""),count=Number(r.request_count||0),ok=outcome==="200"||/accepted|success/i.test(outcome);if(type==="current_match_maintenance"){if(ok)maintenance+=count;else retry+=count;}else if(type==="match_detail"){if(ok)detail+=count;else retry+=count;}}const activeTotal=Math.max(inProgress,maintenance),historicalTotal=detail+due,processed=maintenance+detail,total=Math.max(processed,activeTotal+historicalTotal),remaining=Math.max(0,total-processed),percent=total>0?Math.max(0,Math.min(100,processed/total*100)):0;return {inProgress,due,maintenance,detail,retry,activeTotal,historicalTotal,processed,total,remaining,percent};}
  function quickMatchesProgressCard(est){return `<article class="control-card green-estimated-progress"><div class="section-head"><div><h3>Quick Matches</h3><p>estimated from live stage workload · ${number(est.processed)} / ${number(est.total)} · ${number(est.remaining)} remaining est.</p></div><strong>${est.percent.toFixed(1)}%</strong></div><div class="task-progress"><span style="width:${est.percent}%"></span></div><div class="green-progress-detail"><span>Active sweep ${number(est.maintenance)} / ${number(est.activeTotal)}</span><span>Historical refresh ${number(est.detail)} / ${number(est.historicalTotal)}</span><span>Live fact due ${number(est.due)}</span><span>Retry / failed ${number(est.retry)}</span></div></article>`;}
  function renderGreenBars(rows=[]){return rows.map(r=>{const total=Number(r.total_units??r.display_total_rows??r.total_rows??0),rawDone=Number(r.completed_units??r.processed_rows??0),done=String(r.status)==="pending"?0:Number(r.display_processed_rows??rawDone),failed=Number(r.failed_rows??r.error_rows??0),status=String(r.status||"pending"),stamp=localGreenTimestamp(r.completed_at||r.last_update_at||r.updated_at||r.started_at||""),error=r.last_error||"",mode=String(r.progress_mode||""),convergence=mode==="convergence"||String(r.lane_key||"")==="compat_reconciliation";let pct=status==="completed"?100:(total>0?Math.max(0,Math.min(99,done/total*100)):0),summary=`${status} · ${number(done)} / ${number(total)}`,badge=`${pct.toFixed(1)}%`;if(convergence&&status!=="completed"){let cursor={};try{cursor=JSON.parse(r.cursor_json||"{}")||{}}catch(_){cursor={}}const attempts=Number(r.attempted_rows??rawDone),remaining=r.last_full_pass_remaining??cursor.last_full_pass_remaining,passNo=Number(r.pass_no??cursor.pass_no??1);summary=`${status} · ${number(attempts)} projection attempts · pass ${number(passNo)}`+(remaining!==undefined&&remaining!==null?` · ${number(remaining)} remaining at last full-pass check`:" · scanning for remaining drift");pct=99;badge="Converging";}else if(mode==="audit"&&status==="pending"){summary="pending · exact check count determined when audit runs";pct=0;badge="Pending";}if(status==="completed"&&rawDone!==total&&total>0)summary=`completed · ${number(rawDone)} processed · ${number(total)} current source target`;return `<article class="control-card"><div class="section-head"><div><h3>${esc(r.label||r.phase_key||r.lane_key)}</h3><p>${esc(summary)}${failed?` · ${number(failed)} failed`:""} · ${esc(stamp)}</p>${error?`<p class="warning">${esc(error)}</p>`:""}</div><strong>${esc(badge)}</strong></div><div class="task-progress"><span style="width:${pct}%"></span></div></article>`}).join("");}
  function cutoverWarnings(payload){const direct=Array.isArray(payload?.cutover?.warnings)?payload.cutover.warnings:[];if(direct.length)return [...new Set(direct.map(v=>String(v).replace(":",": ").replaceAll("_"," ")))];const out=[];const add=(prefix,checks)=>{for(const [key,value] of Object.entries(checks||{}))if(value!==true)out.push(`${prefix}: ${key.replaceAll("_"," ")}`);};add("Green",payload?.validation?.checks);add("Adapter",payload?.adapter?.checks);add("Read parity",payload?.compatibility?.smoke?.checks||payload?.adapter?.smoke?.checks);return [...new Set(out)];}
  function gabReconciliationSnapshot(gab={}){const lane=(Array.isArray(gab?.lanes)?gab.lanes:[]).find(r=>String(r.lane_key||r.phase_key||"")==="compat_reconciliation")||{};let cursor={};try{cursor=JSON.parse(lane.cursor_json||"{}")||{}}catch(_){cursor={}}return {status:String(lane.status||"—"),pass:Number(lane.pass_no??cursor.pass_no??0),remaining:lane.last_full_pass_remaining??cursor.last_full_pass_remaining,attempts:Number(lane.attempted_rows??lane.processed_rows??0)};}
  function renderCutoverReadiness(payload){const cut=payload?.cutover||{},allowed=Boolean(payload?.read_cutover_allowed??cut.allowed),clean=Boolean(payload?.read_cutover_ready??cut.clean),warnings=cutoverWarnings(payload),g=payload?.green||{},s=g.state||{},p=g.progress||{},i=g.integrity||{},gf=g.gffl||{},gab=g.gab||{},dur=g.cycle_durations||{},effective=String(payload?.effective_public_source||s.public_read_target||"blue"),blueMaintained=cut.blue_rollback_maintained===true,recon=gabReconciliationSnapshot(gab),smoke=payload?.compatibility?.smoke||payload?.adapter?.smoke;const reconText=recon.status==="completed"?"COMPLETE":recon.remaining!==undefined&&recon.remaining!==null?`${number(recon.remaining)} left · pass ${number(recon.pass)}`:`${recon.status} · pass ${number(recon.pass)}`;const host=$("greenCutoverMetrics");if(host)host.innerHTML=[greenMetric("Public reads",effective.toUpperCase(),String(s.migration_phase||"").replaceAll("_"," ")),greenMetric("Public data mode",effective==="green"?"GREEN NATIVE + LIVE":"BLUE",effective==="green"?"Native totals/points · live compatibility projection":"Blue authoritative reads"),greenMetric("Green public analytics",s.compat_analytics_rebuilt_at?localGreenTimestamp(s.compat_analytics_rebuilt_at):"PENDING","refresh continues while GAB converges"),greenMetric("Green switch",allowed?"AVAILABLE":"TECHNICAL STOP",allowed?"Queues/convergence do not block":"Connection/schema prerequisite failed"),greenMetric("Blue rollback",blueMaintained?"MAINTAINED":"CHECK",blueMaintained?"Blue Team Points workers active":"Blue task state unavailable/paused"),greenMetric("Advisories",number(warnings.length),clean?"all legacy readiness checks clean":"informational; do not block switch"),greenMetric("Unknown / boards pending",`${number(p?.matches?.unknown||0)} / ${number(p?.boards?.pending||0)}`,"continuous-cycle workload"),greenMetric("Current facts over SLO",number(i.current_maintenance_over_slo||0),`GFFL due ${number(gf.due||0)} · hot ${number(gf.hot||0)}`),greenMetric("GABCRF",reconText,`${number(recon.attempts)} projection attempts`),greenMetric("Compatibility smoke",smoke?(smoke.ready?"PASS":"WARN"):"NOT RUN","Run Validate Green for an on-demand smoke check"),greenMetric("Last completed cycle",formatDuration(dur.last_duration_seconds),dur.last_cycle_no?`Cycle #${number(dur.last_cycle_no)}`:"No completed cycle")].join("");const feedback=$("greenCutoverBlockers");if(feedback){feedback.className=`control-feedback ${allowed?(warnings.length?"warning":"success"):"error"}`;feedback.textContent=!allowed?`Technical stop: ${Object.entries(cut?.technical?.checks||{}).filter(([,v])=>v!==true).map(([k])=>k.replaceAll("_"," ")).join(" · ")||"Green connection/schema unavailable"}.`:warnings.length?`Switch is allowed. Advisories (do not block): ${warnings.slice(0,6).join(" · ")}${warnings.length>6?` · +${warnings.length-6} more`:""}.`:"Green switch is available and no advisory readiness checks are outstanding.";}const switchBtn=$("greenSwitchReads"),primaryBtn=$("greenMakePrimary"),rollbackBtn=$("greenRollbackReads");if(switchBtn){switchBtn.disabled=!allowed||effective==="green";switchBtn.textContent=effective==="green"?"Green reads active":"Switch reads to Green";}if(primaryBtn){primaryBtn.disabled=!allowed||String(s.migration_phase||"")==="green_primary";primaryBtn.textContent=String(s.migration_phase||"")==="green_primary"?"Green is primary":"Make Green primary";}if(rollbackBtn)rollbackBtn.disabled=effective!=="green";return allowed;}
  function gqacMetric(s,q){if(q?.initialized)return greenMetric("GQAC",`${number(q.completed||0)}/${number(q.total||0)} · ${Number(q.percent||0).toFixed(1)}%`,`eligible ${number(q.eligible_now||0)} · claimed ${number(q.claimed_active||0)} · backoff ${number(q.blocked_retry||0)} · deferred ${number(q.deferred_transient||0)}`);const mode=String(s?.mode||""),stage=String(s?.stage||"");const detail=mode==="quick"&&stage!=="quick_boards"?`waiting for quick_boards · current ${stage||"—"}`:"no finite quick-board cohort active";return greenMetric("GQAC","Not initialized",detail);}
  function renderGreenControl(payload) {
    const host=$("greenSchedulerMetrics"),badge=$("greenSchedulerBadge");if(!host)return;
    const g=payload?.green||{},s=g.state||{},q=g.gqac||{},gab=g.gab||{},gf=g.gffl||{},hm=g.heatmap_backfill||{},inv=Array.isArray(g.recent_invocations)?g.recent_invocations:[],dur=g.cycle_durations||{},phase=String(s.migration_phase||"blue_primary"),effective=String(payload?.effective_public_source||"blue"),parity=payload?.compatibility?.parity||{};const last=inv[0]||{},active=String(last.operational_status||last.status||s.last_worker_status||"unknown");
    $("greenMigrationPhase") && ($("greenMigrationPhase").value=phase);$("greenWorkerTarget") && ($("greenWorkerTarget").value=String(s.worker_target||"both"));$("greenClientTarget") && ($("greenClientTarget").value=String(s.client_ingest_target||"both"));$("greenForceMode") && ($("greenForceMode").value=String(s.force_mode||"auto"));$("greenGfflTarget") && ($("greenGfflTarget").value=String(gf.target_seconds||1200));
    host.innerHTML=[greenMetric("Public reads",effective.toUpperCase(),parity.ready?"Adapter installed · parity complete":("Adapter installed · parity "+(parity.state||"pending"))),greenMetric("Migration phase",phase.replaceAll("_"," ")),greenMetric("Worker routing",s.worker_target||"—"),greenMetric("Browser ingest",s.client_ingest_target||"—"),greenMetric("Cycle",`#${Number(s.cycle_no||0)} · ${s.mode||"—"}`),greenMetric("Stage",s.stage||"—"),greenMetric("CRON","5 staggered · aggregate 1/min"),greenMetric("Worker budget","50 s soft / 55 s hard","finite worker lock; analytics maintenance runs outside this lock"),greenMetric("Last cycle",formatDuration(dur.last_duration_seconds),dur.last_cycle_no?`Cycle #${number(dur.last_cycle_no)}`:"No completed cycle"),greenMetric("Average · last 10",formatDuration(dur.average_duration_seconds),`${number(dur.sample_size||0)} recorded cycle${Number(dur.sample_size||0)===1?"":"s"}${dur.historical_boundary_warning?" · pre-2.10.6.16 history may be distorted":""}`),greenMetric("Last invocation",last.invocation_id?`#${Number(last.invocation_id)} · ${active}`:"No invocation"),greenMetric("GAB",`${gab.status||s.gab_status||"not started"} · ${Number(gab.percent||0).toFixed(1)}%`),greenMetric("GFFL due / hot",`${number(gf.due||0)} / ${number(gf.hot||0)}`),greenMetric("GFFL fetches saved",number(gf.fetches_saved||0)),gqacMetric(s,q)].join("");
    if(badge){badge.className=`status-pill ${active==="success"?"is-good":active==="error"||active==="failed"?"is-bad":"is-warn"}`;badge.textContent=effective==="green"?"Green reads":active;}
    const gc=gab.convergence||{},gabm=$("greenGabMetrics");if(gabm)gabm.innerHTML=[greenMetric("Status",gab.status||s.gab_status||"not started"),greenMetric("Current lane",gab.phase||s.gab_phase||"—"),greenMetric("Lane-weighted display",`${Number(gab.percent||0).toFixed(1)}%`,`${number(gab.completed_lanes||0)} / ${number(gab.total_lanes||0)} lanes complete`),greenMetric("Measured obligations",`${number(gc.completed||0)} / ${number(gc.total_obligations||0)}`,`${number(gc.unresolved||0)} unresolved`),greenMetric("Retryable / due",`${number(gc.retryable||0)} / ${number(gc.currently_due||0)}`),greenMetric("Terminal retired",number(gc.terminal_retired||0),"404/410 obligations closed"),greenMetric("Oldest unresolved",localGreenTimestamp(gc.oldest_unresolved||"")),greenMetric("Last real progress",localGreenTimestamp(gc.last_real_progress_at||"")),greenMetric("External failed",number(gab.external?.failed||0)),greenMetric("Accelerator priority",String(gab.status)==="running"?"GAB first":"normal")].join("");setHTML("greenGabPhases",renderGreenBars(Array.isArray(gab.lanes)?gab.lanes:[]));
    const gfm=$("greenGfflMetrics");if(gfm)gfm.innerHTML=[greenMetric("Enabled",gf.enabled?"YES":"NO"),greenMetric("Target freshness",`${number(gf.target_seconds||1200)} s`),greenMetric("Pending",number(gf.pending||0)),greenMetric("Due",number(gf.due||0)),greenMetric("Hot",number(gf.hot||0)),greenMetric("Obligations",number(gf.obligations||0)),greenMetric("Coalesced",number(gf.coalesced||0)),greenMetric("Fetches saved",number(gf.fetches_saved||0)),greenMetric("Terminal closed",number(gf.terminal_closed||0),"404/410 freshness obligations retired"),greenMetric("Oldest due",localGreenTimestamp(gf.oldest_due||""))].join("");
    const hmm=$("greenHeatmapMetrics");if(hmm)hmm.innerHTML=[greenMetric("Paired-rating coverage",`${Number(hm.paired_match_percent||0).toFixed(1)}%`,`${number(hm.paired_rating_matches||0)} / ${number(hm.finished_matches||0)} finished matches`),greenMetric("Queued",number(hm.total||0)),greenMetric("Pending",number(hm.pending||0)),greenMetric("Completed attempts",number(hm.completed||0)),greenMetric("Failed",number(hm.failed||0)),greenMetric("Last activity",localGreenTimestamp(hm.last_activity||""))].join("");
    const isQuickMatches=String(s.mode||"")==="quick"&&String(s.stage||"")==="quick_matches",est=isQuickMatches?quickMatchesEstimate(g):null;const basePhases=(Array.isArray(g.phase_progress)?g.phase_progress:[]).filter(r=>!["gab","gffl"].includes(String(r.phase_key||"")));const phases=basePhases.filter(r=>!(isQuickMatches&&String(r.phase_key||"")==="quick_matches"));const cycle=g.cycle||{},progress=g.progress||{},overall=est?est.percent:Number(progress.progress_percent||0);setHTML("greenCycleProgress",`<article class="control-card"><div class="section-head"><div><h3>Cycle #${number(s.cycle_no||0)}</h3><p>${esc(s.mode||"—")} · ${esc(s.stage||"—")} · ${esc(localGreenTimestamp(cycle.started_at||s.cycle_started_at||""))}</p></div><strong>${overall.toFixed(1)}% current phase</strong></div><div class="task-progress"><span style="width:${Math.max(0,Math.min(100,overall))}%"></span></div></article>${est?quickMatchesProgressCard(est):""}${renderGreenBars(phases)}`);
    setHTML("greenInvocationRows",inv.map(r=>{let summary={},a={},t={},gscf={};try{summary=JSON.parse(r.summary_json||"{}")||{};a=summary.achieved||{};t=summary.timings_ms||{};gscf=summary.gscf||{}}catch(_){summary={};a={};t={};gscf={}}const reqSplit=`core ${number((a.finite_cycle_requests||0)+(a.finite_cycle_tail_requests||0))} · GFFL ${number(a.gffl_requests||0)} · maint ${number(a.current_maintenance_requests||0)}`;const sec=v=>(Number(v||0)/1000).toFixed(1),compat=gscf.maintenance?.compat_analytics||{},compatReason=String(compat.reason||"legacy telemetry").replaceAll("_"," "),analytics=compat.analytics||{},compatRows=["matchRows","monthlyRows","playerRows","clubRows","dailyRows","opponentRows"].reduce((n,k)=>n+Number(analytics[k]||0),0),compatWork=compat.ran?`rebuilt ${number(compatRows)} rows · ${compatReason}`:`skipped · ${compatReason}`;const timing=`finite ${sec((t.finite_cycle||0)+(t.finite_cycle_tail||0))}s · GAB ${sec(t.gab)}s · GFFL ${sec(t.gffl)}s · maint ${sec(t.current_maintenance)}s · Green AN ${sec(t.green_analytics)}s · compat AN ${sec(t.compat_analytics)}s · ${compatWork}`;const total=Number(r.runtime_ms||0),core=Number(gscf.core_lock_runtime_ms??total);const runtime=core===total?`${(total/1000).toFixed(1)} s`:`${(core/1000).toFixed(1)} s finite lock · ${(total/1000).toFixed(1)} s total`;return `<tr><td>${esc(localGreenTimestamp(r.started_at||""))}</td><td>${number(r.cycle_no||0)}</td><td>${esc(r.stage_start||"")} → ${esc(r.stage_finish||"")}</td><td>${esc(r.status||"")}</td><td class="num">${esc(runtime)}</td><td class="num">${number(r.request_count||0)}</td><td>${esc(reqSplit)}<br><small>${esc(timing)}</small></td></tr>`}).join("")||'<tr><td colspan="7">No Green invocation loaded.</td></tr>');
    const cutoverAllowed=renderCutoverReadiness(payload),warningCount=cutoverWarnings(payload).length;renderAcceleratorStatus();setFeedback("greenSchedulerMessage",effective==="green"?`Green public reads are active. ${phase==="green_primary"?"Green is primary.":"Blue + Green maintenance remain active for rollback."}${warningCount?` ${number(warningCount)} advisory readiness check(s) continue in the background.`:""}`:cutoverAllowed?`The Green read adapter is installed. Green runtime is operational and the production switch is available.${warningCount?` ${number(warningCount)} advisory readiness check(s) can continue after cutover.`:""}`:"Green runtime is active, but a technical Green connection/schema prerequisite prevents cutover.",effective==="green"||cutoverAllowed?(warningCount?"warning":"success"):"error");
  }
  function renderAcceleratorStatus(){const a=window.P2K_GREEN_ACCELERATOR?.status?.()||{},m=a.metrics||{},t=a.transport||{};const b=$("greenAcceleratorBadge");if(b){b.className=`status-pill ${a.running?"is-good":"is-paused"}`;b.textContent=a.running?(a.busy?"Running · busy":"Running"):"Stopped";}const tm=$("greenAcceleratorMetrics");if(tm)tm.innerHTML=[greenMetric("Priority lane",String(m.last_lane||"normal").toUpperCase()),greenMetric("Batch",`#${number(a.batch_no||0)}`),greenMetric("Planned / fetched",`${number(m.planned||0)} / ${number(m.fetched||0)}`),greenMetric("Accepted / changed",`${number(m.accepted||0)} / ${number(m.changed||0)}`),greenMetric("Failures",number(m.failed||0)),greenMetric("Last rate",Number(m.last_rate||0)>0?`${Number(m.last_rate).toFixed(1)}/s`:"—"),greenMetric("Last batch",localGreenTimestamp(m.last_batch_at||"")),greenMetric("Gateway POSTs active / queued",`${number(t.oauthGatewayActivePosts??t.activeRequests??0)} / ${number(t.oauthGatewayQueued??t.queuedRequests??0)}`),greenMetric("Gateway target / transport cap",`${number(t.oauthGatewayTarget??t.configuredConcurrency??0)} / ${number(t.oauthTransportCapacity??t.adaptiveConcurrency??0)}`),greenMetric("Learned safe rate",Number(t.oauthGatewaySafeRateTarget??t.learnedSafeRate??0)>0?`${Number(t.oauthGatewaySafeRateTarget??t.learnedSafeRate).toFixed(1)}/s`:"—")].join("");const rows=(a.logs||[]).slice(0,100).map(r=>`<tr><td>${esc(localGreenTimestamp(r.at||""))}</td><td>${esc(r.level)}</td><td>${esc(r.message)}</td></tr>`).join("");setHTML("greenAcceleratorLogRows",rows||'<tr><td colspan="3">No accelerator log entry.</td></tr>');}
  async function refreshGreenControl(){try{state.green=await greenEndpoint("status");renderGreenControl(state.green);}catch(error){const b=$("greenSchedulerBadge");if(b){b.className="status-pill is-bad";b.textContent="Unavailable";}setFeedback("greenSchedulerMessage",error.message||String(error),"error");}}
  async function greenPost(action,body={}){const d=await greenEndpoint(action,{method:"POST",body,timeoutMs:60000});await refreshGreenControl();return d;}

  async function connect() {
    const username = sessionUsername();
    if (!username) throw new Error("Log in with the simulated OAuth profile before opening Administration.");
    const payload = await window.P2K_TEAM_POINTS_CLIENT.connect(username);
    state.connected = true;
    setText("taskControlConnection", `${payload.username} verified. MariaDB schema ${payload.schema_version} and the unified task registry are ready.`);
    const badge = $("taskControlConnectionBadge"); if (badge) { badge.className = "status-pill is-good"; badge.textContent = "Connected"; }
    return payload;
  }
  function gatewayMetrics(gateway = {}) {
    const items = [
      ["Gateway health", gateway.health_status || "unknown", gateway.health_message || "No health message"],
      ["Shared cache", number(gateway.cache_entries), `${number(gateway.fresh_cache_entries)} fresh entries`],
      ["Last success", relative(gateway.last_success_at), gateway.last_success_at || "No successful request recorded"],
      ["False-404 policy", gateway.false_404_protection ? "Protected" : "Unknown", "Not-found results are conclusive only while the PubAPI health probe is healthy"],
    ];
    setHTML("gatewayMetrics", items.map(([label, value, detail]) => `<article class="metric"><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(detail)}</small></article>`).join(""));
    const badge = $("gatewayHealthBadge"); if (badge) { badge.className = `status-pill ${healthClass(gateway.health_status)}`; badge.textContent = gateway.health_status || "Unknown"; }
    setText("gatewayMessage", gateway.health_message || "Shared gateway state has not been established yet.");
  }
  function reconstructionStatus() {
    try { return window.P2K_FRESH_POINTS_RECONSTRUCTION?.status?.() || null; } catch (_) { return null; }
  }
  function reconstructionTask() {
    const status = reconstructionStatus() || {};
    const snapshot = status.snapshot || state.payload?.reconstruction || null;
    const run = snapshot?.run || null;
    const mode = run?.status || "idle";
    const progress = Number(run?.overall_progress || 0);
    const health = mode === "failed" ? "failed" : mode === "paused" ? "paused" : mode === "ready" || mode === "applied" ? "healthy" : mode === "running" || mode === "applying" ? "healthy" : "disabled";
    const message = !run
      ? "Force a fresh, checkpointed reconstruction of Club Points and/or current-member Player Points before explicit server-side approval."
      : mode === "ready"
        ? "Fresh acquisition is complete. Review the reconstructed totals and differences before applying."
        : mode === "applied"
          ? "The approved reconstruction has been promoted to Core and obsolete queue work was superseded."
          : `${run.phase_label || "Reconstruction"} · ${progress.toFixed(1)}% overall.`;
    return { task_key: RECONSTRUCTION_TASK, label: "Fresh Points Reconstruction", status: mode, health, health_message: message,
      last_message: run?.last_error || message, expected_interval_seconds: 0, legacy_endpoint: "browser fresh acquisition → reconstruction staging → explicit apply",
      reconstruction: status, snapshot, work: { summary: snapshot?.metrics || {} } };
  }
  function taskList() {
    const source = (Array.isArray(state.payload?.tasks) && state.payload.tasks.length ? state.payload.tasks : SERVER_TASK_FALLBACKS).filter(task=>!["team-points","team-points-club","team-points-player"].includes(String(task.task_key||"")));
    const tasks = source.map(task => {
      const cached = state.taskDetails.get(task.task_key);
      if (!cached) return task;
      // v2.9.22.10 telemetry persistence: status refreshes update volatile task health
      // without throwing away the lazily loaded work snapshot.
      return { ...cached, ...task, work: cached.work || task.work, cron_shell: task.cron_shell || cached.cron_shell, detail_loaded_at: cached.detail_loaded_at };
    });
    return tasks;
  }

  function taskProgress(task) {
    const summary=task.work?.summary||{};
    const job = task.work?.job;
    if (!job) return task.status === "idle" && task.last_success_at ? 100 : 0;
    const total = Number(job.total_items || 0),queue=job.queue||{};
    const unresolved=Number(queue.pending||0)+Number(queue.running||0)+Number(queue.retry||0)+Number(queue.failed||0);
    if(total>0 && unresolved===0) return 100;
    const done = Number(queue.committed ?? job.processed_items ?? queue.done ?? 0);
    return total > 0 ? Math.min(100, Math.round(done / total * 100)) : 0;
  }
  function renderTasks() {
    const tasks = taskList();
    if (!state.selected || !tasks.some(task => task.task_key === state.selected)) {
      const requested = new URLSearchParams(location.search).get("task") || "";
      const compatible = requested === "match-monitoring" ? "match-tracking" : requested;
      state.selected = tasks.some(task => task.task_key === compatible) ? compatible : (tasks[0]?.task_key || "");
    }
    const taskGrid = $("scheduledTaskGrid"); if (!taskGrid) return;
    taskGrid.innerHTML = tasks.map(task => {
      const progress = task.task_key === RECONSTRUCTION_TASK ? Number(task.snapshot?.run?.overall_progress || 0) : taskProgress(task);
      const latest = task.task_key === RECONSTRUCTION_TASK ? (task.snapshot?.run?.updated_at ? relative(task.snapshot.run.updated_at) : "No reconstruction run") : (task.last_success_at ? relative(task.last_success_at) : "No successful run");
      if (task.task_key === RECONSTRUCTION_TASK) {
        const run = task.snapshot?.run || {}; const mode = run.status || "idle";
        const active = ["running","paused","ready","applying"].includes(mode);
        const clubSelected = run.include_club ?? true, playerSelected = run.include_player ?? false;
        const clubProgress = Number(run.club_progress || 0), playerProgress = Number(run.player_progress || 0);
        return `<article class="task-card reconstruction-card ${task.task_key === state.selected ? "is-selected" : ""}" data-task-card="${RECONSTRUCTION_TASK}">
          <div class="task-card-head"><div><h3>${esc(task.label)}</h3><p>${esc(task.health_message)}</p></div><span class="status-pill ${healthClass(task.health)}">${esc(mode)}</span></div>
          <div class="task-progress" aria-label="Overall reconstruction progress"><span style="width:${Math.max(0,Math.min(100,progress))}%"></span></div>
          <div class="reconstruction-subprogress">
            <div><small><span>Club Points</span><strong>${clubProgress.toFixed(1)}%</strong></small><div class="task-progress"><span style="width:${Math.max(0,Math.min(100,clubProgress))}%"></span></div></div>
            <div><small><span>Player Points</span><strong>${playerProgress.toFixed(1)}%</strong></small><div class="task-progress"><span style="width:${Math.max(0,Math.min(100,playerProgress))}%"></span></div></div>
          </div>
          <div class="reconstruction-selectors">
            <label><input type="checkbox" id="reconstructionClubChoice" ${clubSelected ? "checked" : ""} ${active ? "disabled" : ""}> Club Points</label>
            <label><input type="checkbox" id="reconstructionPlayerChoice" ${playerSelected ? "checked" : ""} ${active ? "disabled" : ""}> Player Points</label>
          </div>
          <div class="task-meta"><div><small>Status</small><strong>${esc(mode)}</strong></div><div><small>Checkpoint</small><strong>${esc(latest)}</strong></div><div><small>Phase</small><strong>${esc(run.phase_label || "Not started")}</strong></div></div>
          <div class="task-controls">
            <button class="control-button primary" type="button" data-reconstruction-command="start" ${active ? "disabled" : ""}>Start fresh reconstruction</button>
            <button class="control-button" type="button" data-reconstruction-command="resume" ${mode !== "paused" ? "disabled" : ""}>Resume</button>
            <button class="control-button" type="button" data-reconstruction-command="pause" ${mode !== "running" ? "disabled" : ""}>Pause</button>
            <button class="control-button danger" type="button" data-reconstruction-command="cancel" ${!["running","paused","ready"].includes(mode) ? "disabled" : ""}>Cancel</button>
          </div>
        </article>`;
      }
      const action = task.status === "paused" ? "resume" : "start"; const serverUnavailable = task.server_available === false;
      return `<article class="task-card ${task.task_key === state.selected ? "is-selected" : ""}" data-task-card="${esc(task.task_key)}">
        <div class="task-card-head"><div><h3>${esc(task.label)}</h3><p>${esc(task.health_message)}</p></div><span class="status-pill ${healthClass(task.health)}">${esc(task.health)}</span></div>
        <div class="task-progress" aria-label="Task progress"><span style="width:${progress}%"></span></div>
        <div class="task-meta"><div><small>Status</small><strong>${esc(task.status)}</strong></div><div><small>Last success</small><strong>${esc(latest)}</strong></div><div><small>Expected</small><strong>${esc(cadence(Number(task.expected_interval_seconds)))}</strong></div></div>
        <div class="task-controls">
          <button class="control-button primary" type="button" data-task-command="${action}" data-task="${esc(task.task_key)}" ${serverUnavailable ? "disabled" : ""}>${action === "resume" ? "Resume" : "Start / queue"}</button>
          <button class="control-button danger" type="button" data-task-command="pause" data-task="${esc(task.task_key)}" ${task.status === "paused" || serverUnavailable ? "disabled" : ""}>Pause</button>
          <button class="control-button" type="button" data-task-command="refresh" data-task="${esc(task.task_key)}" ${serverUnavailable ? "disabled" : ""}>Refresh</button>
        </div>
      </article>`;
    }).join("");
    document.querySelectorAll("[data-task-card]").forEach(card => card.addEventListener("click", async event => {
      if (event.target.closest("button")) return;
      state.selected = card.dataset.taskCard; history.replaceState(null, "", `?task=${encodeURIComponent(state.selected)}#task-detail`);
      if (state.selected === RECONSTRUCTION_TASK) {
        try { await window.P2K_FRESH_POINTS_RECONSTRUCTION?.init?.(); }
        catch (error) { setFeedback("taskLogFeedback", error.message || String(error), "error"); }
        renderTasks(); renderDetail(); return;
      }
      renderTasks(); renderDetail();
      loadSelectedTaskDetail().catch(()=>{});
    }));
    document.querySelectorAll("[data-task-command]").forEach(button => button.addEventListener("click", async event => {
      event.stopPropagation(); await command(button.dataset.task, button.dataset.taskCommand);
    }));
    document.querySelectorAll("[data-reconstruction-command]").forEach(button => button.addEventListener("click", async event => {
      event.stopPropagation();
      const api = window.P2K_FRESH_POINTS_RECONSTRUCTION; if (!api) return setFeedback("taskLogFeedback", "Fresh reconstruction engine is not available.", "error");
      try {
        const cmd = button.dataset.reconstructionCommand; state.selected = RECONSTRUCTION_TASK;
        if (cmd === "start") {
          const club = Boolean($("reconstructionClubChoice")?.checked), player = Boolean($("reconstructionPlayerChoice")?.checked);
          if (!club && !player) return setFeedback("taskLogFeedback", "Select Club Points and/or Player Points.", "error");
          if (!window.confirm(`Start a fresh ${club && player ? "Club + Player" : club ? "Club" : "Player"} Points reconstruction? Core will not change until you explicitly approve the result.`)) return;
          await api.start({ club, player });
        } else if (cmd === "pause") await api.pause();
        else if (cmd === "resume") await api.resume();
        else if (cmd === "cancel") { if (window.confirm("Cancel this reconstruction run? Staged reconstruction data will remain for audit but cannot be applied.")) await api.cancel(); }
        renderTasks(); renderDetail();
      } catch (error) { setFeedback("taskLogFeedback", error.message || String(error), "error"); }
    }));
    const select = $("taskLogTask");
    if (!select) return;
    const current = select.value;
    const serverTasks = Array.isArray(state.payload?.tasks) ? state.payload.tasks : [];
    select.innerHTML = '<option value="">All tasks</option>' + serverTasks.map(task => `<option value="${esc(task.task_key)}">${esc(task.label)}</option>`).join("");
    if ([...select.options].some(option => option.value === current)) select.value = current;
  }
  function flattenSummary(summary = {}) {
    return Object.entries(summary).filter(([, value]) => value === null || ["string", "number", "boolean"].includes(typeof value)).slice(0, 32);
  }
  function reconstructionMetricHTML(label, value, detail = "") {
    return `<article class="metric"><span>${esc(label)}</span><strong>${esc(typeof value === "number" ? number(value) : value)}</strong>${detail ? `<small>${esc(detail)}</small>` : ""}</article>`;
  }
  function renderReconstructionDetail(task) {
    const host = $("reconstructionDetail"); if (!host) return;
    const live = task.reconstruction || reconstructionStatus() || {}; const snapshot = live.snapshot || task.snapshot || state.payload?.reconstruction || {};
    const run = snapshot.run || {}; const metrics = snapshot.metrics || {}; const review = snapshot.review || null; const network = live.network || metrics.client?.network || {}; const persistence = live.persistence || metrics.client?.persistence || {};
    const phaseRows = Object.keys(live.phaseRows || {}).length ? live.phaseRows : (metrics.client?.phase_rows || {});
    const mode = run.status || "idle";
    setText("taskDetailTitle", `${task.label} — work details`); setText("taskDetailSubtitle", task.last_message || task.health_message);
    const badge = $("taskDetailHealth"); if (badge) { badge.className = `status-pill ${healthClass(task.health)}`; badge.textContent = mode; }
    const mm=metrics.matches||{}, mem=metrics.members||{}, boards=metrics.boards||{}, games=metrics.games||{};
    const items = [
      ["Run state", mode], ["Overall progress", `${Number(run.overall_progress||0).toFixed(1)}%`], ["Current phase", run.phase_label || "Not started"], ["Run ID", run.run_id || "—"],
      ["Matches found", mm.total||0], ["Matches pending", mm.pending||0], ["Matches resolved", mm.resolved||0], ["Finished matches", mm.finished||0], ["0–0 void/excluded", mm.excluded_zero_zero||0], ["Match issues", Number(mm.unresolved||0)+Number(mm.failed||0)],
      ["Members found", mem.total||0], ["Opening roster", run.opening_roster_count||0], ["Closing roster", run.closing_roster_count||0], ["Members complete", mem.complete||0], ["Archive fallbacks", mem.archive_fallback||0], ["Member issues", Number(mem.unresolved||0)+Number(mem.failed||0)],
      ["Boards found", boards.total||0], ["Boards pending", Number(boards.discovered||0)+Number(boards.pending||0)], ["Boards resolved", boards.resolved||0], ["Board issues", Number(boards.unresolved||0)+Number(boards.failed||0)],
      ["Games reconstructed", games.total||0], ["Player Points", Number(games.points||0).toFixed(1)], ["Chess.com requests", network.scheduled||0], ["Fetches OK", network.ok||0], ["Fetch failures", network.failed||0],
      ["OAuth transport", network.oauth ? "Bearer parallel" : (network.transportMode||"serial")], ["OAuth launch rate", `${Number(network.launchCps||0).toFixed(1)}/s`], ["Fetch completion rate", `${Number(network.completionCps||0).toFixed(1)}/s`], ["Learned safe rate", `${Number(network.safeRate||0).toFixed(1)}/s`], ["Gateway target / POST", network.gatewayTarget||1], ["Logical feeder", network.logicalFeeder||0], ["Transport capacity", network.transportCapacity||0], ["Gateway queued", network.gatewayQueued||0], ["Gateway POSTs active", network.gatewayActivePosts||0],
      ["Rows awaiting persistence", persistence.queuedRows||0], ["Rows persisted", persistence.rowsPersisted||0], ["Ingest batches", persistence.batchesSent||0], ["Ingest failures", persistence.failedBatches||0]
    ];
    setHTML("taskDetailMetrics", items.map(([l,v])=>reconstructionMetricHTML(l,v)).join(""));
    setText("taskWorkReport", !run.run_id ? "No reconstruction has been started. Select one or both tracks in the card and start a fresh run." : mode === "ready" ? "Fresh acquisition is complete. Reconcile differences individually below; acquisition issues no longer block unrelated corrections." : mode === "applied" ? "The selected reconciliation tracks are finalized. Verified worker work was superseded and Analytics was refreshed." : `Fresh reconstruction is ${mode}. ${run.phase_label || "Working"}. Every completed acquisition unit is checkpointed server-side; pausing or browser suspension does not discard completed work.`);
    setText("taskLegacyEndpoint", "browser fresh P2K_API_CLIENT → staged compare → per-entity lane-locked reconciliation");
    setText("taskCadence", "Manual reconciliation workflow. Fresh Chess.com findings are staged and compared continuously; only explicit per-match/per-player actions modify Core.");
    setHidden("taskActivityWrap", true); setHidden("taskQueueWrap", true); host.hidden = false;
    const cp=Number(run.club_progress||0), pp=Number(run.player_progress||0); setText("reconstructionClubProgress",`${cp.toFixed(1)}%`); setText("reconstructionPlayerProgress",`${pp.toFixed(1)}%`);
    const cb=$("reconstructionClubBar"), pb=$("reconstructionPlayerBar"); if(cb)cb.style.width=`${Math.max(0,Math.min(100,cp))}%`; if(pb)pb.style.width=`${Math.max(0,Math.min(100,pp))}%`;
    setHTML("reconstructionClubMetrics", [["Total matches",mm.total||0],["Valid matches",mm.valid||mm.finished||0],["Wins",mm.wins||0],["Draws",mm.draws||0],["Losses",mm.losses||0],["Pending",mm.pending||0],["Resolved",mm.resolved||0],["Issues",Number(mm.unresolved||0)+Number(mm.failed||0)],["0–0 excluded",mm.excluded_zero_zero||0]].map(([l,v])=>`<div><small>${esc(l)}</small><strong>${number(v)}</strong></div>`).join(""));
    setHTML("reconstructionPlayerMetrics", [["Members",mem.total||0],["Complete",mem.complete||0],["Boards",boards.total||0],["Resolved",boards.resolved||0],["Games",games.total||0],["Points",Number(games.points||0).toFixed(1)]].map(([l,v])=>`<div><small>${esc(l)}</small><strong>${esc(v)}</strong></div>`).join(""));
    setText("reconstructionCheckpoint", run.updated_at ? `Last checkpoint ${relative(run.updated_at)} · ${run.updated_at}` : "No checkpoint");
    const ordered = Object.entries(phaseRows);
    setHTML("reconstructionPhaseRows", ordered.map(([id,row])=>{const found=Number(row.found||0),processed=Number(row.processed||0),pending=Number(row.pending||0),issues=Number(row.failed||0)+Number(row.unresolved||0),progress=Math.max(0,Math.min(100,Number(row.progress||0)));return `<tr><td><strong>${esc(row.label||id)}</strong><small>${row.added ? ` · +${number(row.added)} discovered` : ""}</small></td><td>${esc(row.state||"waiting")}</td><td class="num">${number(found)}</td><td class="num">${number(pending)}</td><td class="num">${number(processed)}</td><td class="num">${number(issues)}</td><td class="num reconstruction-phase-progress"><strong>${progress.toFixed(1)}%</strong><div class="task-progress"><span style="width:${progress}%"></span></div></td></tr>`}).join("") || '<tr><td colspan="7">No phase has started yet.</td></tr>');
    const reviewHost=$("reconstructionReview"); if(reviewHost) reviewHost.hidden=!run.run_id;
    if(run.run_id){
      const rec=live.reconciliation||{}, clubRec=rec.club||{}, playerRec=rec.player||{}, c=review?.club||{}, pl=review?.player||{};
      const clubDiff=Number(clubRec.total??c.actionable_differences??c.changed_matches??0), playerDiff=Number(playerRec.total??pl.actionable_differences??pl.changed_members??0);
      const clubIssues=Number(clubRec.issue_total??(Array.isArray(clubRec.issues)?clubRec.issues.filter(row=>row&&typeof row==="object").length:Number(c.unresolved||0))), playerIssues=Number(playerRec.issue_total??(Array.isArray(playerRec.issues)?playerRec.issues.filter(row=>row&&typeof row==="object").length:Number(pl.unresolved||0)));
      const ib=$("reconstructionIntegrityBadge"); if(ib){const clean=clubDiff+playerDiff===0;ib.className=`status-pill ${clean?"is-good":"is-warn"}`;ib.textContent=clean?"No differences":"Differences found"}
      setText("reconstructionIntegrityText",`${number(clubDiff)} Club match difference(s) and ${number(playerDiff)} Player member difference(s) are currently actionable. ${number(clubIssues+playerIssues)} acquisition issue(s) remain separate and do not block unrelated corrections.`);
      const deltaClass=v=>Number(v)>0?"delta-positive":Number(v)<0?"delta-negative":"";
      setHTML("reconstructionReviewGrid", `<article><h4>Club Points</h4><dl><dt>Current Core score</dt><dd>${review?number(c.current_score||0):"—"}</dd><dt>Fresh local score</dt><dd>${review?number(c.reconstructed_score||0):"Building…"}</dd><dt>Raw score difference</dt><dd class="${deltaClass(c.delta)}">${review?(Number(c.delta||0)>=0?"+":"")+number(c.delta||0):"—"}</dd><dt>Actionable matches</dt><dd>${number(clubDiff)}</dd><dt>Fetch issues</dt><dd>${number(clubIssues)}</dd></dl></article><article><h4>Player Points</h4><dl><dt>Current-member Core points</dt><dd>${review?Number(pl.current_points||0).toFixed(1):"—"}</dd><dt>Fresh local points</dt><dd>${review?Number(pl.reconstructed_points||0).toFixed(1):"Building…"}</dd><dt>Raw point difference</dt><dd class="${deltaClass(pl.delta)}">${review?(Number(pl.delta||0)>=0?"+":"")+Number(pl.delta||0).toFixed(1):"—"}</dd><dt>Actionable members</dt><dd>${number(playerDiff)}</dd><dt>Acquisition issues</dt><dd>${number(playerIssues)}</dd></dl></article>`);
      const syncSortState=(scope,rec)=>{if(rec?.sort){state.reconciliationSort[scope]={key:String(rec.sort),direction:String(rec.direction||"desc")==="asc"?"asc":"desc"}}const active=state.reconciliationSort[scope];document.querySelectorAll(`[data-reconciliation-sort="${scope}"]`).forEach(button=>{const on=String(button.dataset.sortKey||"")===active.key;button.classList.toggle("is-active",on);const mark=button.querySelector("span");if(mark)mark.textContent=on?(active.direction==="asc"?"▲":"▼"):"↕";button.closest("th")?.setAttribute("aria-sort",on?(active.direction==="asc"?"ascending":"descending"):"none")})};
      syncSortState("club",clubRec);syncSortState("player",playerRec);
      if(clubRec?.effect)state.reconciliationEffect.club=String(clubRec.effect);const deltaFilter=$("reconstructionClubDeltaFilter");if(deltaFilter)deltaFilter.value=state.reconciliationEffect.club||"all";setText("reconstructionClubDeltaSummary",clubRec&&clubRec.total!==undefined?`${number(clubRec.positive_count||0)} add-point difference(s): +${number(clubRec.positive_delta||0)} · ${number(clubRec.negative_count||0)} remove-point difference(s): ${number(clubRec.negative_delta||0)} · ${number(clubRec.zero_count||0)} zero-point change(s) · net ${(Number(clubRec.net_delta||0)>=0?"+":"")+number(clubRec.net_delta||0)}${Number(clubRec.filtered_total??clubRec.total)!==Number(clubRec.total||0)?` · showing ${number(clubRec.filtered_total||0)} filtered row(s)`:""}`:"Point impact loads with the difference list.");
      const differenceLabel=type=>type==="missing_match"?"Missing match":type==="points_mismatch"?"Points differ":type==="final_result_mismatch"?"Final result differs":"Difference";
      const clubRows=(Array.isArray(clubRec.rows)?clubRec.rows:[]).filter(row=>row&&typeof row==="object");
      setHTML("reconstructionClubDifferenceRows",clubRows.map(row=>{const local=`${row.local_status||"unknown"} · ${Number(row.local_p2k_score||0).toFixed(1)}–${Number(row.local_opponent_score||0).toFixed(1)} · ${number(row.local_boards||0)} boards · ${number(row.local_points||0)} pts`;const core=row.db_match_id===null||row.db_match_id===undefined?"Missing":`${row.db_status||"unknown"} · ${Number(row.db_p2k_score||0).toFixed(1)}–${Number(row.db_opponent_score||0).toFixed(1)} · ${number(row.db_boards||0)} boards · ${number(row.db_points||0)} pts`;return `<tr><td><a href="https://api.chess.com/pub/match/${Number(row.match_id||0)}" target="_blank" rel="noopener">#${number(row.match_id||0)}</a><small>${esc(row.opponent_name||row.match_name||"")}</small></td><td>${esc(differenceLabel(row.difference_type))}</td><td>${esc(local)}</td><td>${esc(core)}</td><td class="num ${deltaClass(row.point_delta)}">${Number(row.point_delta||0)>=0?"+":""}${number(row.point_delta||0)}</td><td><button class="control-button primary" type="button" data-reconciliation-apply="club" data-entity-key="${Number(row.match_id||0)}">Apply local</button></td></tr>`}).join("")||'<tr><td colspan="6">No actionable Club difference in the loaded window.</td></tr>');
      const playerRows=(Array.isArray(playerRec.rows)?playerRec.rows:[]).filter(row=>row&&typeof row==="object");
      setHTML("reconstructionPlayerDifferenceRows",playerRows.map(row=>`<tr><td><strong>${esc(row.username||row.username_key||"")}</strong></td><td>${number(row.local_boards||0)} boards · ${number(row.local_games||0)} games · ${Number(row.local_points||0).toFixed(1)} pts</td><td>${number(row.db_boards||0)} boards · ${number(row.db_games||0)} games · ${Number(row.db_points||0).toFixed(1)} pts</td><td class="num ${deltaClass(row.point_delta)}">${Number(row.point_delta||0)>=0?"+":""}${Number(row.point_delta||0).toFixed(1)}</td><td><button class="control-button primary" type="button" data-reconciliation-apply="player" data-entity-key="${esc(row.username_key||"")}">Apply local</button></td></tr>`).join("")||'<tr><td colspan="5">No actionable Player difference in the loaded window.</td></tr>');
      const pIssues=(Array.isArray(playerRec.issues)?playerRec.issues:[]).filter(row=>row&&typeof row==="object");setHidden("reconstructionPlayerIssuesWrap",!pIssues.length);setHTML("reconstructionPlayerIssueRows",pIssues.map(row=>`<tr><td>${esc(row.username||row.username_key||"")}</td><td>${esc(row.stage_state||"")}</td><td class="num">${number(row.board_issues||0)}</td><td>${esc(JSON.stringify(row.metrics||{}))}</td></tr>`).join("")||'<tr><td colspan="4">No Player acquisition issues.</td></tr>');
      const actions=[...(Array.isArray(clubRec.applied)?clubRec.applied:[]),...(Array.isArray(playerRec.applied)?playerRec.applied:[])].filter(row=>row&&typeof row==="object").sort((a,b)=>String(b.applied_at||"").localeCompare(String(a.applied_at||""))).slice(0,100);setHTML("reconstructionAppliedRows",actions.map(row=>`<tr><td>${esc(row.applied_at||"")}</td><td>${esc(row.scope||"")}</td><td>${esc(row.entity_key||"")}</td><td>${esc(row.action_type||"")}</td><td class="num">${number(row.queue_superseded||0)}</td><td>${esc(row.applied_by||"—")}</td></tr>`).join("")||'<tr><td colspan="6">No correction has been applied in this run.</td></tr>');
      setHidden("reconstructionClubReconcileScope",!run.include_club);setHidden("reconstructionPlayerReconcileScope",!run.include_player);
      const finalClub=$("reconstructionFinalizeClub"),finalPlayer=$("reconstructionFinalizePlayer");if(finalClub)finalClub.disabled=!run.include_club||mode!=="ready"||clubDiff!==0||Boolean(run.club_applied_at);if(finalPlayer)finalPlayer.disabled=!run.include_player||mode!=="ready"||playerDiff!==0||Boolean(run.player_applied_at);
      const retry=$("reconstructionRetryClubIssues"),view=$("reconstructionViewClubIssues"),recalc=$("reconstructionRecalculateClub");if(retry)retry.disabled=!run.include_club||clubIssues===0;if(view)view.disabled=!run.include_club||clubIssues===0;if(recalc)recalc.disabled=!run.include_club||!run.run_id;
      // v2.9.22.8: reconciliation differences are manual/on-action only. Do not
      // launch large staging/Core comparisons merely because this card rendered.
    }
    const logs=Array.isArray(live.logs)?live.logs.slice(0,50):[];setHTML("reconstructionLogRows",logs.map(log=>`<tr><td>${esc(new Date(log.at).toLocaleTimeString("en-GB", {timeZone:"UTC",hour:"2-digit",minute:"2-digit",second:"2-digit",hour12:false}))}</td><td><span class="log-level ${esc(log.level||"info")}">${esc(log.level||"info")}</span></td><td>${esc(log.message||"")}</td></tr>`).join("")||'<tr><td colspan="3">No local reconstruction log entry.</td></tr>');
  }

  // v2.9.22.8: automatic reconciliation polling removed. Refresh differences is explicit.

  function renderDetail() {
    const task = taskList().find(item => item.task_key === state.selected);
    if (!task) return;
    const tournamentPanel = $("manual-tournament"), monitoringPanel = $("match-monitoring");
    if (tournamentPanel) tournamentPanel.hidden = state.selected !== "tournaments";
    if (monitoringPanel) monitoringPanel.hidden = !["match-tracking","match-monitoring"].includes(state.selected);
    if (state.selected === RECONSTRUCTION_TASK) { renderReconstructionDetail(task); return; }
    setHidden("reconstructionDetail", true);
    setText("taskDetailTitle", `${task.label} — work details`);
    setText("taskDetailSubtitle", task.last_message || task.health_message);
    const badge = $("taskDetailHealth"); if (badge) { badge.className = `status-pill ${healthClass(task.health)}`; badge.textContent = task.health; }
    const shell = task.cron_shell || {};
    const shellMetrics = [
      ["CRON shell", shell.observed ? (shell.status || "observed") : "never observed"],
      ["Last shell invocation", shell.last_started_at ? relative(shell.last_started_at) : "Never"],
      ["Shell HTTP", Number(shell.http_status || 0) > 0 ? String(shell.http_status) : "—"],
      ["Shell exit", shell.exit_code === null || shell.exit_code === undefined ? "—" : String(shell.exit_code)],
      ["Task last start", task.last_started_at ? relative(task.last_started_at) : "Never"],
      ["Task last completion", task.last_completed_at ? relative(task.last_completed_at) : "Never"],
      ["Task last success", task.last_success_at ? relative(task.last_success_at) : "Never"],
      ["Task next due", task.next_due_at || "—"],
      ["Detail snapshot", task.detail_loaded_at ? relative(task.detail_loaded_at) : "Loading / not loaded"],
    ];
    const domainMetrics = flattenSummary(task.work?.summary).filter(([label]) => !String(label).startsWith("cron_shell_"));
    const executionMetrics = flattenSummary(task.details || {}).filter(([label]) => !["lane"].includes(String(label))).map(([label,value]) => [`last_${label}`,value]);
    setHTML("taskDetailMetrics", [...shellMetrics, ...domainMetrics, ...executionMetrics].map(([label, value]) => `<article class="metric"><span>${esc(label.replaceAll("_", " "))}</span><strong>${esc(typeof value === "number" ? number(value) : value ?? "—")}</strong></article>`).join("") || '<article class="metric"><span>Work</span><strong>No details</strong></article>');
    setText("taskWorkReport", task.work?.work_report || "No work report available.");
    setText("taskLegacyEndpoint", task.legacy_endpoint || "—");
    setText("taskCadence", `Health is expected ${cadence(Number(task.expected_interval_seconds))}. Warning begins after the expected interval; critical begins after 150% of it.`);
    const activity = task.work?.job?.task_activity && typeof task.work.job.task_activity === "object" ? task.work.job.task_activity : null;
    const activityTypes = ["reconcile_members", "sync_player", "sync_player_stats", "sync_player_archive"];
    const activityRows = activity ? activityTypes.map(type => [type, activity[type] || {}]) : [];
    setHidden("taskActivityWrap", !activityRows.length);
    setHTML("taskActivityRows", activityRows.map(([type, row]) => `<tr><td>${esc(type.replaceAll("_", " "))}</td><td>${esc(row.last_started_at ? `${relative(row.last_started_at)} · ${row.last_started_at}` : "Never")}</td><td>${esc(row.last_success_at ? `${relative(row.last_success_at)} · ${row.last_success_at}` : "Never")}</td></tr>`).join(""));
    const rows = Array.isArray(task.work?.job?.task_breakdown) ? task.work.job.task_breakdown : [];
    setHidden("taskQueueWrap", !rows.length);
    setHTML("taskQueueRows", rows.map(row => `<tr><td>${esc(String(row.item_type || "").replaceAll("_", " "))}</td><td class="num">${number(row.total)}</td><td class="num">${number(row.pending)}</td><td class="num">${number(row.running)}</td><td class="num">${number(row.retry)}</td><td class="num">${number(row.done)}</td><td class="num">${number(row.skipped)}</td><td class="num">${number(row.failed)}</td></tr>`).join(""));
    if (["match-tracking","match-monitoring"].includes(state.selected)) loadTracked();
  }
  async function loadSelectedTaskDetail({ quiet = false } = {}) {
    const key = state.selected;
    if (!key || key === RECONSTRUCTION_TASK) return;
    if (state.taskDetailLoads.has(key)) return state.taskDetailLoads.get(key);
    if (!quiet) setFeedback("taskLogFeedback", `Loading ${key.replaceAll("-"," ")} work details…`);
    const pending = (async () => {
      try {
        const data = await endpoint("task-detail", { params: { task: key }, timeoutMs: 60000 });
        if (data?.task) {
          data.task.server_available = true;
          data.task.detail_loaded_at = new Date().toISOString();
          state.taskDetails.set(key, data.task);
          renderTasks();
          if (state.selected === key) renderDetail();
        }
        if (!quiet) setFeedback("taskLogFeedback", "Selected task details loaded.", "success");
      } catch (error) {
        // Keep the last known detail snapshot. A transient DB/FastCGI failure must not
        // collapse an already useful panel back to transport-only telemetry.
        setFeedback("taskLogFeedback", `Task card is available; the last detailed snapshot is being retained because refresh failed: ${error.message || error}`, "error");
      } finally {
        state.taskDetailLoads.delete(key);
      }
    })();
    state.taskDetailLoads.set(key, pending);
    return pending;
  }
  async function refresh({ logs = true, refreshSelectedDetail = true } = {}) {
    const payload = await endpoint("status", { timeoutMs: 15000 });
    state.payload = payload; gatewayMetrics(payload.gateway); renderTasks(); renderDetail(); refreshGreenControl().catch(()=>{});
    // v2.9.22.10: preserve lazy detail snapshots across the 60-second status pulse
    // and refresh only the selected server panel. This keeps every task panel rich
    // without recreating the old all-panels-at-once DB burst.
    if (refreshSelectedDetail && state.selected && state.selected !== RECONSTRUCTION_TASK) {
      await loadSelectedTaskDetail({ quiet: true });
    }
    if (logs) { /* logs are loaded explicitly with Refresh logs */ }
  }
  async function command(task, commandName) {
    try {
      const result = await endpoint("command", { method: "POST", body: { task, command: commandName } });
      setFeedback("taskLogFeedback", result.message || "Command accepted.", "success");
      state.selected = task; await refresh({ logs: false });
    } catch (error) { setFeedback("taskLogFeedback", error.message, "error"); }
  }
  async function loadLogs({ append = false } = {}) {
    try {
      if (!append) state.logBeforeId = 0;
      const params = { task: $("taskLogTask").value, level: $("taskLogLevel").value, limit: 50 };
      if (append && state.logBeforeId) params.before_id = state.logBeforeId;
      const data = await endpoint("logs", { params });
      const tasks = new Map((state.payload?.tasks || []).map(task => [task.task_key, task.label]));
      const rows = (data.logs || []).map(log => `<tr><td>${esc(String(log.created_at || "").replace("T", " "))}</td><td>${esc(tasks.get(log.task_key) || log.task_key)}</td><td><span class="log-level ${esc(log.level)}">${esc(log.level)}</span></td><td>${esc(log.component)}</td><td>${esc(log.message)}</td><td>${esc(log.run_id || "—")}</td></tr>`).join("");
      if (append) $("unifiedTaskLogs")?.insertAdjacentHTML("beforeend", rows);
      else setHTML("unifiedTaskLogs", rows || '<tr><td colspan="6">No matching unified log entry.</td></tr>');
      state.logBeforeId = Number(data.next_before_id || 0);
      state.logHasMore = Boolean(data.has_more);
      setHidden("loadMoreTaskLogs", !state.logHasMore);
      setFeedback("taskLogFeedback", `${number($("unifiedTaskLogs").querySelectorAll("tr").length)} log entries displayed.`, "success");
    } catch (error) { setFeedback("taskLogFeedback", error.message, "error"); }
  }
  async function initialize() {
    try {
      await window.P2K_ADMIN_ACCESS_READY;
      await connect();
      await refresh({ logs: false });
      state.timer = window.setInterval(() => refresh({ logs: false }).catch(() => {}), 60000);
    } catch (error) {
      state.connected = false;
      setText("taskControlConnection", error.message);
      const badge = $("taskControlConnectionBadge"); badge.className = "status-pill is-bad"; badge.textContent = "Unavailable"; renderTasks(); renderDetail();
    }
  }
  $("greenSchedulerRefresh")?.addEventListener("click",()=>refreshGreenControl());
  function setTaskSurfaceTab(key,{updateHistory=true}={}){
    key=key==="green"?"green":"scheduled";
    const green=key==="green";
    const host=$("p2kTaskControl");
    if(!host)return;
    host.querySelectorAll(":scope > section").forEach(section=>{
      if(section.classList.contains("connection-card"))return;
      section.hidden=green ? section.id!=="greenSchedulerControl" : section.id==="greenSchedulerControl";
    });
    document.querySelectorAll("[data-task-tab]").forEach(btn=>{const active=btn.dataset.taskTab===key;btn.classList.toggle("is-active",active);btn.setAttribute("aria-selected",active?"true":"false");});
    if(green)refreshGreenControl();
    if(updateHistory){const u=new URL(location.href);u.searchParams.set("tab",key);if(window.parent!==window){history.replaceState({tab:key},"",u);window.parent.postMessage({type:"p2k-embedded-tab-change",tool:"task-control",tab:key},window.location.origin)}else history.pushState({tab:key},"",u);}
  }
  document.querySelectorAll("[data-task-tab]").forEach(btn=>btn.addEventListener("click",()=>setTaskSurfaceTab(btn.dataset.taskTab||"scheduled")));
  setTaskSurfaceTab(new URLSearchParams(location.search).get("tab")||"scheduled",{updateHistory:false});
  window.addEventListener("popstate",()=>setTaskSurfaceTab(new URLSearchParams(location.search).get("tab")||"scheduled",{updateHistory:false}));
  $("greenSwitchReads")?.addEventListener("click",async()=>{try{const warnings=cutoverWarnings(state.green||{});if(!window.confirm(`Switch public reads to Green now while keeping BOTH Blue and Green maintenance active?${warnings.length?`\n\n${warnings.length} advisory readiness check(s) will continue after cutover.`:""}`))return;const d=await greenPost("switch-green-reads",{});setFeedback("greenSchedulerMessage",d.message||"Public reads switched to Green with both maintenance paths active.",d.blue_warning?"warning":"success");}catch(e){setFeedback("greenSchedulerMessage",e.message,"error");}});
  $("greenMakePrimary")?.addEventListener("click",async()=>{try{if(!window.confirm("Make Green primary now? Public reads stay on Green, Green becomes the only Team Points maintenance target, and Blue Team Points workers are paused. Blue data is not deleted."))return;const d=await greenPost("make-green-primary",{});setFeedback("greenSchedulerMessage",d.blue_warning?`Green is primary. Blue pause warning: ${d.blue_warning}`:(d.message||"Green is primary."),d.blue_warning?"warning":"success");}catch(e){setFeedback("greenSchedulerMessage",e.message,"error");}});
  $("greenApplyMigrationPhase")?.addEventListener("click",async()=>{try{await greenPost("set-migration-phase",{phase:$("greenMigrationPhase").value});setFeedback("greenSchedulerMessage","Advanced migration phase applied.","success");}catch(e){setFeedback("greenSchedulerMessage",e.message,"error");}});
  $("greenRollbackReads")?.addEventListener("click",async()=>{try{const d=await greenPost("rollback-blue-reads",{});setFeedback("greenSchedulerMessage",d.blue_warning?`Public reads rolled back to Blue. Blue maintenance warning: ${d.blue_warning}`:(d.message||"Public reads rolled back to Blue with both paths maintained."),d.blue_warning?"warning":"success");}catch(e){setFeedback("greenSchedulerMessage",e.message,"error");}});
  $("greenApplyWorkerTarget")?.addEventListener("click",()=>greenPost("set-worker-target",{target:$("greenWorkerTarget").value}).catch(e=>setFeedback("greenSchedulerMessage",e.message,"error")));
  $("greenApplyClientTarget")?.addEventListener("click",()=>greenPost("set-client-target",{target:$("greenClientTarget").value}).catch(e=>setFeedback("greenSchedulerMessage",e.message,"error")));
  $("greenApplyForceMode")?.addEventListener("click",()=>greenPost("set-force-mode",{mode:$("greenForceMode").value}).catch(e=>setFeedback("greenSchedulerMessage",e.message,"error")));
  $("greenRunNow")?.addEventListener("click",()=>greenPost("run-green-now",{}).catch(e=>setFeedback("greenSchedulerMessage",e.message,"error")));
  $("greenValidate")?.addEventListener("click",async()=>{try{const d=await greenEndpoint("validate-green",{method:"POST",body:{},timeoutMs:60000});renderCutoverReadiness(d);const warnings=cutoverWarnings(d);setFeedback("greenSchedulerMessage",d.read_cutover_allowed?(warnings.length?`Green technical cutover is available with ${number(warnings.length)} advisory check(s). Compatibility smoke was run; advisories do not block the switch.`:"Green technical cutover is available and the advisory validation is clean."):`Green technical cutover is unavailable. ${Object.entries(d.cutover?.technical?.checks||{}).filter(([,v])=>v!==true).map(([k])=>k.replaceAll("_"," ")).join(" · ")||"Check Green database/schema connectivity."}`,d.read_cutover_allowed?(warnings.length?"warning":"success"):"error");await refreshGreenControl();}catch(e){setFeedback("greenSchedulerMessage",e.message,"error");}});
  $("greenGabStart")?.addEventListener("click",()=>greenPost("start-gab",{}).catch(e=>setFeedback("greenSchedulerMessage",e.message,"error")));
  $("greenGabRun")?.addEventListener("click",()=>greenPost("run-gab-now",{seconds:12}).catch(e=>setFeedback("greenSchedulerMessage",e.message,"error")));
  $("greenGfflEnable")?.addEventListener("click",()=>greenPost("set-gffl",{enabled:true}).catch(e=>setFeedback("greenSchedulerMessage",e.message,"error")));
  $("greenGfflDisable")?.addEventListener("click",()=>greenPost("set-gffl",{enabled:false}).catch(e=>setFeedback("greenSchedulerMessage",e.message,"error")));
  $("greenGfflApplyTarget")?.addEventListener("click",()=>greenPost("set-gffl",{target_seconds:Number($("greenGfflTarget").value||1200)}).catch(e=>setFeedback("greenSchedulerMessage",e.message,"error")));
  $("greenHeatmapStart")?.addEventListener("click",async()=>{try{const d=await greenPost("start-heatmap-backfill",{restart:false});const h=d.heatmap_backfill?.status||d.heatmap_backfill||{};setFeedback("greenSchedulerMessage",`${number(h.pending||0)} historical heatmap board fetch(es) pending. Start the Green Accelerator to process them.`,Number(h.pending||0)>0?"warning":"success");}catch(e){setFeedback("greenSchedulerMessage",e.message,"error")}});
  $("greenHeatmapRestart")?.addEventListener("click",async()=>{try{const d=await greenPost("start-heatmap-backfill",{restart:true});const h=d.heatmap_backfill?.status||d.heatmap_backfill||{};setFeedback("greenSchedulerMessage",`Requeued missing paired-rating coverage: ${number(h.pending||0)} historical board fetch(es) pending.`,Number(h.pending||0)>0?"warning":"success");}catch(e){setFeedback("greenSchedulerMessage",e.message,"error")}});
  $("greenAcceleratorStart")?.addEventListener("click",()=>window.P2K_GREEN_ACCELERATOR?.start?.());$("greenAcceleratorStop")?.addEventListener("click",()=>window.P2K_GREEN_ACCELERATOR?.stop?.());$("greenAcceleratorOnce")?.addEventListener("click",()=>window.P2K_GREEN_ACCELERATOR?.runOnce?.());
  let acceleratorRenderTimer=0;const scheduleAcceleratorRender=()=>{if(acceleratorRenderTimer)return;acceleratorRenderTimer=window.setTimeout(()=>{acceleratorRenderTimer=0;renderAcceleratorStatus()},120)};window.addEventListener("p2k-green-accelerator-change",scheduleAcceleratorRender);window.addEventListener("p2k-green-accelerator-log",scheduleAcceleratorRender);
  $("taskControlReconnect")?.addEventListener("click", async () => { try { await connect(); await refresh(); } catch (error) { setText("taskControlConnection", error.message); } });
  $("refreshAllTasks").addEventListener("click", () => refresh());
  $("refreshTaskLogs").addEventListener("click", () => loadLogs());
  $("loadMoreTaskLogs").addEventListener("click", () => loadLogs({ append: true }));
  $("taskLogTask").addEventListener("change", () => loadLogs());
  $("taskLogLevel").addEventListener("change", () => loadLogs());
  window.addEventListener("p2k-reconstruction-updated", () => { renderTasks(); if (state.selected === RECONSTRUCTION_TASK) renderDetail(); });
  async function showReconstructionClubIssues(){try{const rows=await window.P2K_FRESH_POINTS_RECONSTRUCTION.clubIssues();setHidden("reconstructionClubIssuesWrap",false);setHTML("reconstructionClubIssueRows",rows.map(row=>`<tr><td><a href="https://api.chess.com/pub/match/${Number(row.match_id||0)}" target="_blank" rel="noopener">${number(row.match_id||0)}</a></td><td>${esc(row.stage_state||"")}</td><td>${esc(row.status||"")}</td><td class="num">${number(row.board_count||0)}</td><td class="num">${Number(row.p2k_score||0).toFixed(1)}</td><td class="num">${Number(row.opponent_score||0).toFixed(1)}</td><td>${esc(row.source_flags||"—")}</td></tr>`).join("")||'<tr><td colspan="7">No Club reconstruction issues remain.</td></tr>');setFeedback("taskLogFeedback",`${number(rows.length)} Club reconstruction issue(s) listed.`,rows.length?"error":"success")}catch(error){setFeedback("taskLogFeedback",error.message||String(error),"error")}}
  async function recalculateReconstructionClub(){try{setFeedback("taskLogFeedback","Recalculating staged Club matches from the stored Chess.com match payloads…");const d=await window.P2K_FRESH_POINTS_RECONSTRUCTION.recalculateClub();setFeedback("taskLogFeedback",`Recalculated ${number(d.processed||0)} staged match payload(s); ${number(d.issues||0)} normalization issue(s) remain.`,Number(d.issues||0)?"error":"success");await refresh({logs:false})}catch(error){setFeedback("taskLogFeedback",error.message||String(error),"error")}}
  async function retryReconstructionClubIssues(){try{setFeedback("taskLogFeedback","Retrying failed/unresolved Club match endpoints…");await window.P2K_FRESH_POINTS_RECONSTRUCTION.retryClubIssues();await refresh({logs:false});await showReconstructionClubIssues()}catch(error){setFeedback("taskLogFeedback",error.message||String(error),"error")}}
  $("reconstructionViewClubIssues")?.addEventListener("click",showReconstructionClubIssues);
  $("reconstructionRecalculateClub")?.addEventListener("click",recalculateReconstructionClub);
  $("reconstructionRetryClubIssues")?.addEventListener("click",retryReconstructionClubIssues);

  async function refreshReconciliationScope(scope){try{setFeedback("taskLogFeedback",`Refreshing ${scope} reconciliation differences…`);const sort=state.reconciliationSort[scope]||{key:"point_delta",direction:"desc"};const effect=scope==="club"?(state.reconciliationEffect.club||"all"):"all";const r=await window.P2K_FRESH_POINTS_RECONSTRUCTION?.refreshDifferences?.(scope,100,0,sort.key,sort.direction,effect);setFeedback("taskLogFeedback",`${number(r?.total||0)} actionable ${scope} difference(s) currently found${scope==="club"&&Number(r?.filtered_total??r?.total)!==Number(r?.total||0)?` · ${number(r.filtered_total||0)} in current impact filter`:""}.`,Number(r?.total||0)?"":"success");renderDetail()}catch(error){setFeedback("taskLogFeedback",error.message||String(error),"error")}}
  async function applyReconciliationDifference(scope,entityKey){const label=scope==="club"?`match #${entityKey}`:`player ${entityKey}`;if(!window.confirm(`Apply the fresh local finding for ${label} to Core? Only this ${scope==="club"?"match":"member"} and its related queue work will be changed.`))return;try{setFeedback("taskLogFeedback",`Synchronizing ${label}…`);const d=await window.P2K_FRESH_POINTS_RECONSTRUCTION.applyDifference(scope,entityKey);setFeedback("taskLogFeedback",`${label} synchronized. ${number(d?.result?.queue_superseded||0)} related queue item(s) superseded.`,"success");await refreshReconciliationScope(scope)}catch(error){setFeedback("taskLogFeedback",error.message||String(error),"error")}}
  async function finalizeReconciliationScope(scope){const live=reconstructionStatus()||{},rec=live.reconciliation?.[scope]||{};if(Number(rec.total||0)>0)return setFeedback("taskLogFeedback",`${number(rec.total)} ${scope} difference(s) still require a decision.`,"error");if(!window.confirm(`Finalize ${scope} reconciliation? Successfully verified identical/applied findings will supersede their obsolete worker jobs; acquisition-error jobs will remain queued.`))return;try{setFeedback("taskLogFeedback",`Finalizing ${scope} reconciliation…`);const d=await window.P2K_FRESH_POINTS_RECONSTRUCTION.finalize(scope);setFeedback("taskLogFeedback",`${scope} reconciliation finalized. ${number(d?.queue_superseded||0)} verified queue item(s) superseded; acquisition-error work was retained.`,"success");await refresh({logs:false})}catch(error){setFeedback("taskLogFeedback",error.message||String(error),"error")}}
  $("reconstructionRefreshClubDifferences")?.addEventListener("click",()=>refreshReconciliationScope("club"));
  $("reconstructionRefreshPlayerDifferences")?.addEventListener("click",()=>refreshReconciliationScope("player"));
  $("reconstructionFinalizeClub")?.addEventListener("click",()=>finalizeReconciliationScope("club"));
  $("reconstructionFinalizePlayer")?.addEventListener("click",()=>finalizeReconciliationScope("player"));
  $("reconstructionClubDeltaFilter")?.addEventListener("change",event=>{state.reconciliationEffect.club=String(event.target?.value||"all");refreshReconciliationScope("club")});
  document.addEventListener("click",event=>{const button=event.target.closest?.("[data-reconciliation-sort]");if(!button)return;const scope=String(button.dataset.reconciliationSort||"");const key=String(button.dataset.sortKey||"");if(!state.reconciliationSort[scope]||!key)return;const current=state.reconciliationSort[scope];const direction=current.key===key?(current.direction==="asc"?"desc":"asc"):(key==="point_delta"?"desc":"asc");state.reconciliationSort[scope]={key,direction};refreshReconciliationScope(scope)});
  document.addEventListener("click",event=>{const button=event.target.closest?.("[data-reconciliation-apply]");if(!button)return;applyReconciliationDifference(String(button.dataset.reconciliationApply||""),String(button.dataset.entityKey||""));});
  $("gatewayProbe").addEventListener("click", async () => {
    setFeedback("gatewayMessage", "Running known-valid Chess.com health probe…");
    try { const result = await endpoint("gateway-probe", { method: "POST", body: {} }); gatewayMetrics(result.gateway); setFeedback("gatewayMessage", result.message, "success"); }
    catch (error) { setFeedback("gatewayMessage", error.message, "error"); await refresh({ logs: false }).catch(() => {}); }
  });
  initialize();

  async function writeJSON(url, {method="GET", body=null}={}) { return window.P2K_TEAM_POINTS_CLIENT.endpointRequest(url,{method,body,username:sessionUsername()}); }


  async function updateTournaments(){try{setFeedback("manualTournamentFeedback","Tournament discovery and missing-medalist jobs queued…");const d=await writeJSON("server/tournaments/public/tournaments.php",{method:"POST",body:{mode:"enqueue-update",discover:true,missingMedalists:true}});setFeedback("manualTournamentFeedback",d.message||"Tournament update jobs queued.","success");await refresh({logs:false})}catch(e){setFeedback("manualTournamentFeedback",e.message,"error")}}
  async function addTournament(){const input=$("manualTournamentInput"),value=input.value.trim();if(!value)return setFeedback("manualTournamentFeedback","Enter a tournament URL or slug.","error");try{setFeedback("manualTournamentFeedback","Adding tournament…");const d=await writeJSON("server/tournaments/public/tournaments.php",{method:"POST",body:{mode:"manual-add",url:value,slug:value}});input.value="";setFeedback("manualTournamentFeedback",d.message||"Tournament added or refreshed.","success");await refresh({logs:false})}catch(e){setFeedback("manualTournamentFeedback",e.message,"error")}}
  function trackedDate(value){
    if(!value)return "—";
    const numeric=Number(value); const date=Number.isFinite(numeric)&&numeric>1000000000?new Date(numeric*1000):new Date(String(value));
    return Number.isFinite(date.getTime())?new Intl.DateTimeFormat("en-GB",{timeZone:"UTC",dateStyle:"medium",timeStyle:"short"}).format(date):String(value);
  }
  function trackedComparator(mode){const time=m=>Number(m.startTime||0),size=m=>Number(m.boardCount||0);if(mode==="date-old")return(a,b)=>(time(a)||Number.MAX_SAFE_INTEGER)-(time(b)||Number.MAX_SAFE_INTEGER);if(mode==="size-large")return(a,b)=>size(b)-size(a);if(mode==="size-small")return(a,b)=>size(a)-size(b);if(mode==="name")return(a,b)=>String(a.name||"").localeCompare(String(b.name||""));return(a,b)=>(time(b)||0)-(time(a)||0);}
  function renderTracked(){
    const q=String($("trackedSearch")?.value||"").trim().toLowerCase(),status=$("trackedStatus")?.value||"",follow=$("trackedFollowState")?.value||"",sort=$("trackedSort")?.value||"date-new";
    const rows=state.trackedMatches.filter(m=>{const hay=[m.name,m.matchId,...(m.teams||[]),...(m.leagueAcronyms||[]),m.status,m.followed?"followed":"unfollowed"].join(" ").toLowerCase();return(!q||hay.includes(q))&&(!status||m.status===status)&&(!follow||(follow==="followed"?m.followed:!m.followed));}).sort(trackedComparator(sort));
    setHTML("trackedRows",rows.map(m=>`<tr><td><a href="${esc(m.url||"#")}" target="_blank" rel="noopener">${esc(m.name||`Match ${m.matchId}`)}</a><br><small>#${esc(m.matchId)}${(m.teams||[]).length?` · ${esc(m.teams.join(" vs "))}`:""}</small></td><td>${esc(m.status||"unknown")}</td><td>${number(m.boardCount)}</td><td><span class="tracked-state ${m.followed?"is-followed":"is-paused"}">${m.followed?"Followed":"Unfollowed"}</span></td><td>${number(m.fileCount)}</td><td>${esc(trackedDate(m.startTime))}</td><td>${esc(trackedDate(m.lastTrackedAt))}</td><td>${esc(trackedDate(m.nextCaptureAt))}<br><small>${esc(m.samplingLabel||"")}</small></td><td><div class="tracked-actions"><button class="control-button" data-history="${esc(m.matchId)}" ${m.hasData?"":"disabled"}>History</button><button class="control-button" data-track="${esc(m.matchId)}" data-follow="${m.followed?"0":"1"}">${m.followed?"Unfollow":(m.autoStopReason==="started-over-24h"?"Record once":"Follow")}</button><button class="control-button danger" data-delete-track="${esc(m.matchId)}" ${m.hasData?"":"disabled"}>Remove data</button></div></td></tr>`).join("")||'<tr><td colspan="9">No matches match the selected filters.</td></tr>');
    document.querySelectorAll("[data-track]").forEach(b=>b.onclick=()=>toggleTracked(b.dataset.track,b.dataset.follow==="1"));
    document.querySelectorAll("[data-delete-track]").forEach(b=>b.onclick=()=>deleteTrackedData(b.dataset.deleteTrack));
    document.querySelectorAll("[data-history]").forEach(b=>b.onclick=()=>openTrackedHistory(b.dataset.history));
  }
  async function loadTracked(){try{const d=await writeJSON("api/tracked-match-data/");state.trackedMatches=Array.isArray(d.matches)?d.matches:[];const sum=d.summary||{};setHTML("trackedSummary",[["Followed",sum.followed||0],["Ongoing",sum.ongoing||0],["Finished",sum.finished||0],["Snapshot files",sum.files||0]].map(([label,value])=>`<article class="metric"><span>${esc(label)}</span><strong>${number(value)}</strong></article>`).join(""));renderTracked();setFeedback("trackedFeedback",`${state.trackedMatches.length} tracked match${state.trackedMatches.length===1?"":"es"} loaded.`,"success")}catch(e){setFeedback("trackedFeedback",e.message,"error")}}
  function trackedFollowFeedback(d){
    const expired=d?.match?.autoStopReason==="started-over-24h"&&!d?.match?.followed;
    if(expired)return d?.captured===false?(d.captureWarning||"Continuous tracking remains stopped because the match started more than 24 hours ago; no new snapshot was available."):"Snapshot recorded once. Continuous tracking remains stopped because the match started more than 24 hours ago.";
    return d?.captured===false?(d.captureWarning||"Match followed; current snapshot unavailable."):"Match followed and current snapshot recorded.";
  }
  async function addTracked(){const input=$("trackedMatchInput"),value=input.value.trim();if(!value)return setFeedback("trackedFeedback","Enter a match ID, URL or slug.","error");try{setFeedback("trackedFeedback","Following match and recording current snapshot…");const d=await writeJSON("api/tracked-match-data/",{method:"POST",body:{action:"follow",match:value}});input.value="";const expired=d?.match?.autoStopReason==="started-over-24h"&&!d?.match?.followed;setFeedback("trackedFeedback",trackedFollowFeedback(d),d.captured===false||expired?"warning":"success");await loadTracked()}catch(e){setFeedback("trackedFeedback",e.message,"error")}}
  async function toggleTracked(id,follow){try{if(!follow&&!confirm(`Unfollow match ${id}? Existing snapshot data will be kept.`))return;if(follow){const d=await writeJSON("api/tracked-match-data/",{method:"POST",body:{action:"follow",match:id}});const expired=d?.match?.autoStopReason==="started-over-24h"&&!d?.match?.followed;setFeedback("trackedFeedback",trackedFollowFeedback(d),d.captured===false||expired?"warning":"success");}else await writeJSON(`api/tracked-match-data/?mode=unfollow&match=${encodeURIComponent(id)}`,{method:"DELETE"});await loadTracked()}catch(e){setFeedback("trackedFeedback",e.message,"error")}}
  async function deleteTrackedData(id){if(!confirm(`Remove every stored snapshot for match ${id}? Follow state will not change.`))return;try{const d=await writeJSON(`api/tracked-match-data/?mode=data&match=${encodeURIComponent(id)}`,{method:"DELETE"});setFeedback("trackedFeedback",`${number(d.deletedFiles)} snapshot file(s) removed.`,"success");await loadTracked()}catch(e){setFeedback("trackedFeedback",e.message,"error")}}
  async function removeFinishedTrackedData(){const count=state.trackedMatches.filter(m=>m.status==="finished"&&Number(m.fileCount||0)>0).length;if(!count)return setFeedback("trackedFeedback","No finished match currently has stored data.","success");if(!confirm(`Remove stored snapshots for all ${count} finished matches?`))return;try{const d=await writeJSON("api/tracked-match-data/?mode=finished-data",{method:"DELETE"});setFeedback("trackedFeedback",`${number(d.deletedFiles)} snapshot file(s) removed from ${number(d.deletedMatches)} finished match(es).`,"success");await loadTracked()}catch(e){setFeedback("trackedFeedback",e.message,"error")}}
  function openTrackedHistory(id){const modal=$("trackedHistoryModal"),body=$("trackedHistoryBody");if(!modal||!body)return;setText("trackedHistoryTitle",`Tracking history · match ${id}`);body.innerHTML=window.P2K_MATCH_HISTORY_UI?.placeholderHTML?.(id)||'<p>History visualization unavailable.</p>';modal.hidden=false;window.P2K_MATCH_HISTORY_UI?.hydrate?.(body);}
  function closeTrackedHistory(){setHidden("trackedHistoryModal",true);$("trackedHistoryBody")?.replaceChildren();}
  async function recordTrackedNow(){const button=$("trackedRecordNow");button.disabled=true;try{setFeedback("trackedFeedback","Loading current tracked match references…");const list=await writeJSON("api/league-match-references/");const refs=Array.isArray(list.references)?list.references:[];let stored=0,failed=0;for(let i=0;i<refs.length;i++){setFeedback("trackedFeedback",`Recording ${i+1} of ${refs.length}…`);try{await writeJSON("api/record-league-match/",{method:"POST",body:{match:refs[i].apiUrl||refs[i].matchId}});stored++;}catch(_){failed++;}}setFeedback("trackedFeedback",`${stored} match snapshot${stored===1?"":"s"} recorded${failed?` · ${failed} failed`:""}.`,failed?"warning":"success");await loadTracked()}catch(e){setFeedback("trackedFeedback",e.message,"error")}finally{button.disabled=false}}


  $("manualTournamentAdd")?.addEventListener("click",addTournament); $("manualTournamentUpdate")?.addEventListener("click",updateTournaments); $("trackedMatchAdd")?.addEventListener("click",addTracked); $("trackedRefresh")?.addEventListener("click",loadTracked); $("trackedRecordNow")?.addEventListener("click",recordTrackedNow); $("trackedRemoveFinished")?.addEventListener("click",removeFinishedTrackedData); $("trackedHistoryClose")?.addEventListener("click",closeTrackedHistory); $("trackedHistoryModal")?.addEventListener("click",e=>{if(e.target===$("trackedHistoryModal"))closeTrackedHistory()}); ["trackedSearch","trackedStatus","trackedFollowState","trackedSort"].forEach(id=>$(id)?.addEventListener(id==="trackedSearch"?"input":"change",renderTracked)); $("trackedClear")?.addEventListener("click",()=>{$("trackedSearch").value="";$("trackedStatus").value="";$("trackedFollowState").value="";$("trackedSort").value="date-new";renderTracked()});

})();
