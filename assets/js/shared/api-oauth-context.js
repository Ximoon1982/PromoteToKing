/* OAuth session context, gateway batching, and adaptive transport ownership. */
(() => {
  "use strict";

  const modules = window.P2K_API_MODULES = window.P2K_API_MODULES || {};
  modules.oauthContext = Object.freeze({
    create(dependencies) {
      const {
        nativeFetch, maxConcurrency, integer, apiError, abortError, normalizeError,
        emitConcurrencyChange, configuredConcurrency, adaptiveConcurrency
      } = dependencies;
  const OAUTH_GATEWAY_ENDPOINT = String(window.P2K_SITE_CONFIG?.api?.oauthGatewayEndpoint || "server/team-points/public/oauth.php");
  const OAUTH_LOGICAL_CONCURRENCY = 256;
  const OAUTH_INITIAL_TARGET = 8;
  const OAUTH_MIN_CONNECTION_CAP = 3;
  const OAUTH_GATEWAY_BATCH_SIZE = 32;
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
  // PHP requests resident; each can still multiplex up to 32 Chess.com calls.
  const OAUTH_BACKGROUND_MAX_POSTS = 2;
  const OAUTH_INTERACTIVE_WAIT_TARGET_MS = 250;
  const OAUTH_INITIAL_RATE_CPS = 30;
  const OAUTH_MIN_RATE_CPS = 1;
  const OAUTH_MAX_RATE_CPS = 120;
  const OAUTH_TUNING_KEY = "p2k-oauth-gateway-tuning-v4";
  const OAUTH_TUNING_TTL_MS = 30 * 60 * 1000;
  let oauthBearerMode = false;
  let oauthGatewayTarget = OAUTH_INITIAL_TARGET;
  let oauthGatewayMax = 256;
  let oauthGatewayBestRate = 0;
  let oauthGatewayBestMedianLatency = 0;
  let oauthGatewayLatencyByClass = Object.create(null);
  let oauthGatewayRateTarget = OAUTH_INITIAL_RATE_CPS;
  let oauthGatewaySafeRateTarget = 0;
  let oauthGatewayUnsafeRateTarget = 0;
  let oauthGatewayBestTargetRate = 0;
  let oauthGatewayStableSamples = 0;
  let oauthGatewayPlateauStrikes = 0;
  let oauthGatewayCeilingDiscovered = false;
  let oauthGatewayPeakInFlight = 0;
  let oauthGatewayLastCps = 0;
  let oauthGatewayBatchSequence = 0;
  let oauthGatewayTimer = 0;
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
        unsafeRateTarget: Math.max(0, Math.min(OAUTH_MAX_RATE_CPS, Number(parsed.unsafeRateTarget || 0))),
        bestTargetRate: Math.max(0, Math.min(OAUTH_MAX_RATE_CPS, Number(parsed.bestTargetRate || 0))),
        bestRate: Math.max(0, Number(parsed.bestRate || 0)),
        bestMedianLatency: Math.max(0, Number(parsed.bestMedianLatency || 0)),
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
        bestMedianLatency: oauthGatewayBestMedianLatency,
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
      else if (/^\/pub\/player\/[^/]+\/games\//.test(path)) classes.add("archive");
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

  function oauthBackoffRate(requestedRate, factor = 0.7) {
    if (oauthGatewaySafeRateTarget > 0 && oauthGatewaySafeRateTarget < requestedRate) {
      // We already proved a lower rate clean. Retreat just below that known-safe
      // point instead of collapsing all the way to a blind multiplicative cut.
      return Math.max(OAUTH_MIN_RATE_CPS, oauthGatewaySafeRateTarget * 0.9);
    }
    return Math.max(OAUTH_MIN_RATE_CPS, requestedRate * factor);
  }

  function oauthNextProbeRate(currentRate) {
    const current = Math.max(OAUTH_MIN_RATE_CPS, Number(currentRate) || OAUTH_INITIAL_RATE_CPS);
    if (oauthGatewayUnsafeRateTarget > current + 0.5) {
      // Once a backlash boundary is known, converge geometrically and never jump
      // straight back to it. Each clean probe closes only 25% of the remaining gap.
      const gap = oauthGatewayUnsafeRateTarget - current;
      return Math.min(oauthGatewayUnsafeRateTarget * 0.94, current + Math.max(0.5, gap * 0.25));
    }
    // Before a boundary is known, use conservative additive/multiplicative growth.
    return current + Math.max(1, current * 0.12);
  }

  function adaptOAuthGateway(batch) {
    // v2.9.12: the server-side OAuthRateCoordinator is authoritative across every
    // frame/requester that shares the OAuth token. The browser mirrors diagnostics
    // and sizes its per-POST connection cap; it no longer independently punishes or
    // probes the same upstream event a second time.
    const controller = batch?.controller && typeof batch.controller === "object" ? batch.controller : {};
    const serverRate = Math.max(OAUTH_MIN_RATE_CPS, Math.min(OAUTH_MAX_RATE_CPS, Number(controller.rate_target_cps || batch?.rate_cps || oauthGatewayRateTarget || OAUTH_INITIAL_RATE_CPS)));
    oauthGatewayRateTarget = serverRate;
    oauthGatewaySafeRateTarget = Math.max(0, Number(controller.safe_rate_cps || 0));
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
    const median = Math.max(0, Number(batch?.median_latency_ms || 0));
    const p95 = Math.max(0, Number(batch?.p95_latency_ms || 0));
    const endpointClass = String(batch?.endpoint_class || oauthBatchEndpointClass(batch) || "unknown");
    const baselines = controller.latency_baseline_ms && typeof controller.latency_baseline_ms === "object"
      ? controller.latency_baseline_ms
      : null;
    if (baselines) oauthGatewayLatencyByClass = { ...baselines };
    else if (median > 0 && endpointClass !== "mixed" && endpointClass !== "unknown") {
      const existing = Math.max(0, Number(oauthGatewayLatencyByClass[endpointClass] || 0));
      if (existing <= 0 || median < existing) oauthGatewayLatencyByClass[endpointClass] = median;
    }
    oauthGatewayBestRate = Math.max(oauthGatewayBestRate, oauthGatewayLastCps);
    oauthGatewayBestTargetRate = Math.max(oauthGatewayBestTargetRate, oauthGatewaySafeRateTarget);
    oauthGatewayCeilingDiscovered = oauthGatewayUnsafeRateTarget > 0;
    // Each gateway POST is intentionally bounded; multiple concurrent POSTs provide
    // the latency headroom required for slow endpoints without giant single waves.
    oauthGatewayTarget = Math.max(1, Math.min(OAUTH_GATEWAY_BATCH_SIZE, oauthGatewayMax, oauthConcurrencyForRate(serverRate, median, p95)));
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
    if (/^\/pub\/player\/[^/]+\/games\//.test(path)) return "archive";
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
    if (trafficClass !== "background") return oauthGatewayActivePosts < OAUTH_GATEWAY_MAX_POSTS;
    // Server-admin requests do not pass through the OAuth gateway queue, but they
    // compete for the same PHP-FCGI process pool. Treat them as P0 foreground too.
    if (serverForegroundPressure()) return false;
    // Reserve gateway capacity even before foreground arrives. Once foreground is
    // queued, stop admitting new background waves altogether.
    if (counts.foreground > 0 || oauthGatewayActiveForegroundPosts > 0) return false;
    return oauthGatewayActiveBackgroundPosts < OAUTH_BACKGROUND_MAX_POSTS && oauthGatewayActivePosts < OAUTH_BACKGROUND_MAX_POSTS;
  }

  function takeOAuthGatewayBatch() {
    if (!oauthGatewayQueue.length) return [];
    // Foreground always wins. Within the winning traffic class, keep endpoint
    // classes homogeneous so slow match details cannot distort a fast-index sample.
    let seedIndex = -1;
    let seed = null;
    for (let index = 0; index < oauthGatewayQueue.length; index += 1) {
      const entry = oauthGatewayQueue[index];
      if (entry.settled) continue;
      if (!seed || (seed.trafficClass === "background" && entry.trafficClass !== "background") ||
          (seed.trafficClass === entry.trafficClass && Number(entry.priority || 0) > Number(seed.priority || 0))) {
        seed = entry; seedIndex = index;
      }
    }
    if (!seed) return [];
    const trafficClass = seed.trafficClass;
    const endpointClass = seed.endpointClass;
    const matching = [];
    const remaining = [];
    for (const entry of oauthGatewayQueue) {
      if (!entry.settled && matching.length < OAUTH_GATEWAY_BATCH_SIZE && entry.trafficClass === trafficClass && entry.endpointClass === endpointClass) matching.push(entry);
      else remaining.push(entry);
    }
    oauthGatewayQueue.length = 0;
    oauthGatewayQueue.push(...remaining);
    matching.sort((a, b) => Number(b.priority || 0) - Number(a.priority || 0) || Number(a.sequence || 0) - Number(b.sequence || 0));
    return matching;
  }

  function scheduleOAuthGatewayFlush() {
    if (oauthGatewayTimer || !oauthGatewayQueue.length) return;
    const counts = updateInteractiveProtection("schedule");
    if (counts.foreground > 0) {
      if (oauthGatewayActivePosts >= OAUTH_GATEWAY_MAX_POSTS) return;
    } else if (counts.background > 0 && !canLaunchOAuthTraffic("background")) { oauthBackgroundAdmissionSuppressions++; return; }
    oauthGatewayTimer = window.setTimeout(flushOAuthGateway, 0);
  }

  async function flushOAuthGateway() {
    oauthGatewayTimer = 0;
    const countsBefore = updateInteractiveProtection("flush");
    const preferredTraffic = countsBefore.foreground > 0 ? "foreground" : "background";
    if (!canLaunchOAuthTraffic(preferredTraffic)) {
      if (preferredTraffic === "background") oauthBackgroundAdmissionSuppressions++;
      return;
    }
    const entries = takeOAuthGatewayBatch().filter(entry => !entry.settled);
    if (!entries.length) { if (oauthGatewayQueue.length) scheduleOAuthGatewayFlush(); return; }
    const active = entries.filter(entry => !entry.signal?.aborted);
    entries.filter(entry => entry.signal?.aborted).forEach(entry => entry.finish(false, abortError(entry.url)));
    if (!active.length) { if (oauthGatewayQueue.length) scheduleOAuthGatewayFlush(); return; }
    const csrf = String(window.P2K_AUTH?.getCsrf?.() || "");
    if (!csrf) {
      active.forEach(entry => entry.finish(false, apiError("OAuth session is not ready.", { category:"forbidden", code:"OAUTH_SESSION_NOT_READY", status:401, url:entry.url, retryable:true })));
      if (oauthGatewayQueue.length) scheduleOAuthGatewayFlush();
      return;
    }
    const trafficClass = active[0]?.trafficClass === "background" ? "background" : "foreground";
    oauthGatewayActivePosts += 1;
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
    try {
      const endpoint = new URL(OAUTH_GATEWAY_ENDPOINT, window.location.href); endpoint.searchParams.set("action", "batch");
      const response = await nativeFetch(endpoint.href, {
        method:"POST", credentials:"same-origin", cache:"no-store",
        headers:{"Content-Type":"application/json","Accept":"application/json","X-P2K-OAuth-CSRF":csrf},
        body:JSON.stringify({
          requests,
          concurrency: Math.max(1, Math.min(active.length, oauthGatewayTarget, OAUTH_GATEWAY_BATCH_SIZE)),
          // This is a ceiling/permission, not the actual launch target. v2.9.12's
          // server-side coordinator supplies the learned shared target.
          rate_cps: OAUTH_MAX_RATE_CPS,
          traffic_class: trafficClass
        })
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || payload?.ok !== true) {
        if (response.status === 401) setEnabled(false);
        throw apiError(payload?.error?.message || `OAuth API gateway HTTP ${response.status}`, {
          category: response.status===429?"rate-limit":"server",
          code: payload?.error?.code || "OAUTH_GATEWAY_ERROR",
          status: response.status,
          retryable: response.status===429 || response.status>=500
        });
      }
      adaptOAuthGateway(payload);
      const resultMap = new Map((Array.isArray(payload.results) ? payload.results : []).map(row => [String(row.id), row]));
      active.forEach(entry => {
        if (entry.signal?.aborted) return entry.finish(false, abortError(entry.url));
        const row = resultMap.get(entry.id);
        if (!row) return entry.finish(false, apiError("OAuth gateway omitted a response.", { category:"network", code:"OAUTH_GATEWAY_MISSING_RESULT", url:entry.url, retryable:true }));
        const status = Number(row.status || 0); const h = new Headers(row.headers || {});
        const body = status === 304 ? null : String(row.body ?? "");
        try { entry.finish(true, new Response(body, { status, statusText: String(row.status_text || ""), headers: h })); }
        catch (cause) { entry.finish(false, apiError("Unable to construct OAuth gateway response.", { category:"parse", code:"OAUTH_GATEWAY_RESPONSE", status, url:entry.url, retryable:true, cause })); }
      });
    } catch (error) {
      active.forEach(entry => entry.finish(false, normalizeError(error, entry.url, entry.signal)));
    } finally {
      oauthGatewayActivePosts = Math.max(0, oauthGatewayActivePosts - 1);
      if (trafficClass === "background") oauthGatewayActiveBackgroundPosts = Math.max(0, oauthGatewayActiveBackgroundPosts - 1);
      else oauthGatewayActiveForegroundPosts = Math.max(0, oauthGatewayActiveForegroundPosts - 1);
      updateInteractiveProtection("post-complete");
      if (oauthGatewayQueue.length) scheduleOAuthGatewayFlush();
    }
  }

  function executeOAuthGateway(url, { headers, signal, priority = 0, trafficClass = "foreground" }) {
    if (signal?.aborted) return Promise.reject(abortError(url));
    return new Promise((resolve, reject) => {
      const entry = {
        id:`g${++oauthGatewayBatchSequence}`,
        sequence: oauthGatewayBatchSequence,
        url, headers, signal,
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
      const onAbort = () => entry.finish(false, abortError(url));
      signal?.addEventListener?.("abort", onAbort, { once:true });
      oauthGatewayQueue.push(entry);
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
      oauthGatewayTarget = Math.max(1, Math.min(oauthGatewayMax, Math.max(OAUTH_INITIAL_TARGET, Number(saved?.target || 0))));
      oauthGatewayRateTarget = Math.max(OAUTH_MIN_RATE_CPS, Math.min(OAUTH_MAX_RATE_CPS, Number(saved?.rateTarget || OAUTH_INITIAL_RATE_CPS)));
      oauthGatewaySafeRateTarget = Math.max(0, Number(saved?.safeRateTarget || 0));
      oauthGatewayUnsafeRateTarget = Math.max(0, Number(saved?.unsafeRateTarget || 0));
      oauthGatewayBestTargetRate = Math.max(0, Number(saved?.bestTargetRate || 0));
      oauthGatewayBestRate = Math.max(0, Number(saved?.bestRate || 0));
      oauthGatewayBestMedianLatency = Math.max(0, Number(saved?.bestMedianLatency || 0));
      oauthGatewayLatencyByClass = saved?.latencyByClass && typeof saved.latencyByClass === "object" ? { ...saved.latencyByClass } : Object.create(null);
      oauthGatewayStableSamples = 0;
      oauthGatewayPlateauStrikes = 0;
      oauthGatewayCeilingDiscovered = oauthGatewayUnsafeRateTarget > 0;
    }
    return oauthBearerMode;
  }

  function effectiveBatchConcurrency() {
    return oauthBearerMode
      ? OAUTH_LOGICAL_CONCURRENCY
      : Math.max(1, Math.min(maxConcurrency, configuredConcurrency(), adaptiveConcurrency()));
  }

  function diagnostics() {
    return {
      oauthBearerMode,
      oauthGatewayTarget, oauthGatewayMax,
      oauthLogicalFeederConcurrency: OAUTH_LOGICAL_CONCURRENCY,
      oauthTransportCapacity: oauthGatewayMax,
      oauthGatewayRateTarget, oauthGatewaySafeRateTarget, oauthGatewayUnsafeRateTarget,
      oauthGatewayBestTargetRate, oauthGatewayLastCps, oauthGatewayBestRate,
      oauthGatewayPeakInFlight, oauthGatewayCeilingDiscovered,
      oauthGatewayLatencyByClass: { ...oauthGatewayLatencyByClass },
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

