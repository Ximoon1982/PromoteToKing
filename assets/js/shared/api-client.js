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
  const observationQueue = [];
  const observedUrls = new Map();
  const OBSERVATION_REUSE_MS = 10 * 60 * 1000;
  let observationTimer = 0;
  const OBSERVATION_ENDPOINT = String(window.P2K_SITE_CONFIG?.serverStorage?.opportunisticObservationEndpoint || "server/team-points/public/observe.php");

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

  function integer(value, fallback, minimum, maximum) {
    const number = Number(value);
    if (!Number.isFinite(number)) return fallback;
    return Math.min(maximum, Math.max(minimum, Math.trunc(number)));
  }


  function effectiveBatchConcurrency() {
    return oauthContext.effectiveBatchConcurrency();
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
          ...oauthContext.diagnostics()
        }
      }));
    } catch (_) { /* Older WebViews. */ }
  }

  const oauthContextFactory = window.P2K_API_MODULES?.oauthContext?.create;
  if (typeof oauthContextFactory !== "function") {
    throw new Error("P2K API OAuth context module is unavailable.");
  }
  const oauthContext = oauthContextFactory({
    nativeFetch, maxConcurrency: MAX_CONCURRENCY, integer, apiError, abortError,
    normalizeError, emitConcurrencyChange,
    configuredConcurrency: () => configuredConcurrency,
    adaptiveConcurrency: () => adaptiveConcurrency
  });

  function noteTransientFailure(error) {
    const time = Date.now();
    recentTransientFailures = recentTransientFailures.filter(value => time - value < 30_000);
    recentTransientFailures.push(time);
    successfulSinceThrottle = 0;
    if (error.category === "rate-limit") counters.rateLimits += 1;
    if (oauthContext.isBearerMode()) {
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
    if (oauthContext.isBearerMode()) return;
    successfulSinceThrottle += 1;
    recentTransientFailures = recentTransientFailures.filter(value => Date.now() - value < 30_000);
    if (successfulSinceThrottle >= 12 && adaptiveConcurrency < configuredConcurrency) {
      adaptiveConcurrency += 1;
      successfulSinceThrottle = 0;
      emitConcurrencyChange("recovery");
      drainQueue();
    }
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


  const transportFactory = window.P2K_API_MODULES?.transport?.create;
  if (typeof transportFactory !== "function") {
    throw new Error("P2K API transport module is unavailable.");
  }
  const { executeFetch, fetchSnapshot } = transportFactory({
    nativeFetch, defaultTimeoutMs: DEFAULT_TIMEOUT_MS, jsonpEnabled: JSONP_ENABLED,
    counters, integer, normalizeUrl, apiError, abortError, timeoutError, normalizeError,
    isCancelled, categoryForStatus, oauthSessionActive: oauthContext.sessionActive,
    executeOAuthGateway: oauthContext.executeGateway,
    waitForRealOAuthDecision: oauthContext.waitForRealDecision
  });

  const requestCoordinatorFactory = window.P2K_API_MODULES?.requestCoordinator?.create;
  if (typeof requestCoordinatorFactory !== "function") {
    throw new Error("P2K API request coordinator module is unavailable.");
  }
  const { json, jsonDetailed } = requestCoordinatorFactory({
    defaultAttempts: DEFAULT_ATTEMPTS, defaultTimeoutMs: DEFAULT_TIMEOUT_MS,
    retryBaseDelayMs: RETRY_BASE_DELAY_MS, counters, integer, normalizeUrl, apiError,
    activityAwareOptions, normalizeError, isTransient, isCancelled, abortError,
    recordError, recordWarning, delay, jitter, endpointPriority, schedule,
    fetchSnapshot, cachedData, queueObservation, noteSuccess, noteTransientFailure
  });

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
    const requestedConcurrency = integer(options.concurrency, transportConcurrency, 1, oauthContext.logicalConcurrency);
    // In bearer mode `concurrency` is logical feeder depth, not a physical
    // cURL connection cap. The OAuth gateway and server-side coordinator own
    // the real transport concurrency/rate. This lets callers deliberately keep
    // the gateway supplied even before the first batch has returned telemetry.
    const concurrency = oauthContext.isBearerMode() ? requestedConcurrency : Math.min(requestedConcurrency, transportConcurrency);
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
    oauthContext.setEnabled(false);
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
    const oauthBearerMode = oauthContext.setEnabled(enabled);
    concurrentMode = oauthBearerMode;
    configuredConcurrency = oauthBearerMode ? oauthContext.logicalConcurrency : 1;
    adaptiveConcurrency = configuredConcurrency;
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
    return oauthContext.observeBatch(batch);
  }

  function diagnostics() {
    const oauth = oauthContext.diagnostics();
    return Object.freeze({
      loaded: true,
      allowedOrigins: [...ALLOWED_ORIGINS],
      jsonpEnabled: JSONP_ENABLED,
      concurrentMode,
      ...oauth,
      transportMode: oauth.oauthBearerMode ? "oauth-bearer-gateway" : (concurrentMode ? "browser-concurrent" : "serial"),
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
    oauthContext.handleServerForegroundPressure();
    if (Math.max(0, Number(window.P2K_SERVER_FOREGROUND_REQUESTS || 0)) <= 0) drainQueue();
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
    get oauthBearerMode() { return oauthContext.isBearerMode(); },
    get concurrentTarget() { return CONCURRENT_TARGET; },
    get configuredConcurrency() { return configuredConcurrency; },
    get concurrency() { return configuredConcurrency; },
    get adaptiveConcurrency() { return adaptiveConcurrency; },
    get activeRequests() { return activeRequests; },
    get queuedRequests() { return requestQueue.length; },
    get nextAllowedRequestAt() { return nextAllowedRequestAt; }
  });
})();
