/* Explicit Chess.com PubAPI client with caching, resilient retries, and priority scheduling. */
(() => {
  "use strict";

  const RUNTIME_VERSION = "2.10.4";
  const existingClient = window.P2K_API_CLIENT;
  if (
    existingClient?.runtimeVersion === RUNTIME_VERSION &&
    typeof existingClient.json === "function" &&
    typeof existingClient.processPriority === "function"
  ) return;

  const config = window.P2K_SITE_CONFIG?.api || {};
  const nativeFetch = window.fetch.bind(window);
  const DEFAULT_ATTEMPTS = integer(config.defaultAttempts, 3, 1, 6);
  const DEFAULT_CONCURRENCY = integer(config.defaultConcurrency, 5, 1, 12);
  const MAX_CONCURRENCY = integer(config.maximumConcurrency, 12, DEFAULT_CONCURRENCY, 24);
  const DEFAULT_TIMEOUT_MS = integer(config.requestTimeoutMs, 20_000, 1_000, 120_000);
  const RETRY_BASE_DELAY_MS = 800;
  const PRIORITY_AGING_INTERVAL_MS = 5_000;
  const ALLOWED_ORIGINS = new Set(
    (Array.isArray(config.allowedOrigins) ? config.allowedOrigins : ["https://api.chess.com"])
      .map(value => String(value).replace(/\/$/, ""))
  );
  const JSONP_ENABLED = config.jsonpFallback !== false;
  const backgroundRefreshes = new Map();
  const observationQueue = [];
  const observedUrls = new Map();
  const OBSERVATION_REUSE_MS = 10 * 60 * 1000;
  let observationTimer = 0;
  const OBSERVATION_ENDPOINT = String(window.P2K_SITE_CONFIG?.serverStorage?.opportunisticObservationEndpoint || "server/team-points/public/observe.php");
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

  function usefulObservation(url) {
    let parsed; try { parsed = new URL(url); } catch (_) { return false; }
    if (parsed.origin !== "https://api.chess.com") return false;
    const path = parsed.pathname.toLowerCase();
    const club = encodeURIComponent(String(window.P2K_SITE_CONFIG?.clubSlug || "promote-to-king").toLowerCase());
    if (path === `/pub/club/${club}` || path === `/pub/club/${club}/matches` || path === `/pub/club/${club}/members`) return true;
    return /^\/pub\/match\/\d+\/?$/.test(path)
      || /^\/pub\/player\/[^/]+\/(matches|stats)\/?$/.test(path)
      || /^\/pub\/player\/[^/]+\/games\/20\d{2}\/(0[1-9]|1[0-2])\/?$/.test(path)
      || /^\/pub\/player\/[^/]+\/?$/.test(path);
  }

  function compactObservationPayload(url, payload) {
    let parsed; try { parsed = new URL(url); } catch (_) { return payload; }
    if (/^\/pub\/player\/[^/]+\/games\/20\d{2}\/(0[1-9]|1[0-2])\/?$/i.test(parsed.pathname) && Array.isArray(payload?.games)) {
      // Monthly archives can be several MB. The opportunistic channel only needs the
      // untouched team-match game objects; the server revalidates every object against
      // known P2K participation before storing anything canonical.
      return { games: payload.games.filter(game => game && typeof game === "object" && !Array.isArray(game) && game.match).slice(0, 2000) };
    }
    return payload;
  }

  function observationSignature(payload) {
    let text = "";
    try { text = JSON.stringify(payload); } catch (_) { return ""; }
    let hash = 2166136261;
    for (let i = 0; i < text.length; i += Math.max(1, Math.floor(text.length / 4096))) {
      hash ^= text.charCodeAt(i); hash = Math.imul(hash, 16777619);
    }
    return `${text.length}:${(hash >>> 0).toString(16)}`;
  }

  function queueObservation(url, payload, detail = {}) {
    if (!usefulObservation(url) || !payload || typeof payload !== "object" || Array.isArray(payload)) return;
    payload = compactObservationPayload(url, payload);
    const signature = observationSignature(payload); if (!signature) return;
    const source = ["acamr","client_refresh"].includes(detail.observationSource) ? detail.observationSource : "browser";
    const cacheState = String(detail.cacheState || "").toUpperCase();
    // Cache reuse is not a new Chess.com observation. The original network fetch,
    // or the background refresh below, owns opportunistic ingest/freshness.
    if (["HIT", "STALE", "STALE_IF_ERROR"].includes(cacheState)) return;
    const claimBacked = ["acamr","client_refresh"].includes(source);
    const claimToken = claimBacked ? String(detail.observationClaimToken || "") : "";
    // A passive observation must never suppress delivery of a later server-assigned
    // claim receipt for the same URL/payload. Claim-bound dedupe is per claim token.
    const dedupeKey = claimBacked && claimToken ? `${source}:${claimToken}:${url}` : `${source}:${url}`;
    const previous = observedUrls.get(dedupeKey); const timestamp = Date.now();
    if (previous && previous.signature === signature && timestamp - previous.at < OBSERVATION_REUSE_MS) return;
    observedUrls.set(dedupeKey, { signature, at: timestamp });
    observationQueue.push({ url, payload, source, claimToken, claimKind: detail.observationClaimKind || "", cacheState: detail.cacheState || "", transport: detail.transport || "", observedAt: new Date().toISOString(), deliveryAttempts: 0 });
    counters.observationsQueued = (counters.observationsQueued || 0) + 1;
    if (!observationTimer) observationTimer = window.setTimeout(flushObservations, 100);
  }

  function flushObservations() {
    observationTimer = 0;
    if (!observationQueue.length) return;
    const batch = []; let bytes = 0;
    while (observationQueue.length && batch.length < 48) {
      const next = observationQueue[0]; let encoded = "";
      try { encoded = JSON.stringify(next); } catch (_) { observationQueue.shift(); continue; }
      if (batch.length && bytes + encoded.length > 1500000) break;
      observationQueue.shift(); batch.push(next); bytes += encoded.length;
    }
    if (!batch.length) return;
    const body = JSON.stringify({ observations: batch });
    nativeFetch(new URL(OBSERVATION_ENDPOINT, window.location.href).href, {
      method: "POST", credentials: "same-origin", cache: "no-store",
      headers: { "Content-Type": "application/json", Accept: "application/json", "X-P2K-Observation": "1" },
      body, keepalive: body.length < 60000
    }).then(async response => {
      let payload = null; try { payload = await response.json(); } catch (_) { payload = null; }
      if (!response.ok || payload?.ok !== true || payload?.deferred === true) throw Object.assign(new Error(`Observation delivery HTTP ${response.status}`), { deferred: payload?.deferred === true });
      counters.observationsSent = (counters.observationsSent || 0) + batch.length;
      counters.observationsAccepted = (counters.observationsAccepted || 0) + Number(payload?.accepted || 0);
      counters.observationWorkQueued = (counters.observationWorkQueued || 0) + Number(payload?.queued || 0);
      counters.observationRowsUpdated = (counters.observationRowsUpdated || 0) + Number(payload?.updated || 0);
      // FFSD: expose one compact result event per delivered observation batch so
      // controllers can attribute server-side observation rejection by work class.
      try {
        const results = Array.isArray(payload?.results) ? payload.results : [];
        window.dispatchEvent(new CustomEvent("p2k-api-observation-results", { detail: {
          rows: batch.map((item, index) => ({
            url: String(item.url || ""), claimKind: String(item.claimKind || ""), source: String(item.source || ""),
            accepted: Boolean(results[index]?.accepted), reason: String(results[index]?.reason || ""), type: String(results[index]?.type || "")
          }))
        }}));
      } catch (_) {}
    }).catch(() => {
      counters.observationDeliveryFailures = (counters.observationDeliveryFailures || 0) + batch.length;
      // One bounded retry is enough: the normal workers remain fully sufficient.
      batch.reverse().forEach(item => {
        const attempts = Number(item.deliveryAttempts || 0) + 1;
        if (attempts <= 1) observationQueue.unshift({ ...item, deliveryAttempts: attempts });
      });
    }).finally(() => {
      if (observationQueue.length && !observationTimer) observationTimer = window.setTimeout(flushObservations, 100);
    });
  }

  window.addEventListener("pagehide", () => { if (observationQueue.length && !observationTimer) flushObservations(); });

  const requestQueue = [];
  const errors = [];
  const warnings = [];
  const counters = {
    requests: 0,
    fetches: 0,
    conditionalFetches: 0,
    plainFetchRetries: 0,
    jsonp: 0,
    cacheHits: 0,
    cacheStaleHits: 0,
    cacheMisses: 0,
    staleIfErrorFallbacks: 0,
    retries: 0,
    rateLimits: 0,
    cancellations: 0
  };

  const CONCURRENT_TARGET = DEFAULT_CONCURRENCY;
  let concurrentMode = false;
  let configuredConcurrency = 1;
  let adaptiveConcurrency = 1;
  let activeRequests = 0;
  let sequence = 0;
  let successfulSinceThrottle = 0;
  let nextAllowedRequestAt = 0;
  let circuitOpenUntil = 0;
  let recentTransientFailures = [];
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

  function integer(value, fallback, minimum, maximum) {
    const number = Number(value);
    if (!Number.isFinite(number)) return fallback;
    return Math.min(maximum, Math.max(minimum, Math.trunc(number)));
  }

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

  function effectiveBatchConcurrency() {
    if (oauthBearerMode) {
      // v2.9.22.2: logical feeder depth must not be frozen to a transient physical
      // gateway cap. The shared gateway/rate coordinator owns physical admission.
      return OAUTH_LOGICAL_CONCURRENCY;
    }
    return Math.max(1, Math.min(MAX_CONCURRENCY, configuredConcurrency, adaptiveConcurrency));
  }

  function jitter(milliseconds) {
    const value = Math.max(0, Number(milliseconds) || 0);
    return Math.round(value * (0.85 + Math.random() * 0.3));
  }

  const requestSemanticsFactory = window.P2K_API_MODULES?.requestSemantics?.create;
  if (typeof requestSemanticsFactory !== "function") {
    throw new Error("P2K API request-semantics module is unavailable.");
  }
  const {
    normalizeUrl, apiError, abortError, activityAwareOptions, timeoutError,
    categoryForStatus, normalizeError, isCancelled, isTransient, isPermanent,
    userMessage, delay
  } = requestSemanticsFactory({ allowedOrigins: ALLOWED_ORIGINS });

  function recordError(error) {
    const described = describeError(error);
    errors.push({ ...described, at: Date.now() });
    if (errors.length > 10) errors.splice(0, errors.length - 10);
  }

  function recordWarning(message, detail = {}) {
    warnings.push({ message: String(message), ...detail, at: Date.now() });
    if (warnings.length > 10) warnings.splice(0, warnings.length - 10);
  }

  function endpointPriority(url) {
    let path = "";
    try { path = new URL(url).pathname.toLowerCase(); }
    catch (_) { return 0; }
    if (/\/pub\/club\/[^/]+\/matches\/?$/.test(path)) return 90;
    if (/\/pub\/match\//.test(path)) return 75;
    if (/\/pub\/player\/[^/]+\/(clubs|stats|games)/.test(path)) return 55;
    if (/\/pub\/player\//.test(path)) return 60;
    if (/\/pub\/club\//.test(path)) return 50;
    return 25;
  }

  function effectivePriority(entry) {
    const aging = Math.floor((Date.now() - entry.queuedAt) / PRIORITY_AGING_INTERVAL_MS);
    return entry.priority + aging;
  }

  function pickNextQueueIndex() {
    let bestIndex = 0;
    let bestPriority = -Infinity;
    for (let index = 0; index < requestQueue.length; index += 1) {
      const entry = requestQueue[index];
      const priority = effectivePriority(entry);
      if (priority > bestPriority || (priority === bestPriority && entry.sequence < requestQueue[bestIndex]?.sequence)) {
        bestPriority = priority;
        bestIndex = index;
      }
    }
    return bestIndex;
  }

  async function waitForGlobalGate(signal = null) {
    const gate = Math.max(nextAllowedRequestAt, circuitOpenUntil);
    if (gate > Date.now()) await delay(gate - Date.now(), signal);
  }

  function drainQueue() {
    const limit = Math.min(configuredConcurrency, adaptiveConcurrency);
    while (activeRequests < limit && requestQueue.length) {
      const index = pickNextQueueIndex();
      const entry = requestQueue.splice(index, 1)[0];
      if (entry.signal?.aborted) { entry.reject(abortError(entry.url)); continue; }
      activeRequests += 1;
      (async () => {
        await waitForGlobalGate(entry.signal);
        return entry.task();
      })().then(entry.resolve, entry.reject).finally(() => {
        activeRequests -= 1;
        drainQueue();
      });
    }
  }

  function schedule(task, { priority = 0, signal = null, url = "" } = {}) {
    if (signal?.aborted) return Promise.reject(abortError(url));
    return new Promise((resolve, reject) => {
      requestQueue.push({ task, priority: Number(priority) || 0, signal, url, resolve, reject, queuedAt: Date.now(), sequence: ++sequence });
      drainQueue();
    });
  }

  function emitConcurrencyChange(reason = "update") {
    try {
      window.dispatchEvent(new CustomEvent("p2k-api-concurrency-change", {
        detail: {
          reason,
          concurrentMode,
          configuredConcurrency,
          adaptiveConcurrency,
          activeRequests,
          queuedRequests: requestQueue.length,
          nextAllowedRequestAt,
          oauthGatewayTarget,
          oauthGatewayRateTarget,
          oauthGatewaySafeRateTarget,
          oauthGatewayUnsafeRateTarget
        }
      }));
    } catch (_) { /* Older WebViews. */ }
  }

  function noteTransientFailure(error) {
    const time = Date.now();
    recentTransientFailures = recentTransientFailures.filter(value => time - value < 30_000);
    recentTransientFailures.push(time);
    successfulSinceThrottle = 0;
    if (error.category === "rate-limit") counters.rateLimits += 1;
    if (oauthBearerMode) {
      // The shared server coordinator already applies Retry-After/cooldown to every
      // frame using this OAuth token. A second browser-wide gate would punish the
      // same backlash twice and create the long recovery valleys seen in v2.9.11.
      emitConcurrencyChange(error.category === "rate-limit" ? "oauth-rate-limit-server-owned" : "oauth-transient-failure");
      return;
    }
    if (recentTransientFailures.length >= 6) circuitOpenUntil = Math.max(circuitOpenUntil, time + 5_000);
    if (error.category === "rate-limit") {
      adaptiveConcurrency = Math.max(1, Math.floor(adaptiveConcurrency / 2));
      nextAllowedRequestAt = Math.max(nextAllowedRequestAt, time + Math.max(error.retryAfterMs || 0, 2_000));
      emitConcurrencyChange("rate-limit");
    } else {
      adaptiveConcurrency = Math.max(1, adaptiveConcurrency - 1);
      emitConcurrencyChange("transient-failure");
    }
  }

  function noteSuccess() {
    if (oauthBearerMode) return;
    successfulSinceThrottle += 1;
    recentTransientFailures = recentTransientFailures.filter(value => Date.now() - value < 30_000);
    if (successfulSinceThrottle >= 12 && adaptiveConcurrency < configuredConcurrency) {
      adaptiveConcurrency += 1;
      successfulSinceThrottle = 0;
      emitConcurrencyChange("recovery");
      drainQueue();
    }
  }

  function combineSignal(externalSignal, timeoutMs, url) {
    const controller = new AbortController();
    let timeoutTriggered = false;
    const timer = window.setTimeout(() => {
      timeoutTriggered = true;
      controller.abort();
    }, timeoutMs);
    const onAbort = () => controller.abort();
    externalSignal?.addEventListener("abort", onAbort, { once: true });
    return {
      signal: controller.signal,
      error() { return timeoutTriggered ? timeoutError(url) : abortError(url); },
      cleanup() {
        window.clearTimeout(timer);
        externalSignal?.removeEventListener("abort", onAbort);
      }
    };
  }

  function retryAfterMilliseconds(response) {
    const value = response.headers?.get?.("retry-after");
    if (!value) return 0;
    const seconds = Number(value);
    if (Number.isFinite(seconds)) return Math.max(0, seconds * 1000);
    const timestamp = Date.parse(value);
    return Number.isFinite(timestamp) ? Math.max(0, timestamp - Date.now()) : 0;
  }

  function appendCallbackParameter(url, callbackName) {
    return `${url}${url.includes("?") ? "&" : "?"}callback=${encodeURIComponent(callbackName)}`;
  }

  function loadJSONP(url, { signal = null, timeoutMs = DEFAULT_TIMEOUT_MS } = {}) {
    const normalizedUrl = normalizeUrl(url);
    if (!JSONP_ENABLED) {
      return Promise.reject(apiError("JSONP fallback is disabled.", {
        category: "network",
        code: "JSONP_DISABLED",
        url: normalizedUrl,
        retryable: true
      }));
    }
    counters.jsonp += 1;
    return new Promise((resolve, reject) => {
      if (signal?.aborted) { reject(abortError(normalizedUrl)); return; }
      const callbackName = `p2kApiJsonp_${Date.now()}_${Math.random().toString(36).slice(2)}`;
      const script = document.createElement("script");
      let timer = null;
      let settled = false;
      const cleanup = () => {
        if (timer !== null) window.clearTimeout(timer);
        signal?.removeEventListener("abort", onAbort);
        script.remove();
        try { delete window[callbackName]; }
        catch (_) { window[callbackName] = undefined; }
      };
      const finish = (handler, value) => {
        if (settled) return;
        settled = true;
        cleanup();
        handler(value);
      };
      const onAbort = () => finish(reject, abortError(normalizedUrl));
      window[callbackName] = data => finish(resolve, data);
      script.onerror = () => finish(reject, apiError(`JSONP request failed for ${normalizedUrl}`, {
        category: "network", code: "JSONP_FAILED", url: normalizedUrl, retryable: true
      }));
      timer = window.setTimeout(() => finish(reject, timeoutError(normalizedUrl)), timeoutMs);
      signal?.addEventListener("abort", onAbort, { once: true });
      script.src = appendCallbackParameter(normalizedUrl, callbackName);
      script.async = true;
      document.head.appendChild(script);
    });
  }

  function responseHeaders(response) {
    const headers = {};
    response.headers?.forEach?.((value, name) => { headers[name] = value; });
    if (!headers["content-type"]) headers["content-type"] = "application/json";
    return headers;
  }

  async function cachedData(entry, url) {
    try { return JSON.parse(String(entry?.body || "")); }
    catch (cause) {
      await window.P2K_API_CACHE?.remove?.(url);
      throw apiError(`Unable to parse the cached response from ${url}`, {
        category: "cache",
        code: "CACHE_PARSE_ERROR",
        status: Number(entry?.status) || 200,
        url,
        retryable: true,
        cause
      });
    }
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
        if (response.status === 401) setOAuthBearerMode(false);
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

  async function executeFetch(url, { headers, signal, timeoutMs, conditional = false, priority = 0, trafficClass = "foreground" }) {
    await waitForRealOAuthDecision();
    counters.fetches += 1;
    if (conditional) counters.conditionalFetches += 1;
    // OAuth queue waiting is not network time. The browser timeout must not start
    // while a logical request is merely waiting for a gateway lane; PHP cURL starts
    // its per-request timeout only when that individual Chess.com request launches.
    if (oauthSessionActive()) {
      try { return await executeOAuthGateway(url, { headers, signal, priority, trafficClass }); }
      catch (error) { throw normalizeError(error, url, signal); }
    }
    const combined = combineSignal(signal, timeoutMs, url);
    try {
      return await nativeFetch(url, {
        method: "GET",
        mode: "cors",
        cache: "no-cache",
        credentials: "omit",
        referrerPolicy: "no-referrer",
        headers,
        signal: combined.signal
      });
    } catch (error) {
      if (combined.signal.aborted) throw combined.error();
      throw normalizeError(error, url, signal);
    } finally {
      combined.cleanup();
    }
  }

  async function fetchSnapshot(url, cachedEntry, options) {
    const signal = options.signal || null;
    const timeoutMs = integer(options.timeoutMs, DEFAULT_TIMEOUT_MS, 1_000, 120_000);
    const baseHeaders = new Headers(options.headers || { Accept: "application/json" });
    const conditionalHeaders = new Headers(baseHeaders);
    const hasConditional = Boolean(cachedEntry?.etag || cachedEntry?.lastModified);
    if (cachedEntry?.etag) conditionalHeaders.set("If-None-Match", cachedEntry.etag);
    if (cachedEntry?.lastModified) conditionalHeaders.set("If-Modified-Since", cachedEntry.lastModified);

    let response;
    try {
      response = await executeFetch(url, {
        headers: hasConditional ? conditionalHeaders : baseHeaders,
        signal,
        timeoutMs,
        conditional: hasConditional,
        priority: Number(options.priority) || 0,
        trafficClass: options.trafficClass === "background" ? "background" : "foreground"
      });
    } catch (conditionalError) {
      if (isCancelled(conditionalError, signal)) throw conditionalError;
      if (hasConditional) {
        counters.plainFetchRetries += 1;
        try {
          response = await executeFetch(url, {
            headers: baseHeaders, signal, timeoutMs, conditional: false,
            priority: Number(options.priority) || 0,
            trafficClass: options.trafficClass === "background" ? "background" : "foreground"
          });
        } catch (plainError) {
          if (isCancelled(plainError, signal)) throw plainError;
          if (options.jsonpFallback === false || !JSONP_ENABLED) throw plainError;
          const data = await loadJSONP(url, { signal, timeoutMs });
          return {
            url,
            body: JSON.stringify(data),
            status: 200,
            statusText: "OK",
            headers: { "content-type": "application/json" },
            etag: "",
            lastModified: "",
            fetchedAt: Date.now(),
            transport: "jsonp"
          };
        }
      } else {
        if (options.jsonpFallback === false || !JSONP_ENABLED) throw conditionalError;
        const data = await loadJSONP(url, { signal, timeoutMs });
        return {
          url,
          body: JSON.stringify(data),
          status: 200,
          statusText: "OK",
          headers: { "content-type": "application/json" },
          etag: "",
          lastModified: "",
          fetchedAt: Date.now(),
          transport: "jsonp"
        };
      }
    }

    if (response.status === 304 && cachedEntry) {
      return { ...cachedEntry, fetchedAt: Date.now(), transport: "conditional-cache" };
    }
    if (!response.ok) {
      const status = Number(response.status) || 0;
      const category = categoryForStatus(status);
      throw apiError(`Unable to load ${url}: HTTP ${status}`, {
        ...category,
        status,
        url,
        retryAfterMs: retryAfterMilliseconds(response)
      });
    }

    const body = await response.text();
    try { JSON.parse(body); }
    catch (cause) {
      throw apiError(`Unable to parse the response from ${url}`, {
        category: "parse", code: "RESPONSE_PARSE_ERROR", status: Number(response.status) || 200,
        url, retryable: false, cause
      });
    }
    return {
      url,
      body,
      status: Number(response.status) || 200,
      statusText: response.statusText || "OK",
      headers: responseHeaders(response),
      etag: response.headers.get("etag") || cachedEntry?.etag || "",
      lastModified: response.headers.get("last-modified") || cachedEntry?.lastModified || "",
      fetchedAt: Date.now(),
      transport: "fetch"
    };
  }

  async function refresh(url, cachedEntry, options) {
    const cache = window.P2K_API_CACHE || null;
    const priority = Number.isFinite(Number(options.priority)) ? Number(options.priority) : endpointPriority(url);
    const task = async sharedSignal => {
      const snapshot = await schedule(
        () => fetchSnapshot(url, cachedEntry, { ...options, signal: sharedSignal || options.signal }),
        { priority, signal: sharedSignal || options.signal, url }
      );
      if (options.cacheMode !== "no-store" && cache?.put) await cache.put(snapshot);
      try {
        window.dispatchEvent(new CustomEvent("p2k-api-cache-updated", {
          detail: { url, fetchedAt: snapshot.fetchedAt, transport: snapshot.transport }
        }));
      } catch (_) { /* Older WebViews. */ }
      return snapshot;
    };
    if (cache?.coordinate && options.cacheMode !== "no-store") {
      const attempts = integer(options.attempts, DEFAULT_ATTEMPTS, 1, 6);
      const leaseMs = Math.max(90_000, (integer(options.timeoutMs, DEFAULT_TIMEOUT_MS, 1_000, 120_000) * attempts) + 15_000);
      return cache.coordinate(url, task, { signal: options.signal, leaseMs });
    }
    return task(options.signal);
  }

  async function jsonOnce(url, options = {}) {
    const normalizedUrl = normalizeUrl(url);
    counters.requests += 1;
    const cache = window.P2K_API_CACHE || null;
    const requestedMode = String(options.cacheMode || (options.networkOnly ? "network-only" : "default")).toLowerCase();
    let cachedEntry = requestedMode === "no-store" ? null : await cache?.get?.(normalizedUrl);
    const policy = cache?.policyFor?.(normalizedUrl, cachedEntry) || {
      freshFor: 0, usableFor: 0, staleIfErrorFor: 0, networkPreferred: false
    };
    const cacheMode = policy.networkPreferred && requestedMode === "default" ? "network-only" : requestedMode;
    if (cacheMode === "no-store") cachedEntry = null;
    let age = cachedEntry ? Date.now() - Number(cachedEntry.fetchedAt || 0) : Infinity;

    if (cacheMode === "cache-only") {
      if (!cachedEntry || age > policy.usableFor) {
        throw apiError(`No cached response is available for ${normalizedUrl}`, {
          category: "cache", code: "CACHE_MISS", url: normalizedUrl, retryable: false
        });
      }
      counters.cacheHits += 1;
      return { data: await cachedData(cachedEntry, normalizedUrl), cacheState: "HIT", transport: cachedEntry.transport || "cache" };
    }

    if (cacheMode === "default" && cachedEntry && age <= policy.usableFor) {
      if (age > policy.freshFor && !backgroundRefreshes.has(normalizedUrl) && (window.P2K_TAB_ACTIVITY?.isActive?.() ?? true)) {
        const promise = refresh(normalizedUrl, cachedEntry, {
          ...options, signal: null, priority: -100, trafficClass: "background", cacheMode: "network-only"
        }).then(async snapshot => {
          // The caller received stale-but-usable cache data, but the completed background
          // network validation is still valuable to the universal observation pipeline.
          try {
            const data = await cachedData(snapshot, normalizedUrl);
            queueObservation(normalizedUrl, data, {
              ...options,
              cacheState: "REFRESH",
              transport: snapshot.transport || "fetch"
            });
          } catch (_) { /* Cache update remains valid even if opportunistic ingest cannot decode it. */ }
          return snapshot;
        }).catch(error => {
          recordWarning("Background refresh failed.", { url: normalizedUrl, category: error?.category || "unknown" });
        }).finally(() => backgroundRefreshes.delete(normalizedUrl));
        backgroundRefreshes.set(normalizedUrl, promise);
      }
      if (age <= policy.freshFor) counters.cacheHits += 1;
      else counters.cacheStaleHits += 1;
      try {
        return {
          data: await cachedData(cachedEntry, normalizedUrl),
          cacheState: age <= policy.freshFor ? "HIT" : "STALE",
          transport: cachedEntry.transport || "cache"
        };
      } catch (error) {
        if (error.code !== "CACHE_PARSE_ERROR") throw error;
        cachedEntry = null;
        age = Infinity;
      }
    }

    counters.cacheMisses += 1;
    try {
      const snapshot = await refresh(normalizedUrl, cachedEntry, { ...options, cacheMode });
      noteSuccess();
      return {
        data: await cachedData(snapshot, normalizedUrl),
        cacheState: cachedEntry ? "REFRESH" : "MISS",
        transport: snapshot.transport || "fetch"
      };
    } catch (error) {
      const normalized = normalizeError(error, normalizedUrl, options.signal);
      if (isTransient(normalized)) noteTransientFailure(normalized);
      if (
        cachedEntry &&
        isTransient(normalized) &&
        age <= Number(policy.staleIfErrorFor || policy.usableFor || 0)
      ) {
        counters.staleIfErrorFallbacks += 1;
        const warning = {
          url: normalizedUrl,
          category: normalized.category,
          fetchedAt: cachedEntry.fetchedAt,
          ageMs: age
        };
        recordWarning("Using cached data because the network refresh failed.", warning);
        try {
          window.dispatchEvent(new CustomEvent("p2k-api-stale-fallback", { detail: warning }));
        } catch (_) { /* Older WebViews. */ }
        return {
          data: await cachedData(cachedEntry, normalizedUrl),
          cacheState: "STALE_IF_ERROR",
          transport: cachedEntry.transport || "cache",
          warning
        };
      }
      throw normalized;
    }
  }

  async function jsonDetailed(url, options = {}) {
    const normalizedUrl = normalizeUrl(url);
    const activity = activityAwareOptions(options);
    options = activity.options;
    const signal = options.signal || null;
    const attempts = integer(options.attempts, DEFAULT_ATTEMPTS, 1, 6);
    let lastError = null;
    try {
      for (let attempt = 1; attempt <= attempts; attempt += 1) {
        try {
          const result = await jsonOnce(normalizedUrl, options);
          result.observationSource = options.observationSource;
          // Claim-backed freshness requires a successful network/conditional validation.
          // A STALE_IF_ERROR cache fallback remains a useful hint but cannot satisfy the claim.
          if (result.cacheState !== "STALE_IF_ERROR") {
            result.observationClaimToken = options.observationClaimToken;
            result.observationClaimKind = options.observationClaimKind;
          }
          queueObservation(normalizedUrl, result.data, result);
          return Object.freeze({ ...result, url: normalizedUrl, attempts: attempt });
        } catch (error) {
          const normalized = normalizeError(error, normalizedUrl, signal);
          normalized.attempt = attempt;
          if (isCancelled(normalized, signal)) {
            counters.cancellations += 1;
            throw abortError(normalizedUrl);
          }
          lastError = normalized;
          recordError(normalized);
          if (!normalized.retryable || attempt >= attempts) break;
          counters.retries += 1;
          const wait = Math.max(normalized.retryAfterMs || 0, RETRY_BASE_DELAY_MS * attempt);
          await delay(jitter(wait), signal);
        }
      }
      throw lastError || apiError(`Unable to load ${normalizedUrl}`, { url: normalizedUrl });
    } finally {
      activity.cleanup();
    }
  }

  async function json(url, options = {}) { return (await jsonDetailed(url, options)).data; }

  function inferItemPriority(item) {
    const codes = window.P2K_SITE_CONFIG?.leagueAcronyms || [];
    const text = String(item?.name || item?.title || item?.url || item?.["@id"] || item?.apiUrl || item?.summary?.name || "").toUpperCase();
    return codes.some(code => new RegExp(`(^|[^A-Z0-9])${code}([^A-Z0-9]|$)`, "i").test(text)) ? 100 : 0;
  }

  function matchStartTimestamp(value) {
    const raw = value?.start_time ?? value?.startTime ?? value?.summary?.start_time;
    const numeric = Number(raw);
    return Number.isFinite(numeric) && numeric > 0 ? numeric : Number.POSITIVE_INFINITY;
  }

  function compareMatchPriority(a, b) {
    const priorityDifference = inferItemPriority(b) - inferItemPriority(a);
    if (priorityDifference !== 0) return priorityDifference;
    const dateDifference = matchStartTimestamp(a) - matchStartTimestamp(b);
    if (dateDifference !== 0) return dateDifference;
    return String(a?.name || a?.summary?.name || a?.["@id"] || a?.apiUrl || "")
      .localeCompare(String(b?.name || b?.summary?.name || b?.["@id"] || b?.apiUrl || ""));
  }

  function prioritizeMatchReferences(values) {
    return window.P2K_MATCH_PRIORITY?.prioritizeMatchReferences
      ? window.P2K_MATCH_PRIORITY.prioritizeMatchReferences(values)
      : [...(Array.isArray(values) ? values : [])].sort(compareMatchPriority);
  }

  function prioritizeRecords(values) {
    return window.P2K_MATCH_PRIORITY?.prioritizeRecords
      ? window.P2K_MATCH_PRIORITY.prioritizeRecords(values)
      : [...(Array.isArray(values) ? values : [])].sort(compareMatchPriority);
  }

  function makeBatchResult({ entries, succeeded, failures, pending, cancelled, worker, options }) {
    const result = {
      succeeded: succeeded.slice().sort((a, b) => a.index - b.index),
      failures: failures.slice().sort((a, b) => a.index - b.index),
      pending: pending.slice().sort((a, b) => a.index - b.index),
      cancelled: Boolean(cancelled),
      total: entries.length,
      settled: succeeded.length + failures.length,
      get partialValues() { return this.succeeded.map(entry => entry.value); },
      async retryFailed(retryOptions = {}) {
        if (!this.failures.length) return this;
        const originalFailures = this.failures;
        const retried = await processPriority(originalFailures.map(entry => entry.item), worker, {
          ...options,
          ...retryOptions,
          getKey: (_item, index) => originalFailures[index]?.key ?? String(index),
          getPriority: (_item, index) => originalFailures[index]?.priority ?? 0
        });
        const recovered = new Map(retried.succeeded.map(entry => [entry.key, entry]));
        const remaining = new Map(retried.failures.map(entry => [entry.key, entry]));
        const mergedSucceeded = [...this.succeeded];
        originalFailures.forEach(original => {
          if (recovered.has(original.key)) mergedSucceeded.push({ ...original, value: recovered.get(original.key).value });
        });
        return makeBatchResult({
          entries,
          succeeded: mergedSucceeded,
          failures: originalFailures.filter(entry => remaining.has(entry.key)).map(entry => ({ ...entry, error: remaining.get(entry.key).error })),
          pending: retried.pending,
          cancelled: retried.cancelled,
          worker,
          options: { ...options, ...retryOptions }
        });
      },
      async resumePending(resumeOptions = {}) {
        if (!this.pending.length) return this;
        const originalPending = this.pending;
        const resumed = await processPriority(originalPending.map(entry => entry.item), worker, {
          ...options,
          ...resumeOptions,
          getKey: (_item, index) => originalPending[index]?.key ?? String(index),
          getPriority: (_item, index) => originalPending[index]?.priority ?? 0
        });
        return makeBatchResult({
          entries,
          succeeded: [...this.succeeded, ...resumed.succeeded.map(entry => {
            const original = originalPending.find(item => item.key === entry.key);
            return { ...(original || entry), value: entry.value };
          })],
          failures: [...this.failures, ...resumed.failures],
          pending: resumed.pending,
          cancelled: resumed.cancelled,
          worker,
          options: { ...options, ...resumeOptions }
        });
      }
    };
    return Object.freeze(result);
  }

  async function processPriority(items, worker, options = {}) {
    if (!Array.isArray(items)) throw new TypeError("items must be an array.");
    if (typeof worker !== "function") throw new TypeError("worker must be a function.");
    const signal = options.signal || null;
    const transportConcurrency = effectiveBatchConcurrency();
    const requestedConcurrency = integer(options.concurrency, transportConcurrency, 1, OAUTH_LOGICAL_CONCURRENCY);
    // In bearer mode `concurrency` is logical feeder depth, not a physical
    // cURL connection cap. The OAuth gateway and server-side coordinator own
    // the real transport concurrency/rate. This lets callers deliberately keep
    // the gateway supplied even before the first batch has returned telemetry.
    const concurrency = oauthBearerMode ? requestedConcurrency : Math.min(requestedConcurrency, transportConcurrency);
    const getKey = typeof options.getKey === "function" ? options.getKey : (_item, index) => String(index);
    const getPriority = typeof options.getPriority === "function" ? options.getPriority : inferItemPriority;
    const onProgress = typeof options.onProgress === "function" ? options.onProgress : null;
    const queuedAt = Date.now();
    const entries = items.map((item, index) => ({
      item, index, key: String(getKey(item, index)), priority: Number(getPriority(item, index)) || 0, queuedAt
    }));

    // All entries enter this batch at the same time, so priority aging adds the
    // same value to every item. Sorting once is equivalent to the old repeated
    // full-queue scan, while avoiding O(N^2) feeder work on 600+ match histories.
    const queue = [...entries].sort((a, b) => b.priority - a.priority || a.index - b.index);
    let cursor = 0;
    const interrupted = [];
    const succeeded = [];
    const failures = [];
    let cancelled = Boolean(signal?.aborted);

    // Large analyzers used to open one IndexedDB transaction per worker as it
    // reached each URL. Warm all URL-shaped keys in one transaction first.
    try {
      const urls = entries.map(entry => entry.key).filter(key => /^https:\/\/api\.chess\.com\/pub\//i.test(key));
      if (urls.length > 1 && typeof window.P2K_API_CACHE?.getMany === "function") await window.P2K_API_CACHE.getMany(urls);
    } catch (_) { /* Cache warmup is an optimisation; normal per-key reads remain. */ }

    function takeNext() { return cursor < queue.length ? queue[cursor++] : null; }
    function pendingEntries() { return [...interrupted, ...queue.slice(cursor)]; }

    function report(entry = null, state = "pending") {
      if (!onProgress) return;
      try {
        onProgress({
          total: entries.length,
          settled: succeeded.length + failures.length,
          succeeded: succeeded.length,
          failed: failures.length,
          pending: interrupted.length + Math.max(0, queue.length - cursor),
          cancelled,
          state,
          item: entry?.item,
          index: entry?.index,
          key: entry?.key,
          error: entry?.error || null
        });
      } catch (error) { console.warn("P2K API client: progress callback failed", error); }
    }

    async function runWorker() {
      while (!cancelled) {
        if (signal?.aborted) { cancelled = true; break; }
        const entry = takeNext();
        if (!entry) break;
        try {
          const value = await worker(entry.item, entry.index, entry.key);
          succeeded.push({ ...entry, value });
          report(entry, "succeeded");
        } catch (error) {
          if (isCancelled(error, signal)) {
            cancelled = true;
            interrupted.push(entry);
            report({ ...entry, error: abortError() }, "cancelled");
            break;
          }
          const normalized = normalizeError(error, error?.url || "", signal);
          failures.push({ ...entry, error: normalized });
          report({ ...entry, error: normalized }, "failed");
        }
      }
    }

    report(null, cancelled ? "cancelled" : "started");
    await Promise.all(Array.from({ length: Math.min(concurrency, Math.max(1, queue.length)) }, () => runWorker()));
    return makeBatchResult({ entries, succeeded, failures, pending: pendingEntries(), cancelled, worker, options });
  }


  function describeError(error) {
    const normalized = normalizeError(error, error?.url || "");
    return Object.freeze({
      category: normalized.category,
      code: normalized.code,
      status: normalized.status,
      url: normalized.url,
      retryable: normalized.retryable,
      retryAfterMs: normalized.retryAfterMs,
      message: normalized.message
    });
  }

  function setConcurrentMode(enabled) {
    oauthBearerMode = false;
    concurrentMode = Boolean(enabled);
    configuredConcurrency = concurrentMode ? CONCURRENT_TARGET : 1;
    // Enabling is an explicit user choice, so start at the configured target.
    // Any later 429 response immediately reduces this value adaptively.
    adaptiveConcurrency = configuredConcurrency;
    successfulSinceThrottle = 0;
    emitConcurrencyChange(concurrentMode ? "concurrent-enabled" : "sequential-enabled");
    drainQueue();
    return concurrentMode;
  }

  function setOAuthBearerMode(enabled) {
    const wasEnabled = oauthBearerMode;
    oauthBearerMode = Boolean(enabled);
    concurrentMode = oauthBearerMode;
    configuredConcurrency = oauthBearerMode ? OAUTH_LOGICAL_CONCURRENCY : 1;
    adaptiveConcurrency = configuredConcurrency;
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
      oauthGatewayStableSamples = 0; oauthGatewayPlateauStrikes = 0; oauthGatewayCeilingDiscovered = oauthGatewayUnsafeRateTarget > 0;
    }
    successfulSinceThrottle = 0;
    emitConcurrencyChange(oauthBearerMode ? "oauth-bearer-enabled" : "oauth-bearer-disabled"); drainQueue(); return oauthBearerMode;
  }

  function setConcurrency(value) {
    configuredConcurrency = integer(value, DEFAULT_CONCURRENCY, 1, MAX_CONCURRENCY);
    concurrentMode = configuredConcurrency > 1;
    adaptiveConcurrency = configuredConcurrency;
    successfulSinceThrottle = 0;
    emitConcurrencyChange("configured");
    drainQueue();
    return configuredConcurrency;
  }

  function observeOAuthBatch(batch) {
    if (!oauthBearerMode || !batch || typeof batch !== "object") return false;
    adaptOAuthGateway(batch);
    return true;
  }

  function diagnostics() {
    return Object.freeze({
      loaded: true,
      allowedOrigins: [...ALLOWED_ORIGINS],
      jsonpEnabled: JSONP_ENABLED,
      concurrentMode,
      oauthBearerMode,
      transportMode: oauthBearerMode ? "oauth-bearer-gateway" : (concurrentMode ? "browser-concurrent" : "serial"),
      oauthGatewayTarget, oauthGatewayMax, oauthLogicalFeederConcurrency: OAUTH_LOGICAL_CONCURRENCY, oauthTransportCapacity: oauthGatewayMax, oauthGatewayRateTarget, oauthGatewaySafeRateTarget, oauthGatewayUnsafeRateTarget, oauthGatewayBestTargetRate, oauthGatewayLastCps, oauthGatewayBestRate, oauthGatewayPeakInFlight, oauthGatewayCeilingDiscovered, oauthGatewayLatencyByClass: { ...oauthGatewayLatencyByClass },
      oauthGatewayActivePosts, oauthGatewayMaximumActivePosts, oauthGatewayActiveForegroundPosts, oauthGatewayActiveBackgroundPosts, oauthGatewayQueued: oauthGatewayQueue.length,
      oauthGatewayForegroundQueued: oauthGatewayQueue.filter(entry => !entry.settled && entry.trafficClass !== "background").length,
      oauthGatewayBackgroundQueued: oauthGatewayQueue.filter(entry => !entry.settled && entry.trafficClass === "background").length,
      oauthForegroundReservedPosts: OAUTH_FOREGROUND_RESERVED_POSTS,
      oauthBackgroundMaxPosts: OAUTH_BACKGROUND_MAX_POSTS,
      serverForegroundRequests: Math.max(0, Number(window.P2K_SERVER_FOREGROUND_REQUESTS || 0)),
      oauthInteractiveWaitTargetMs: OAUTH_INTERACTIVE_WAIT_TARGET_MS,
      oauthForegroundWaitLastMs, oauthForegroundWaitMaxMs,
      oauthBackgroundAdmissionSuppressions,
      oauthInteractiveProtection,
      oauthInteractiveProtectionSince,
      recommendedBatchConcurrency: effectiveBatchConcurrency(),
      concurrentTarget: CONCURRENT_TARGET,
      configuredConcurrency,
      adaptiveConcurrency,
      activeRequests,
      queuedRequests: requestQueue.length,
      nextAllowedRequestAt,
      circuitOpenUntil,
      counters: Object.freeze({ ...counters }),
      recentErrors: errors.map(value => ({ ...value })),
      recentWarnings: warnings.map(value => ({ ...value }))
    });
  }

  window.addEventListener("p2k-server-foreground-pressure", () => {
    updateInteractiveProtection("server-foreground-pressure");
    if (!serverForegroundPressure()) { scheduleOAuthGatewayFlush(); drainQueue(); }
  });

  window.P2K_API_CLIENT = Object.freeze({
    runtimeVersion: RUNTIME_VERSION,
    json,
    jsonDetailed,
    processPriority,
    prioritizeMatchReferences,
    prioritizeRecords,
    describeError,
    userMessage,
    isPermanent,
    isTransient,
    setConcurrentMode,
    setOAuthBearerMode,
    setConcurrency,
    observeOAuthBatch,
    diagnostics,
    get concurrentMode() { return concurrentMode; },
    get oauthBearerMode() { return oauthBearerMode; },
    get concurrentTarget() { return CONCURRENT_TARGET; },
    get configuredConcurrency() { return configuredConcurrency; },
    get concurrency() { return configuredConcurrency; },
    get adaptiveConcurrency() { return adaptiveConcurrency; },
    get activeRequests() { return activeRequests; },
    get queuedRequests() { return requestQueue.length; },
    get nextAllowedRequestAt() { return nextAllowedRequestAt; }
  });
})();
