/* OAuth session context, gateway batching, and adaptive transport ownership. */
(() => {
  "use strict";

  const modules = window.P2K_API_MODULES = window.P2K_API_MODULES || {};
  modules.oauthContext = Object.freeze({
    create(dependencies) {
      const {
        nativeFetch, maxConcurrency, integer, apiError, abortError, normalizeError,
        emitConcurrencyChange, configuredConcurrency, adaptiveConcurrency, retryAfterDeadline
      } = dependencies;
  const OAUTH_GATEWAY_ENDPOINT = String(window.P2K_SITE_CONFIG?.api?.oauthGatewayEndpoint || "server/team-points/public/oauth.php");
  const OAUTH_LOGICAL_CONCURRENCY = 256;
  const OAUTH_INITIAL_TARGET = 8;
  const OAUTH_MIN_CONNECTION_CAP = 3;
  const OAUTH_GATEWAY_BATCH_SIZE = 32;
  // These are browser workload bounds, not upstream concurrent-handle limits.
  const OAUTH_MAX_OUTSTANDING = 90;
  const OAUTH_BACKGROUND_MAX_OUTSTANDING = 16;
  const OAUTH_COALESCE_MS = 30;
  const OAUTH_LATENCY_DEFAULTS = Object.freeze({"player-profile":200,"player-stats":1300,archive:200,"player-matches":800,"match-detail":1600,"club-index":500,roster:900,"club-profile":300,other:600});
  const OAUTH_GATEWAY_MAX_POSTS = 6;
  // P0 interactive survival: background acquisition can never occupy every
  // same-origin OAuth gateway POST lane. One lane is permanently reserved for
  // user-initiated foreground work, and queued foreground work suppresses new
  // background admission until it has crossed the gateway boundary.
  const OAUTH_FOREGROUND_RESERVED_POSTS = 1;
  // v2.9.22.9: same-origin OAuth gateway POSTs occupy PHP-FCGI workers while
  // their cURL-multi batch is active. On shared hosting, five simultaneous
  // background POSTs can starve admin/session/CRON requests even though one
  // *browser gateway* lane is nominally reserved. Keep only two background
  // PHP requests resident; each can still multiplex bounded Chess.com work.
  const OAUTH_BACKGROUND_MAX_POSTS = 2;
  const OAUTH_INTERACTIVE_WAIT_TARGET_MS = 250;
  const OAUTH_INITIAL_RATE_CPS = 24;
  const OAUTH_MIN_RATE_CPS = 1;
  // Compatibility defaults; actual policy and launch rate are owned by PHP.
  const OAUTH_MAX_RATE_CPS = 30;
  const OAUTH_TUNING_KEY = "p2k-oauth-gateway-tuning-v4";
  const OAUTH_TUNING_TTL_MS = 30 * 60 * 1000;
  let oauthBearerMode = false;
  let oauthGatewayTarget = OAUTH_INITIAL_TARGET;
  let oauthGatewayMax = 256;
  let oauthGatewayBestRate = 0;
  let oauthGatewayLatencyByClass = Object.create(null);
  let oauthGatewayRateTarget = OAUTH_INITIAL_RATE_CPS;
  let oauthGatewayRatePolicy = null;
  let oauthGatewayEffectiveCeiling = OAUTH_MAX_RATE_CPS;
  let oauthGatewayObservedLaunchCps = 0;
  let oauthGatewayObservedCleanCps = 0;
  let oauthGatewayControllerObservedAtMs = 0;
  let oauthGatewaySafeRateTarget = 0;
  let oauthGatewayUnsafeRateTarget = 0;
  let oauthGatewayBestTargetRate = 0;

  let oauthGatewayCeilingDiscovered = false;
  let oauthGatewayPeakInFlight = 0;
  let oauthGatewayLastCps = 0;
  let oauthGatewayBatchSequence = 0;
  let oauthGatewayTimer = 0, oauthGatewayTimerAt = 0;
  let oauthGatewayBackgroundNotBeforeMs = 0;
  let oauthGatewayOutstanding = 0, oauthGatewayBackgroundOutstanding = 0;
  const oauthLatencySamples = Object.create(null);
  const oauthScheduler = {revision:1,posts:0,submitted:0,returned:0,launched:0,successes:0,deferred:0,postErrors:0,peakOutstanding:0,peakBackgroundOutstanding:0,queueWaitMs:0,postWallMs:0,serverMs:0,serverTimingPosts:0,acquisitionMs:0,cacheIngestMs:0,reservationMs:0,coordinatorCalls:0,coordinatorWrites:0,coordinatorLockWaitMs:0,peakQueued:0,byEndpoint:{},recentPosts:[]};
  const finitePositive = n => Number.isFinite(Number(n)) && Number(n)>0 ? Number(n) : 0;
  function quantile(values,p=.5) { const a=values.slice().sort((x,y)=>x-y);return a.length?a[Math.floor((a.length-1)*p)]:0; }
  function latencyFor(kind) {
    const fallback=OAUTH_LATENCY_DEFAULTS[kind]||OAUTH_LATENCY_DEFAULTS.other;
    const samples=oauthLatencySamples[kind]||[];
    const median=samples.length>=3?quantile(samples.map(x=>x.median)):Math.max(fallback,finitePositive(oauthGatewayLatencyByClass[kind]));
    const p95=samples.length>=3?quantile(samples.map(x=>x.p95)):median*1.5;
    return {median:Math.max(100,median),p95:Math.max(median,p95),samples:samples.length};
  }
  function outstandingBudget(trafficClass) {
    const budget=Math.max(4,Math.min(OAUTH_MAX_OUTSTANDING,Math.ceil(oauthGatewayRateTarget*3)));
    // Preserve foreground headroom even at a very low learned rate.
    return trafficClass==="background"?Math.max(1,Math.min(OAUTH_BACKGROUND_MAX_OUTSTANDING,Math.floor(budget/2))):budget;
  }
  function availableBudget(trafficClass) {
    const total=outstandingBudget("foreground")-oauthGatewayOutstanding;
    return Math.max(0,trafficClass==="background"?Math.min(total,outstandingBudget(trafficClass)-oauthGatewayBackgroundOutstanding):total);
  }
  function backgroundCooldown(deadline) {
    if (!Number.isFinite(deadline)||deadline<=oauthGatewayBackgroundNotBeforeMs||deadline<=Date.now()) return;
    oauthGatewayBackgroundNotBeforeMs=deadline;
    for (const entry of oauthGatewayQueue) if (!entry.settled&&entry.trafficClass==="background"&&entry.deferMode==="return") entry.finish(false,localCooldownError(entry.url,deadline));
    scheduleOAuthGatewayFlush();
  }
  let oauthGatewayNotBeforeMs = 0;
  let oauthGatewayDeferredRows = 0;
  // The PHP controller owns pacing. This document merely avoids sending work
  // that the server has already told it cannot launch yet.
  function extendOAuthCooldown(deadline) {
    if (!Number.isFinite(deadline) || deadline <= oauthGatewayNotBeforeMs || deadline <= Date.now()) return;
    oauthGatewayNotBeforeMs = deadline;
    for (const entry of oauthGatewayQueue) {
      if (!entry.settled && entry.deferMode === "return") entry.finish(false, localCooldownError(entry.url));
    }
    if (oauthGatewayTimer) window.clearTimeout(oauthGatewayTimer);
    oauthGatewayTimer = 0;
    scheduleOAuthGatewayFlush();
  }
  function localCooldownError(url, deadline=oauthGatewayNotBeforeMs) {
    return apiError("OAuth request deferred in browser before launch.", {
      category:"deferred", code:"OAUTH_DEFERRED", status:503, url, retryable:true, deferred:true,
      notBeforeMs:deadline, retryAfterMs:Math.max(0,deadline-Date.now()), transport:"oauth-bearer"
    });
  }
  function readRetryAfter(headers) {
    return typeof retryAfterDeadline === "function" ? retryAfterDeadline(headers) : 0;
  }
  let oauthGatewayActivePosts = 0;
  let oauthGatewayMaximumActivePosts = 0;
  let oauthGatewayActiveForegroundPosts = 0;
  let oauthGatewayActiveBackgroundPosts = 0;
  let oauthInteractiveProtection = false;
  let oauthInteractiveProtectionSince = 0;
  let oauthForegroundWaitMaxMs = 0;
  let oauthForegroundWaitLastMs = 0;
  let oauthBackgroundAdmissionSuppressions = 0;
  const oauthGatewayQueue = [];
  function loadOAuthTuning() {
    try {
      const parsed = JSON.parse(sessionStorage.getItem(OAUTH_TUNING_KEY) || "null");
      if (!parsed || Date.now() - Number(parsed.updatedAt || 0) > OAUTH_TUNING_TTL_MS) return null;
      return {
        target: integer(parsed.target, OAUTH_INITIAL_TARGET, 1, OAUTH_LOGICAL_CONCURRENCY),
        rateTarget: Math.max(OAUTH_MIN_RATE_CPS, Math.min(OAUTH_MAX_RATE_CPS, Number(parsed.rateTarget || OAUTH_INITIAL_RATE_CPS))),
        safeRateTarget: Math.max(0, Math.min(OAUTH_MAX_RATE_CPS, Number(parsed.safeRateTarget || 0))),
        unsafeRateTarget: Math.max(0, Number(parsed.unsafeRateTarget || 0)),
        bestTargetRate: Math.max(0, Math.min(OAUTH_MAX_RATE_CPS, Number(parsed.bestTargetRate || 0))),
        bestRate: Math.max(0, Number(parsed.bestRate || 0)),
        latencyByClass: parsed.latencyByClass && typeof parsed.latencyByClass === "object" ? parsed.latencyByClass : {}
      };
    } catch (_) { return null; }
  }

  function persistOAuthTuning() {
    try {
      sessionStorage.setItem(OAUTH_TUNING_KEY, JSON.stringify({
        target: oauthGatewayTarget,
        rateTarget: oauthGatewayRateTarget,
        safeRateTarget: oauthGatewaySafeRateTarget,
        unsafeRateTarget: oauthGatewayUnsafeRateTarget,
        bestTargetRate: oauthGatewayBestTargetRate,
        bestRate: oauthGatewayBestRate,
        latencyByClass: oauthGatewayLatencyByClass,
        updatedAt: Date.now()
      }));
    } catch (_) { /* Storage is an optimization only. */ }
  }
  function oauthSessionActive() {
    const auth = window.P2K_AUTH;
    const current = auth?.getSession?.();
    return oauthBearerMode === true && auth?.realOAuth === true && Boolean(current?.oauthVerified || current?.realOAuth || current?.authMode === "real-oauth");
  }

  function oauthHeadersObject(headers) {
    const out = {};
    try { headers?.forEach?.((value, name) => { const key = String(name || "").toLowerCase(); if (["accept","if-none-match","if-modified-since"].includes(key)) out[key] = String(value || ""); }); } catch (_) {}
    return out;
  }

  function oauthBatchEndpointClass(batch) {
    const classes = new Set();
    for (const row of Array.isArray(batch?.results) ? batch.results : []) {
      let path = ""; try { path = new URL(String(row?.url || "")).pathname.toLowerCase(); } catch (_) { path = ""; }
      if (/^\/pub\/match\//.test(path)) classes.add("match-detail");
      else if (/^\/pub\/club\/[^/]+\/matches\/?$/.test(path)) classes.add("club-index");
      else if (/^\/pub\/club\/[^/]+\/members\/?$/.test(path)) classes.add("roster");
      else if (/^\/pub\/club\/[^/]+\/?$/.test(path)) classes.add("club-profile");
      else if (/^\/pub\/player\/[^/]+\/stats\/?$/.test(path)) classes.add("player-stats");
      else if (/^\/pub\/player\/[^/]+\/matches\/?$/.test(path)) classes.add("player-matches");
      else if (/^\/pub\/player\/[^/]+\/games(?:\/|$)/.test(path)) classes.add("archive");
      else if (/^\/pub\/player\/[^/]+\/?$/.test(path)) classes.add("player-profile");
      else classes.add("other");
      if (classes.size > 1) return "mixed";
    }
    return classes.values().next().value || "unknown";
  }

  function oauthConcurrencyForRate(rateCps, medianMs = 0, p95Ms = 0) {
    const rate = Math.max(OAUTH_MIN_RATE_CPS, Math.min(OAUTH_MAX_RATE_CPS, Number(rateCps) || OAUTH_INITIAL_RATE_CPS));
    const serviceMs = Math.max(100, Number(medianMs) || 0, (Number(p95Ms) || 0) * 0.55);
    // Little's law plus headroom: enough in-flight work to sustain the learned rate,
    // but no giant connection burst just because logical page concurrency is 256.
    return Math.max(Math.min(OAUTH_MIN_CONNECTION_CAP, oauthGatewayMax), Math.min(oauthGatewayMax, Math.ceil(rate * (serviceMs / 1000) * 1.35 + 1)));
  }

  function adaptOAuthGateway(batch) {
    // v2.9.12: the server-side OAuthRateCoordinator is authoritative across every
    // frame/requester that shares the OAuth token. The browser mirrors diagnostics
    // and sizes its per-POST connection cap; it no longer independently punishes or
    // probes the same upstream event a second time.
    const controller = batch?.controller && typeof batch.controller === "object" ? batch.controller : {};
    const remaining = Number(controller.cooldown_remaining_ms || 0);
    if (remaining > 0) extendOAuthCooldown(Date.now() + remaining);
    const observedAt = Number(controller.observed_at_ms || 0);
    // Concurrent POSTs may arrive out of order. An older state must not replace
    // a newer shared rate, boundary or latency snapshot.
    if (observedAt > 0 && observedAt < oauthGatewayControllerObservedAtMs) return;
    if (observedAt > 0) oauthGatewayControllerObservedAtMs = observedAt;
    if (controller.rate_policy && typeof controller.rate_policy === "object") oauthGatewayRatePolicy = { ...controller.rate_policy };
    const policyMax = Math.max(OAUTH_MIN_RATE_CPS, Math.min(OAUTH_MAX_RATE_CPS, Number(oauthGatewayRatePolicy?.maximum_rate_cps) || OAUTH_MAX_RATE_CPS));
    oauthGatewayEffectiveCeiling = Math.max(OAUTH_MIN_RATE_CPS, Math.min(policyMax, Number(controller.effective_ceiling_cps) || policyMax));
    oauthGatewayObservedLaunchCps = Math.max(0, Number(controller.observed_launch_cps || 0));
    oauthGatewayObservedCleanCps = Math.max(0, Number(controller.observed_clean_cps || 0));
    const serverRate = Math.max(OAUTH_MIN_RATE_CPS, Math.min(oauthGatewayEffectiveCeiling, Number(controller.rate_target_cps || batch?.rate_cps || oauthGatewayRateTarget || OAUTH_INITIAL_RATE_CPS)));
    oauthGatewayRateTarget = serverRate;
    oauthGatewaySafeRateTarget = Math.max(0, Math.min(oauthGatewayEffectiveCeiling, Number(controller.safe_rate_cps || 0)));
    oauthGatewayUnsafeRateTarget = Math.max(0, Number(controller.unsafe_rate_cps || 0));
    oauthGatewayLastCps = Math.max(0, Number(batch?.launch_cps || batch?.cps || 0));
    oauthGatewayPeakInFlight = Math.max(oauthGatewayPeakInFlight, Number(batch?.peak_in_flight || 0));
    const reportedCapacity = Number(batch?.transport_capacity || 0);
    if (Number.isFinite(reportedCapacity) && reportedCapacity > 0) {
      oauthGatewayMax = Math.max(1, Math.min(256, reportedCapacity));
    } else {
      // Legacy servers reported transport_cap as min(real capacity, batch size).
      // Never let a tiny batch permanently collapse the browser transport ceiling.
      const legacyCap = Number(batch?.transport_cap || 0);
      if (Number.isFinite(legacyCap) && legacyCap > oauthGatewayMax) oauthGatewayMax = Math.min(256, legacyCap);
    }
    const endpointClass = String(batch?.endpoint_class || oauthBatchEndpointClass(batch) || "unknown");
    const baselines = controller.latency_baseline_ms;
    if (baselines && typeof baselines === "object") for (const [kind,value] of Object.entries(baselines)) if (finitePositive(value)) oauthGatewayLatencyByClass[kind]=Number(value);
    // Learn from successful individual upstream timings, never the wall time of
    // a POST, a tiny tail, a throttled response, or a mixed endpoint wave.
    const rows=Array.isArray(batch?.results)?batch.results:[];
    const samples=rows.filter(row=>!row.deferred&&Number(row.status)>=200&&Number(row.status)<400).map(row=>finitePositive(row.elapsed_ms)).filter(Boolean);
    if (samples.length>=4&&samples.length===rows.length&&endpointClass!=="mixed"&&endpointClass!=="unknown") {
      const history=oauthLatencySamples[endpointClass] ||= [];
      history.push({median:quantile(samples),p95:quantile(samples,.95)});if(history.length>9)history.shift();
    }
    oauthGatewayBestRate = Math.max(oauthGatewayBestRate, oauthGatewayLastCps);
    oauthGatewayBestTargetRate = Math.max(oauthGatewayBestTargetRate, oauthGatewaySafeRateTarget);
    oauthGatewayCeilingDiscovered = oauthGatewayUnsafeRateTarget > 0;
    // The last requested handle cap remains a compatibility diagnostic. It is
    // not used to size another endpoint's batch.
    persistOAuthTuning();
    emitConcurrencyChange(String(controller.reason || "oauth-server-rate-sync"));
  }

  function oauthEndpointClass(url) {
    let path = ""; try { path = new URL(String(url || "")).pathname.toLowerCase(); } catch (_) { return "other"; }
    if (/^\/pub\/match\//.test(path)) return "match-detail";
    if (/^\/pub\/club\/[^/]+\/matches\/?$/.test(path)) return "club-index";
    if (/^\/pub\/club\/[^/]+\/members\/?$/.test(path)) return "roster";
    if (/^\/pub\/club\/[^/]+\/?$/.test(path)) return "club-profile";
    if (/^\/pub\/player\/[^/]+\/stats\/?$/.test(path)) return "player-stats";
    if (/^\/pub\/player\/[^/]+\/matches\/?$/.test(path)) return "player-matches";
    if (/^\/pub\/player\/[^/]+\/games(?:\/|$)/.test(path)) return "archive";
    if (/^\/pub\/player\/[^/]+\/?$/.test(path)) return "player-profile";
    return "other";
  }

  function oauthQueuedCounts() {
    let foreground = 0, background = 0, oldestForegroundAt = 0;
    for (const entry of oauthGatewayQueue) {
      if (entry.settled) continue;
      if (entry.trafficClass === "background") background++;
      else {
        foreground++;
        const queuedAt = Number(entry.queuedAt || 0);
        if (queuedAt > 0 && (!oldestForegroundAt || queuedAt < oldestForegroundAt)) oldestForegroundAt = queuedAt;
      }
    }
    return { foreground, background, oldestForegroundAt };
  }

  function serverForegroundPressure() {
    return Math.max(0, Number(window.P2K_SERVER_FOREGROUND_REQUESTS || 0)) > 0;
  }

  function updateInteractiveProtection(reason = "queue-change") {
    const counts = oauthQueuedCounts();
    const active = serverForegroundPressure() || counts.foreground > 0 || oauthGatewayActiveForegroundPosts > 0;
    if (active !== oauthInteractiveProtection) {
      oauthInteractiveProtection = active;
      oauthInteractiveProtectionSince = active ? Date.now() : 0;
      try {
        window.dispatchEvent(new CustomEvent("p2k-api-interactive-protection", {
          detail: { active, reason, at: Date.now(), foregroundQueued: counts.foreground, backgroundQueued: counts.background }
        }));
      } catch (_) {}
    }
    return counts;
  }

  function canLaunchOAuthTraffic(trafficClass) {
    const counts = updateInteractiveProtection("admission-check");
    if (trafficClass !== "background") return oauthGatewayActivePosts < OAUTH_GATEWAY_MAX_POSTS && availableBudget(trafficClass)>0;
    // Server-admin requests do not pass through the OAuth gateway queue, but they
    // compete for the same PHP-FCGI process pool. Treat them as P0 foreground too.
    if (serverForegroundPressure()) return false;
    // Reserve gateway capacity even before foreground arrives. Once foreground is
    // queued, stop admitting new background waves altogether.
    if (counts.foreground > 0 || oauthGatewayActiveForegroundPosts > 0) return false;
    return oauthGatewayActiveBackgroundPosts < OAUTH_BACKGROUND_MAX_POSTS && oauthGatewayActivePosts < OAUTH_BACKGROUND_MAX_POSTS && availableBudget(trafficClass)>0;
  }

  function priorityOf(entry) { return entry.priority+Math.floor(Math.max(0,Date.now()-entry.queuedAt)/5000); }
  function batchPlan(trafficClass) {
    const candidates=oauthGatewayQueue.filter(e=>!e.settled&&e.trafficClass===trafficClass).sort((a,b)=>priorityOf(b)-priorityOf(a)||a.sequence-b.sequence);
    const seed=candidates[0];if(!seed)return null;
    const rate=oauthGatewayRateTarget, model=latencyFor(seed.endpointClass);
    const cap=Math.min(OAUTH_GATEWAY_BATCH_SIZE,trafficClass==="background"?8:24,availableBudget(trafficClass),Math.max(1,Math.ceil(rate*.8)),Math.max(1,Math.floor(Math.max(0,seed.timeoutMs-model.p95-500)*rate/1000)));
    if(cap<=0)return null;
    // Sort before taking the bounded slice. Keep incompatible timeout budgets
    // apart, and do not fill urgent waves with much lower priority bulk work.
    const entries=candidates.filter(e=>e.endpointClass===seed.endpointClass&&e.timeoutMs===seed.timeoutMs&&e.priority>=seed.priority-19).slice(0,cap);
    return {entries,seed,cap,model};
  }
  function takeOAuthGatewayBatch(plan) {
    const chosen=new Set(plan.entries);for(let i=oauthGatewayQueue.length-1;i>=0;i--)if(chosen.has(oauthGatewayQueue[i]))oauthGatewayQueue.splice(i,1);
    return plan.entries;
  }
  function scheduleOAuthGatewayFlush() {
    for(let i=oauthGatewayQueue.length-1;i>=0;i--)if(oauthGatewayQueue[i].settled)oauthGatewayQueue.splice(i,1);
    if(!oauthGatewayQueue.length){if(oauthGatewayTimer)window.clearTimeout(oauthGatewayTimer);oauthGatewayTimer=0;oauthGatewayTimerAt=0;updateInteractiveProtection("empty-queue");return;}
    if(oauthGatewayTimer&&oauthGatewayTimerAt<=Date.now())return;
    const counts=updateInteractiveProtection("schedule"),traffic=counts.foreground?"foreground":"background";
    if(!canLaunchOAuthTraffic(traffic)){if(traffic==="background")oauthBackgroundAdmissionSuppressions++;if(oauthGatewayTimer)window.clearTimeout(oauthGatewayTimer);oauthGatewayTimer=0;oauthGatewayTimerAt=0;return;}
    const now=Date.now(),gate=Math.max(oauthGatewayNotBeforeMs,traffic==="background"?oauthGatewayBackgroundNotBeforeMs:0);
    const plan=batchPlan(traffic);if(!plan)return;
    // Initial/interactive work starts immediately. Coalesce only tiny bulk tails
    // while other POSTs are already serving work, and cap the wait at 30 ms.
    const coalesce=oauthGatewayActivePosts>0&&plan.entries.length<Math.min(4,plan.cap)&&plan.seed.priority<20?Math.max(0,OAUTH_COALESCE_MS-(now-plan.seed.queuedAt)):0;
    const at=now+Math.min(60000,Math.max(0,Math.ceil(gate-now),coalesce));
    if(oauthGatewayTimer&&oauthGatewayTimerAt===at)return;
    if(oauthGatewayTimer)window.clearTimeout(oauthGatewayTimer);
    oauthGatewayTimerAt=at;oauthGatewayTimer=window.setTimeout(flushOAuthGateway,at-now);
  }

  async function flushOAuthGateway() {
    oauthGatewayTimer = 0; oauthGatewayTimerAt=0;
    if (oauthGatewayNotBeforeMs > Date.now()) { scheduleOAuthGatewayFlush(); return; }
    const countsBefore = updateInteractiveProtection("flush");
    const preferredTraffic = countsBefore.foreground > 0 ? "foreground" : "background";
    if (!canLaunchOAuthTraffic(preferredTraffic)) {
      if (preferredTraffic === "background") oauthBackgroundAdmissionSuppressions++;
      return;
    }
    if(preferredTraffic==="background"&&oauthGatewayBackgroundNotBeforeMs>Date.now()){scheduleOAuthGatewayFlush();return;}
    const plan=batchPlan(preferredTraffic);if(!plan)return;
    const entries = takeOAuthGatewayBatch(plan).filter(entry => !entry.settled);
    if (!entries.length) { if (oauthGatewayQueue.length) scheduleOAuthGatewayFlush(); return; }
    const active = entries.filter(entry => !entry.signal?.aborted);
    entries.filter(entry => entry.signal?.aborted).forEach(entry => entry.finish(false, abortError(entry.url)));
    if (!active.length) { if (oauthGatewayQueue.length) scheduleOAuthGatewayFlush(); return; }
    const csrf = String(window.P2K_AUTH?.getCsrf?.() || "");
    if (!csrf || !oauthSessionActive()) {
      active.forEach(entry => entry.finish(false, apiError("OAuth session is not ready.", { category:"forbidden", code:"OAUTH_SESSION_NOT_READY", status:401, url:entry.url, retryable:false })));
      if (oauthGatewayQueue.length) scheduleOAuthGatewayFlush();
      return;
    }
    const trafficClass = active[0]?.trafficClass === "background" ? "background" : "foreground";
    oauthGatewayActivePosts += 1;
    oauthGatewayOutstanding+=active.length;
    if(trafficClass==="background")oauthGatewayBackgroundOutstanding+=active.length;
    oauthScheduler.peakOutstanding=Math.max(oauthScheduler.peakOutstanding,oauthGatewayOutstanding);
    oauthScheduler.peakBackgroundOutstanding=Math.max(oauthScheduler.peakBackgroundOutstanding,oauthGatewayBackgroundOutstanding);
    oauthScheduler.posts++;oauthScheduler.submitted+=active.length;
    const postStarted=Date.now(),endpointClass=active[0].endpointClass;
    const postConcurrency=Math.min(active.length,oauthConcurrencyForRate(oauthGatewayRateTarget,plan.model.median,plan.model.p95));
    oauthGatewayTarget=postConcurrency;
    const residence={endpointClass,trafficClass,submitted:active.length,concurrency:postConcurrency,queueWaitMs:Math.max(...active.map(e=>postStarted-e.queuedAt)),timeoutMs:active[0].timeoutMs,wallMs:0};
    oauthScheduler.queueWaitMs+=active.reduce((n,e)=>n+Math.max(0,postStarted-e.queuedAt),0);
    if (trafficClass === "background") oauthGatewayActiveBackgroundPosts += 1;
    else oauthGatewayActiveForegroundPosts += 1;
    oauthGatewayMaximumActivePosts = Math.max(oauthGatewayMaximumActivePosts, oauthGatewayActivePosts);
    if (trafficClass !== "background") {
      const oldest = Math.min(...active.map(entry => Number(entry.queuedAt || Date.now())));
      const waitMs = Math.max(0, Date.now() - oldest);
      oauthForegroundWaitLastMs = waitMs;
      oauthForegroundWaitMaxMs = Math.max(oauthForegroundWaitMaxMs, waitMs);
    }
    // Fill the remaining HTTP lanes immediately. Background waves can use only the
    // non-reserved lanes; a queued foreground wave suppresses further background.
    if (oauthGatewayQueue.length) scheduleOAuthGatewayFlush();
    const requests = active.map(entry => ({ id: entry.id, url: entry.url, headers: oauthHeadersObject(entry.headers) }));
    const postTimeoutMs = Math.max(1_000, Math.min(...active.map(entry => Math.max(1_000, Number(entry.timeoutMs) || 20_000))));
    const postController = new AbortController();
    const postTimer = window.setTimeout(() => postController.abort(), postTimeoutMs);
    try {
      const endpoint = new URL(OAUTH_GATEWAY_ENDPOINT, window.location.href); endpoint.searchParams.set("action", "batch");
      const response = await nativeFetch(endpoint.href, {
        method:"POST", credentials:"same-origin", cache:"no-store", signal:postController.signal,
        headers:{"Content-Type":"application/json","Accept":"application/json","X-P2K-OAuth-CSRF":csrf},
        body:JSON.stringify({
          requests,
          concurrency: postConcurrency,
          // This is a ceiling/permission, not the actual launch target. v2.9.12's
          // server-side coordinator supplies the learned shared target.
          rate_cps: OAUTH_MAX_RATE_CPS,
          traffic_class: trafficClass
        })
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || payload?.ok !== true) {
        // A failed gateway is not a request to switch the verified session to
        // anonymous mode. The authoritative session bootstrap owns that decision.
        const deadline = readRetryAfter(response.headers);
        const notBeforeMs = response.status === 429 ? Math.max(deadline, Date.now() + 2000) : deadline;
        if (response.status === 429 || response.status === 503) extendOAuthCooldown(notBeforeMs);
        throw apiError(payload?.error?.message || `OAuth API gateway HTTP ${response.status}`, {
          category: response.status===401 || response.status===403 ? "forbidden" : response.status===429 ? "rate-limit" : "server",
          code: payload?.error?.code || "OAUTH_GATEWAY_ERROR",
          status: response.status, transport: "oauth-bearer",
          notBeforeMs, retryAfterMs: Math.max(0, notBeforeMs - Date.now()),
          retryable: response.status===429 || response.status>=500
        });
      }
      adaptOAuthGateway(payload);
      const rows = Array.isArray(payload.results) ? payload.results : [];
      const timing=payload.gateway_timing||{};
      const returned=rows.filter(r=>!r.deferred).length,successes=rows.filter(r=>!r.deferred&&r.status>=200&&r.status<400).length,deferred=rows.filter(r=>r.deferred===true).length;
      Object.assign(residence,{returned,successes,deferred,serverMs:finitePositive(payload.elapsed_ms),acquisitionMs:finitePositive(timing.acquisition_ms),cacheIngestMs:finitePositive(timing.cache_ingest_ms),reservationMs:finitePositive(timing.reservation_call_ms),peakHandles:finitePositive(payload.peak_in_flight)});
      oauthScheduler.returned+=returned;oauthScheduler.launched+=finitePositive(payload.launched)||returned;oauthScheduler.successes+=successes;oauthScheduler.deferred+=deferred;
      if(Number.isFinite(Number(payload.elapsed_ms))&&Number(payload.elapsed_ms)>0)oauthScheduler.serverTimingPosts++;
      for(const field of ["serverMs","acquisitionMs","cacheIngestMs","reservationMs"])oauthScheduler[field]+=residence[field];
      oauthScheduler.coordinatorCalls+=finitePositive(timing.coordinator_io?.calls);oauthScheduler.coordinatorWrites+=finitePositive(timing.coordinator_io?.writes);oauthScheduler.coordinatorLockWaitMs+=finitePositive(timing.coordinator_io?.lock_wait_ms);
      // Establish the maximum known deadline before settling any subscribers,
      // even if the throttled row belongs to an already cancelled subscriber.
      for (const row of rows) {
        if (row.deferred === true || Number(row.status) === 429) {
          const deadline = readRetryAfter(new Headers(row.headers || {}));
          const until=Math.max(deadline,Date.now()+(deadline>Date.now()?0:2000));
          if(row.deferred===true&&row.deferred_reason==="foreground-protection"&&trafficClass==="background")backgroundCooldown(until);else extendOAuthCooldown(until);
        }
        if (row.deferred === true) oauthGatewayDeferredRows++;
      }
      const resultMap = new Map(rows.map(row => [String(row.id), row]));
      active.forEach(entry => {
        if (entry.signal?.aborted) return entry.finish(false, abortError(entry.url));
        const row = resultMap.get(entry.id);
        if (!row) return entry.finish(false, apiError("OAuth gateway omitted a response.", { category:"network", code:"OAUTH_GATEWAY_MISSING_RESULT", url:entry.url, retryable:true }));
        const status = Number(row.status || 0); const h = new Headers(row.headers || {});
        if (row.deferred === true) {
          const notBeforeMs = Math.max(oauthGatewayNotBeforeMs, trafficClass==="background"?oauthGatewayBackgroundNotBeforeMs:0, readRetryAfter(h));
          return entry.finish(false, apiError("OAuth request deferred before launch.", {
            category:"deferred", code:"OAUTH_DEFERRED", status:503, url:entry.url,
            retryable:true, deferred:true, notBeforeMs, retryAfterMs:Math.max(0,notBeforeMs-Date.now()), transport:"oauth-bearer"
          }));
        }
        if (status === 0) return entry.finish(false, apiError("Chess.com OAuth transport failed.", {
          category:"network", code:"OAUTH_UPSTREAM_TRANSPORT", status:0, url:entry.url, retryable:true, transport:"oauth-bearer"
        }));
        const body = status === 304 || status === 204 || status === 205 ? null : String(row.body ?? "");
        try { entry.finish(true, new Response(body, { status, statusText: String(row.status_text || ""), headers: h })); }
        catch (cause) { entry.finish(false, apiError("Unable to construct OAuth gateway response.", { category:"parse", code:"OAUTH_GATEWAY_RESPONSE", status, url:entry.url, retryable:true, cause })); }
      });
    } catch (error) {
      oauthScheduler.postErrors++;residence.error=true;
      if (postController.signal.aborted) {
        active.forEach(entry => entry.finish(false, apiError("OAuth API gateway timed out.", {
          category:"timeout", code:"OAUTH_GATEWAY_TIMEOUT", url:entry.url, retryable:true
        })));
      } else {
        active.forEach(entry => entry.finish(false, normalizeError(error, entry.url, entry.signal)));
      }
    } finally {
      window.clearTimeout(postTimer);
      residence.wallMs=Math.max(0,Date.now()-postStarted);oauthScheduler.postWallMs+=residence.wallMs;
      const endpoint=oauthScheduler.byEndpoint[endpointClass] ||= {posts:0,submitted:0,returned:0,successes:0,deferred:0,wallMs:0};
      endpoint.posts++;endpoint.submitted+=active.length;endpoint.returned+=residence.returned||0;endpoint.successes+=residence.successes||0;endpoint.deferred+=residence.deferred||0;endpoint.wallMs+=residence.wallMs;
      oauthScheduler.recentPosts.push(residence);if(oauthScheduler.recentPosts.length>80)oauthScheduler.recentPosts.shift();
      oauthGatewayOutstanding=Math.max(0,oauthGatewayOutstanding-active.length);
      if(trafficClass==="background")oauthGatewayBackgroundOutstanding=Math.max(0,oauthGatewayBackgroundOutstanding-active.length);
      oauthGatewayActivePosts = Math.max(0, oauthGatewayActivePosts - 1);
      if (trafficClass === "background") oauthGatewayActiveBackgroundPosts = Math.max(0, oauthGatewayActiveBackgroundPosts - 1);
      else oauthGatewayActiveForegroundPosts = Math.max(0, oauthGatewayActiveForegroundPosts - 1);
      updateInteractiveProtection("post-complete");
      if (oauthGatewayQueue.length) scheduleOAuthGatewayFlush();
    }
  }

  function executeOAuthGateway(url, { headers, signal, priority = 0, trafficClass = "foreground", timeoutMs = 20_000, deferMode = "wait" }) {
    if (signal?.aborted) return Promise.reject(abortError(url));
    const gate=Math.max(oauthGatewayNotBeforeMs,trafficClass==="background"?oauthGatewayBackgroundNotBeforeMs:0);
    if (deferMode === "return" && gate > Date.now()) return Promise.reject(localCooldownError(url,gate));
    return new Promise((resolve, reject) => {
      const entry = {
        id:`g${++oauthGatewayBatchSequence}`,
        sequence: oauthGatewayBatchSequence,
        url, headers, signal, deferMode,
        timeoutMs: Math.max(1_000, Math.min(120_000, Number(timeoutMs) || 20_000)),
        priority: Number(priority) || 0,
        trafficClass: trafficClass === "background" ? "background" : "foreground",
        endpointClass: oauthEndpointClass(url),
        queuedAt: Date.now(),
        settled:false,
        finish(ok,value){
          if (entry.settled) return;
          entry.settled=true;
          signal?.removeEventListener?.("abort", onAbort);
          ok ? resolve(value) : reject(value);
        }
      };
      const onAbort = () => { entry.finish(false, abortError(url)); scheduleOAuthGatewayFlush(); };
      signal?.addEventListener?.("abort", onAbort, { once:true });
      oauthGatewayQueue.push(entry);oauthScheduler.peakQueued=Math.max(oauthScheduler.peakQueued,oauthGatewayQueue.length);
      updateInteractiveProtection("enqueue");
      scheduleOAuthGatewayFlush();
    });
  }


  async function waitForRealOAuthDecision() {
    let explicitMode = "";
    try { explicitMode = String(new URLSearchParams(window.location.search).get("oauth") || ""); } catch (_) { explicitMode = ""; }
    // ?oauth=1 is intentionally simulated and serial even if a real P2KOAUTH cookie
    // also exists. Every other page waits for the real-session probe so a persisted
    // server session remains the authority for Bearer mode even when no OAuth URL flag is present.
    if (explicitMode === "1" || oauthBearerMode) return;
    let ready = window.P2K_REAL_OAUTH_READY;
    if (!ready && document.readyState === "loading") {
      await new Promise(resolve => document.addEventListener("DOMContentLoaded", resolve, { once: true }));
      ready = window.P2K_REAL_OAUTH_READY;
    }
    if (ready && typeof ready.then === "function") {
      try { await ready; } catch (_) { /* A failed session probe falls back to serial public mode. */ }
    }
  }
  function setEnabled(enabled) {
    const wasEnabled = oauthBearerMode;
    oauthBearerMode = Boolean(enabled);
    if (oauthBearerMode && !wasEnabled) {
      const saved = loadOAuthTuning();
      oauthGatewayRatePolicy = null;
      oauthGatewayEffectiveCeiling = OAUTH_MAX_RATE_CPS;
      oauthGatewayObservedLaunchCps = 0;
      oauthGatewayObservedCleanCps = 0;
      oauthGatewayControllerObservedAtMs = 0;
      oauthGatewayTarget = Math.max(1, Math.min(oauthGatewayMax, Math.max(OAUTH_INITIAL_TARGET, Number(saved?.target || 0))));
      oauthGatewayRateTarget = Math.max(OAUTH_MIN_RATE_CPS, Math.min(OAUTH_MAX_RATE_CPS, Number(saved?.rateTarget || OAUTH_INITIAL_RATE_CPS)));
      oauthGatewaySafeRateTarget = Math.max(0, Number(saved?.safeRateTarget || 0));
      oauthGatewayUnsafeRateTarget = Math.max(0, Number(saved?.unsafeRateTarget || 0));
      oauthGatewayBestTargetRate = Math.max(0, Number(saved?.bestTargetRate || 0));
      oauthGatewayBestRate = Math.max(0, Number(saved?.bestRate || 0));
      oauthGatewayLatencyByClass = saved?.latencyByClass && typeof saved.latencyByClass === "object" ? { ...saved.latencyByClass } : Object.create(null);

      oauthGatewayCeilingDiscovered = oauthGatewayUnsafeRateTarget > 0;
    }
    return oauthBearerMode;
  }

  function recommendedFeederDepth() { return Math.min(OAUTH_LOGICAL_CONCURRENCY,Math.max(24,Math.ceil(oauthGatewayRateTarget*4))); }
  function effectiveBatchConcurrency() {
    return oauthBearerMode
      ? recommendedFeederDepth()
      : Math.max(1, Math.min(maxConcurrency, configuredConcurrency(), adaptiveConcurrency()));
  }

  function diagnostics() {
    return {
      oauthBearerMode,
      oauthGatewayTarget, oauthGatewayMax,
      oauthLogicalFeederConcurrency: recommendedFeederDepth(),
      oauthLogicalFeederMaximum: OAUTH_LOGICAL_CONCURRENCY,
      oauthTransportCapacity: oauthGatewayMax,
      oauthGatewayRateTarget, oauthGatewaySafeRateTarget, oauthGatewayUnsafeRateTarget,
      oauthGatewayRatePolicy: oauthGatewayRatePolicy ? { ...oauthGatewayRatePolicy } : null,
      oauthGatewayEffectiveCeiling, oauthGatewayObservedLaunchCps, oauthGatewayObservedCleanCps, oauthGatewayControllerObservedAtMs,
      oauthGatewayBestTargetRate, oauthGatewayLastCps, oauthGatewayBestRate,
      oauthGatewayPeakInFlight, oauthGatewayCeilingDiscovered,
      oauthGatewayLatencyByClass: { ...oauthGatewayLatencyByClass },
      oauthGatewayNotBeforeMs, oauthGatewayBackgroundNotBeforeMs, oauthGatewayDeferredRows,
      oauthScheduler:{...oauthScheduler,byEndpoint:Object.fromEntries(Object.entries(oauthScheduler.byEndpoint).map(([k,v])=>[k,{...v}])),recentPosts:oauthScheduler.recentPosts.map(p=>({...p})),outstanding:oauthGatewayOutstanding,backgroundOutstanding:oauthGatewayBackgroundOutstanding,budget:outstandingBudget("foreground"),backgroundBudget:outstandingBudget("background"),endpointModels:Object.fromEntries(Object.keys(OAUTH_LATENCY_DEFAULTS).map(k=>[k,latencyFor(k)]))},
      oauthGatewayActivePosts, oauthGatewayMaximumActivePosts,
      oauthGatewayActiveForegroundPosts, oauthGatewayActiveBackgroundPosts,
      oauthGatewayQueued: oauthGatewayQueue.length,
      oauthGatewayForegroundQueued: oauthGatewayQueue.filter(entry => !entry.settled && entry.trafficClass !== "background").length,
      oauthGatewayBackgroundQueued: oauthGatewayQueue.filter(entry => !entry.settled && entry.trafficClass === "background").length,
      oauthForegroundReservedPosts: OAUTH_FOREGROUND_RESERVED_POSTS,
      oauthBackgroundMaxPosts: OAUTH_BACKGROUND_MAX_POSTS,
      serverForegroundRequests: Math.max(0, Number(window.P2K_SERVER_FOREGROUND_REQUESTS || 0)),
      oauthInteractiveWaitTargetMs: OAUTH_INTERACTIVE_WAIT_TARGET_MS,
      oauthForegroundWaitLastMs, oauthForegroundWaitMaxMs,
      oauthBackgroundAdmissionSuppressions,
      oauthInteractiveProtection,
      oauthInteractiveProtectionSince
    };
  }

  function handleServerForegroundPressure() {
    updateInteractiveProtection("server-foreground-pressure");
    if (!serverForegroundPressure()) scheduleOAuthGatewayFlush();
  }

  return Object.freeze({
    isBearerMode: () => oauthBearerMode,
    setEnabled,
    sessionActive: oauthSessionActive,
    executeGateway: executeOAuthGateway,
    waitForRealDecision: waitForRealOAuthDecision,
    observeBatch(batch) {
      if (!oauthBearerMode || !batch || typeof batch !== "object") return false;
      adaptOAuthGateway(batch);
      scheduleOAuthGatewayFlush();
      return true;
    },
    effectiveBatchConcurrency,
    diagnostics,
    handleServerForegroundPressure,
    logicalConcurrency: OAUTH_LOGICAL_CONCURRENCY
  });
    }
  });
})();

