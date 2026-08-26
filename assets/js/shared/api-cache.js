/* Shared Chess.com PubAPI cache, memory fallback, and cross-tab coordination. */
(() => {
  "use strict";

  if (window.P2K_API_CACHE) return;

  const config = window.P2K_SITE_CONFIG?.api?.cache || {};
  const DB_NAME = "P2KSharedApiCache";
  const DB_VERSION = 3;
  const STORE_NAME = "responses";
  const CHANNEL_NAME = "p2k-api-request-coordination-v3";
  const CLAIM_WINDOW_MS = 60;
  const DEFAULT_REMOTE_LEASE_MS = 90_000;
  const COMPLETION_MEMORY_MS = 12_000;
  const CLEANUP_INTERVAL_MS = 15_000;
  const MEMORY_MAX_ENTRIES = positiveInteger(config.memoryMaximumEntries, 180);
  const MEMORY_MAX_BYTES = positiveInteger(config.memoryMaximumBytes, 8 * 1024 * 1024);
  const CALIBRATION = Object.freeze({
    clubMatchIndexMaximumAgeMs: hours(config.calibrationProposal?.clubMatchIndexMaximumAgeHours, 6),
    playerMaximumAgeMs: days(config.calibrationProposal?.playerMaximumAgeDays, 30),
    activeMatchMaximumAgeMs: days(config.calibrationProposal?.activeMatchMaximumAgeDays, 7),
    finishedMatchMaximumAgeMs: days(config.calibrationProposal?.finishedMatchMaximumAgeDays, 365),
    unknownMatchMaximumAgeMs: days(config.calibrationProposal?.unknownMatchMaximumAgeDays, 30),
    defaultMaximumAgeMs: days(7),
    maximumEntries: positiveInteger(config.calibrationProposal?.maximumEntries, 10000),
    maximumBytes: positiveInteger(config.calibrationProposal?.approximateMaximumMiB, 512) * 1024 * 1024,
    storageQuotaFraction: Math.min(0.9, Math.max(0.1, Number(config.calibrationProposal?.storageQuotaFraction) || 0.60)),
    minimumRecentMatchEntries: positiveInteger(config.calibrationProposal?.minimumRecentMatchEntries, 5000)
  });

  const scheduledPruningEnabled = config.scheduledPruningEnabled === true;
  const tabId = randomId();
  const memoryCache = new Map();
  const localInFlight = new Map();
  const remoteInFlight = new Map();
  const localClaims = new Map();
  const completionWaiters = new Map();
  const recentCompletions = new Map();
  let memoryBytes = 0;
  let databasePromise = null;
  let prunePromise = null;
  let requestSequence = 0;
  let channel = null;
  let cleanupTimer = null;
  const pendingWrites = new Map();
  const pendingWriteWaiters = new Map();
  let writeFlushTimer = null;
  let writeFlushPromise = null;

  const counters = {
    memoryHits: 0,
    indexedDbHits: 0,
    misses: 0,
    writes: 0,
    writeBatches: 0,
    maximumWriteBatch: 0,
    bulkReads: 0,
    bulkReadKeys: 0,
    writeFailures: 0,
    quotaRecoveries: 0,
    coordinatedLocalJoins: 0,
    coordinatedRemoteWaits: 0,
    crossTabClaimsWon: 0,
    crossTabClaimsLost: 0,
    prunedEntries: 0
  };

  function positiveInteger(value, fallback) {
    const number = Number(value);
    return Number.isFinite(number) && number > 0 ? Math.trunc(number) : fallback;
  }

  function hours(value, fallback = value) {
    return positiveInteger(value, fallback) * 60 * 60 * 1000;
  }

  function days(value, fallback = value) {
    return positiveInteger(value, fallback) * 24 * 60 * 60 * 1000;
  }

  function randomId() {
    try { return crypto.randomUUID(); }
    catch (_) { return `${Date.now()}-${Math.random().toString(36).slice(2)}`; }
  }

  function now() { return Date.now(); }

  function normalizeUrl(value) {
    try { return new URL(String(value || ""), window.location.href).href; }
    catch (_) { return String(value || ""); }
  }

  function abortError() {
    const error = new Error("The operation was cancelled.");
    error.name = "AbortError";
    error.category = "cancelled";
    error.code = "CANCELLED";
    return error;
  }

  function delay(milliseconds, signal = null) {
    const duration = Math.max(0, Number(milliseconds) || 0);
    if (signal?.aborted) return Promise.reject(abortError());
    return new Promise((resolve, reject) => {
      const timer = window.setTimeout(() => {
        signal?.removeEventListener("abort", onAbort);
        resolve();
      }, duration);
      const onAbort = () => {
        window.clearTimeout(timer);
        signal?.removeEventListener("abort", onAbort);
        reject(abortError());
      };
      signal?.addEventListener("abort", onAbort, { once: true });
    });
  }

  function withAbort(promise, signal = null) {
    if (!signal) return promise;
    if (signal.aborted) return Promise.reject(abortError());
    return new Promise((resolve, reject) => {
      const onAbort = () => reject(abortError());
      signal.addEventListener("abort", onAbort, { once: true });
      promise.then(
        value => { signal.removeEventListener("abort", onAbort); resolve(value); },
        error => { signal.removeEventListener("abort", onAbort); reject(error); }
      );
    });
  }

  function approximateSize(entry) {
    const text = [
      entry?.url || "",
      entry?.body || "",
      JSON.stringify(entry?.headers || {}),
      entry?.statusText || "",
      entry?.etag || "",
      entry?.lastModified || ""
    ].join("\n");
    try { return new Blob([text]).size; }
    catch (_) { return text.length * 2; }
  }

  function entryKind(url) {
    let path = "";
    try { path = new URL(normalizeUrl(url)).pathname.toLowerCase(); }
    catch (_) { return "other"; }
    if (/\/pub\/club\/[^/]+\/matches\/?$/.test(path)) return "club-match-index";
    if (/\/pub\/match\//.test(path)) return "match";
    if (/^\/pub\/player\/[^/]+\/?$/.test(path)) return "player-profile";
    if (/^\/pub\/player\/[^/]+\/stats\/?$/.test(path)) return "player-stats";
    if (/^\/pub\/player\/[^/]+\/matches\/?$/.test(path)) return "player-matches";
    if (/^\/pub\/player\/[^/]+\/games\/20\d{2}\/(0[1-9]|1[0-2])\/?$/.test(path)) return "player-archive";
    if (/\/pub\/player\//.test(path)) return "player";
    return "other";
  }

  function cachePolicy(url, entry = null) {
    const kind = entryKind(url);
    if (kind === "club-match-index") {
      return {
        kind,
        freshFor: 2 * 60 * 1000,
        usableFor: CALIBRATION.clubMatchIndexMaximumAgeMs,
        staleIfErrorFor: days(1),
        networkPreferred: true
      };
    }
    if (kind === "match") {
      const matchState = entry?.matchState || matchStateFromBody(entry?.body);
      if (matchState === "finished") {
        return {
          kind,
          matchState,
          // A confirmed finished match is immutable for ordinary analysis. Keep it
          // fresh for the same retention window so stale-while-revalidate does not
          // generate hundreds of background network calls in historical analyzers.
          freshFor: CALIBRATION.finishedMatchMaximumAgeMs,
          usableFor: CALIBRATION.finishedMatchMaximumAgeMs,
          staleIfErrorFor: CALIBRATION.finishedMatchMaximumAgeMs,
          networkPreferred: false
        };
      }
      return {
        kind,
        matchState,
        freshFor: 5 * 60 * 1000,
        usableFor: matchState === "active"
          ? Math.min(days(1), CALIBRATION.activeMatchMaximumAgeMs)
          : CALIBRATION.unknownMatchMaximumAgeMs,
        staleIfErrorFor: matchState === "active"
          ? CALIBRATION.activeMatchMaximumAgeMs
          : CALIBRATION.unknownMatchMaximumAgeMs,
        networkPreferred: false
      };
    }
    if (kind === "player-profile") {
      return { kind, freshFor: days(30), usableFor: CALIBRATION.playerMaximumAgeMs, staleIfErrorFor: days(180), networkPreferred: false };
    }
    if (kind === "player-stats") {
      return { kind, freshFor: hours(6), usableFor: days(7), staleIfErrorFor: days(30), networkPreferred: false };
    }
    if (kind === "player-matches") {
      return { kind, freshFor: 30 * 60 * 1000, usableFor: days(1), staleIfErrorFor: days(7), networkPreferred: false };
    }
    if (kind === "player-archive") {
      const currentMonth = new Date().toISOString().slice(0,7).replace('-', '/');
      const isCurrent = String(url).includes(`/games/${currentMonth}/`);
      return { kind, freshFor: isCurrent ? hours(1) : days(30), usableFor: isCurrent ? days(2) : days(365), staleIfErrorFor: days(365), networkPreferred: false };
    }
    if (kind === "player") {
      return { kind, freshFor: hours(1), usableFor: days(7), staleIfErrorFor: days(30), networkPreferred: false };
    }
    return {
      kind,
      freshFor: 10 * 60 * 1000,
      usableFor: CALIBRATION.defaultMaximumAgeMs,
      staleIfErrorFor: CALIBRATION.defaultMaximumAgeMs,
      networkPreferred: false
    };
  }

  function matchStateFromBody(body) {
    if (!body) return "unknown";
    try {
      const data = typeof body === "string" ? JSON.parse(body) : body;
      const status = String(data?.status || data?.state || "").trim().toLowerCase();
      if (/finish|complete|closed|ended/.test(status)) return "finished";
      if (/progress|started|ongoing|registration|register|open|pending/.test(status)) return "active";
      if (data?.finished === true || data?.is_finished === true) return "finished";
    } catch (_) { /* Cache parsing is handled by the API client. */ }
    return "unknown";
  }

  function normalizeEntry(entry) {
    if (!entry?.url) return null;
    const normalized = {
      ...entry,
      url: normalizeUrl(entry.url),
      fetchedAt: Number(entry.fetchedAt) || now(),
      kind: entry.kind || entryKind(entry.url)
    };
    if (normalized.kind === "match") {
      normalized.matchState = entry.matchState || matchStateFromBody(normalized.body);
    }
    normalized.sizeBytes = Math.max(0, Number(entry.sizeBytes) || approximateSize(normalized));
    return normalized;
  }

  function memoryPut(entry) {
    const normalized = normalizeEntry(entry);
    if (!normalized) return null;
    const previous = memoryCache.get(normalized.url);
    if (previous) memoryBytes -= Number(previous.sizeBytes) || 0;
    memoryCache.delete(normalized.url);
    memoryCache.set(normalized.url, normalized);
    memoryBytes += normalized.sizeBytes;
    while (memoryCache.size > MEMORY_MAX_ENTRIES || memoryBytes > MEMORY_MAX_BYTES) {
      const oldestKey = memoryCache.keys().next().value;
      if (!oldestKey) break;
      const oldest = memoryCache.get(oldestKey);
      memoryCache.delete(oldestKey);
      memoryBytes -= Number(oldest?.sizeBytes) || 0;
    }
    memoryBytes = Math.max(0, memoryBytes);
    return normalized;
  }

  function memoryGet(url) {
    const key = normalizeUrl(url);
    const entry = memoryCache.get(key);
    if (!entry) return null;
    memoryCache.delete(key);
    memoryCache.set(key, entry);
    counters.memoryHits += 1;
    return entry;
  }

  function memoryRemove(url) {
    const key = normalizeUrl(url);
    const entry = memoryCache.get(key);
    if (!entry) return false;
    memoryCache.delete(key);
    memoryBytes = Math.max(0, memoryBytes - (Number(entry.sizeBytes) || 0));
    return true;
  }

  function memoryClear() {
    memoryCache.clear();
    memoryBytes = 0;
  }

  function openDatabase() {
    if (databasePromise) return databasePromise;
    databasePromise = new Promise(resolve => {
      if (!("indexedDB" in window)) { resolve(null); return; }
      let request;
      try { request = indexedDB.open(DB_NAME, DB_VERSION); }
      catch (error) {
        console.warn("P2K API cache: IndexedDB access is unavailable", error);
        resolve(null);
        return;
      }
      request.onupgradeneeded = () => {
        const database = request.result;
        const store = database.objectStoreNames.contains(STORE_NAME)
          ? request.transaction.objectStore(STORE_NAME)
          : database.createObjectStore(STORE_NAME, { keyPath: "url" });
        if (!store.indexNames.contains("fetchedAt")) store.createIndex("fetchedAt", "fetchedAt", { unique: false });
        if (!store.indexNames.contains("sizeBytes")) store.createIndex("sizeBytes", "sizeBytes", { unique: false });
        if (!store.indexNames.contains("kind")) store.createIndex("kind", "kind", { unique: false });
      };
      request.onsuccess = () => {
        const database = request.result;
        database.onversionchange = () => database.close();
        resolve(database);
      };
      request.onerror = () => { console.warn("P2K API cache: IndexedDB unavailable", request.error); resolve(null); };
      request.onblocked = () => resolve(null);
    });
    return databasePromise;
  }

  async function idbRequest(mode, operation, fallback = null) {
    const database = await openDatabase();
    if (!database) return { ok: false, value: fallback, error: null, unavailable: true };
    return new Promise(resolve => {
      try {
        const transaction = database.transaction(STORE_NAME, mode);
        const store = transaction.objectStore(STORE_NAME);
        let settled = false;
        const finish = result => {
          if (settled) return;
          settled = true;
          resolve(result);
        };
        operation(store, transaction, finish);
        transaction.onerror = () => finish({ ok: false, value: fallback, error: transaction.error || null });
        transaction.onabort = () => finish({ ok: false, value: fallback, error: transaction.error || null });
      } catch (error) {
        resolve({ ok: false, value: fallback, error });
      }
    });
  }

  async function idbGet(url) {
    const key = normalizeUrl(url);
    return idbRequest("readonly", (store, _transaction, finish) => {
      const request = store.get(key);
      request.onsuccess = () => finish({ ok: true, value: request.result || null, error: null });
      request.onerror = () => finish({ ok: false, value: null, error: request.error || null });
    }, null);
  }

  async function get(url) {
    const memoryEntry = memoryGet(url);
    if (memoryEntry) return memoryEntry;
    const result = await idbGet(url);
    if (result.ok && result.value) {
      counters.indexedDbHits += 1;
      return memoryPut(result.value);
    }
    counters.misses += 1;
    return null;
  }

  async function getMany(urls) {
    const keys = [...new Set((Array.isArray(urls) ? urls : []).map(normalizeUrl).filter(Boolean))];
    const values = new Map();
    const missing = [];
    keys.forEach(key => {
      const entry = memoryGet(key);
      if (entry) values.set(key, entry);
      else missing.push(key);
    });
    if (!missing.length) return values;
    counters.bulkReads += 1;
    counters.bulkReadKeys += missing.length;
    const result = await idbRequest("readonly", (store, transaction, finish) => {
      const found = new Map();
      missing.forEach(key => {
        const request = store.get(key);
        request.onsuccess = () => { if (request.result) found.set(key, request.result); };
      });
      transaction.oncomplete = () => finish({ ok: true, value: found, error: null });
    }, new Map());
    const found = result.ok && result.value instanceof Map ? result.value : new Map();
    missing.forEach(key => {
      const entry = found.get(key);
      if (entry) { counters.indexedDbHits += 1; values.set(key, memoryPut(entry)); }
      else counters.misses += 1;
    });
    return values;
  }

  async function getAll() {
    const result = await idbRequest("readonly", (store, _transaction, finish) => {
      const request = store.getAll();
      request.onsuccess = () => finish({ ok: true, value: Array.isArray(request.result) ? request.result : [], error: null });
      request.onerror = () => finish({ ok: false, value: [], error: request.error || null });
    }, []);
    return result.value || [];
  }

  function isQuotaError(error) {
    return error?.name === "QuotaExceededError" || /quota/i.test(String(error?.message || ""));
  }

  async function idbPutMany(entries) {
    return idbRequest("readwrite", (store, transaction, finish) => {
      entries.forEach(entry => store.put(entry));
      transaction.oncomplete = () => finish({ ok: true, value: entries.length, error: null });
    }, 0);
  }

  function queueWrite(normalized) {
    pendingWrites.set(normalized.url, normalized);
    return new Promise(resolve => {
      if (!pendingWriteWaiters.has(normalized.url)) pendingWriteWaiters.set(normalized.url, []);
      pendingWriteWaiters.get(normalized.url).push(resolve);
      if (pendingWrites.size >= 64) {
        if (writeFlushTimer !== null) { window.clearTimeout(writeFlushTimer); writeFlushTimer = null; }
        void flushWrites();
      } else if (writeFlushTimer === null) {
        writeFlushTimer = window.setTimeout(() => { writeFlushTimer = null; void flushWrites(); }, 8);
      }
    });
  }

  async function flushWrites() {
    if (writeFlushPromise) return writeFlushPromise;
    if (!pendingWrites.size) return null;
    const entries = [...pendingWrites.values()].slice(0, 128);
    entries.forEach(entry => pendingWrites.delete(entry.url));
    writeFlushPromise = (async () => {
      let result = await idbPutMany(entries);
      if (!result.ok && isQuotaError(result.error)) {
        counters.quotaRecoveries += 1;
        await prune({ force: true, emergency: true });
        result = await idbPutMany(entries);
      }
      counters.writeBatches += 1;
      counters.maximumWriteBatch = Math.max(counters.maximumWriteBatch, entries.length);
      if (result.ok) counters.writes += entries.length;
      else counters.writeFailures += entries.length;
      entries.forEach(entry => {
        const waiters = pendingWriteWaiters.get(entry.url) || [];
        pendingWriteWaiters.delete(entry.url);
        waiters.forEach(resolve => resolve(entry));
      });
      return result;
    })().finally(() => {
      writeFlushPromise = null;
      if (pendingWrites.size && writeFlushTimer === null) writeFlushTimer = window.setTimeout(() => { writeFlushTimer = null; void flushWrites(); }, 0);
    });
    return writeFlushPromise;
  }

  async function put(entry) {
    const normalized = memoryPut(entry);
    if (!normalized) return null;
    await queueWrite(normalized);
    return normalized;
  }

  async function remove(url) {
    const key = normalizeUrl(url);
    memoryRemove(key);
    const result = await idbRequest("readwrite", (store, transaction, finish) => {
      store.delete(key);
      transaction.oncomplete = () => finish({ ok: true, value: true, error: null });
    }, false);
    return result.ok;
  }

  async function clear() {
    memoryClear();
    const result = await idbRequest("readwrite", (store, transaction, finish) => {
      store.clear();
      transaction.oncomplete = () => finish({ ok: true, value: true, error: null });
    }, false);
    return result.ok;
  }

  async function deleteKeys(keys) {
    const unique = [...new Set(keys.map(normalizeUrl).filter(Boolean))];
    unique.forEach(memoryRemove);
    if (!unique.length) return 0;
    const result = await idbRequest("readwrite", (store, transaction, finish) => {
      unique.forEach(key => store.delete(key));
      transaction.oncomplete = () => finish({ ok: true, value: unique.length, error: null });
    }, 0);
    return result.value || 0;
  }

  function maximumAgeFor(entry) {
    switch (entry?.kind || entryKind(entry?.url)) {
      case "club-match-index": return CALIBRATION.clubMatchIndexMaximumAgeMs;
      case "match":
        if (entry?.matchState === "finished") return CALIBRATION.finishedMatchMaximumAgeMs;
        if (entry?.matchState === "active") return CALIBRATION.activeMatchMaximumAgeMs;
        return CALIBRATION.unknownMatchMaximumAgeMs;
      case "player-profile": return CALIBRATION.playerMaximumAgeMs;
      case "player-stats": return days(7);
      case "player-matches": return days(1);
      case "player-archive": return days(365);
      case "player": return days(7);
      default: return CALIBRATION.defaultMaximumAgeMs;
    }
  }

  async function prune({ force = false, emergency = false } = {}) {
    if (!scheduledPruningEnabled && !force && !emergency) {
      return Object.freeze({ disabled: true, reason: "Scheduled cache pruning is disabled pending calibration." });
    }
    if (prunePromise) return prunePromise;
    prunePromise = (async () => {
      const entries = (await getAll()).map(normalizeEntry).filter(Boolean);
      const current = now();
      const expired = [];
      const retained = [];
      entries.forEach(entry => {
        if (current - entry.fetchedAt > maximumAgeFor(entry)) expired.push(entry);
        else retained.push(entry);
      });

      const matchEntries = retained.filter(entry => entry.kind === "match")
        .sort((a, b) => b.fetchedAt - a.fetchedAt);
      const protectedMatchUrls = new Set(
        matchEntries.slice(0, CALIBRATION.minimumRecentMatchEntries).map(entry => entry.url)
      );
      let totalBytes = retained.reduce((sum, entry) => sum + entry.sizeBytes, 0);
      let totalEntries = retained.length;
      let quotaTarget = CALIBRATION.maximumBytes;
      try {
        const estimate = await navigator.storage?.estimate?.();
        if (Number.isFinite(Number(estimate?.quota)) && Number(estimate.quota) > 0) {
          quotaTarget = Math.min(quotaTarget, Math.floor(Number(estimate.quota) * CALIBRATION.storageQuotaFraction));
        }
      } catch (_) { /* Storage estimation is optional. */ }
      const targetBytes = emergency ? Math.floor(quotaTarget * 0.75) : quotaTarget;
      const targetEntries = emergency ? Math.floor(CALIBRATION.maximumEntries * 0.75) : CALIBRATION.maximumEntries;
      const candidates = retained
        .filter(entry => !protectedMatchUrls.has(entry.url))
        .sort((a, b) => {
          const aMatch = a.kind === "match" ? 1 : 0;
          const bMatch = b.kind === "match" ? 1 : 0;
          return aMatch - bMatch || a.fetchedAt - b.fetchedAt;
        });
      const evicted = [];
      while (candidates.length && (totalEntries > targetEntries || totalBytes > targetBytes)) {
        const entry = candidates.shift();
        evicted.push(entry);
        totalEntries -= 1;
        totalBytes -= entry.sizeBytes;
      }
      const removed = await deleteKeys([...expired, ...evicted].map(entry => entry.url));
      counters.prunedEntries += removed;
      return Object.freeze({
        disabled: false,
        emergency,
        removedByAge: expired.length,
        removedByLimits: evicted.length,
        retainedEntries: Math.max(0, totalEntries),
        approximateBytes: Math.max(0, totalBytes)
      });
    })().finally(() => { prunePromise = null; });
    return prunePromise;
  }

  function post(message) {
    try { channel?.postMessage({ ...message, sourceTabId: tabId, sentAt: now() }); }
    catch (_) { /* Optional optimisation. */ }
  }

  function cleanupCoordination() {
    const time = now();
    for (const [url, record] of remoteInFlight) {
      if (record.expiresAt <= time) remoteInFlight.delete(url);
    }
    for (const [url, record] of recentCompletions) {
      if (record.expiresAt <= time) recentCompletions.delete(url);
    }
    for (const [url, record] of localClaims) {
      if (record.expiresAt <= time) localClaims.delete(url);
    }
  }

  function resolveCompletion(url, message) {
    const key = normalizeUrl(url);
    recentCompletions.set(key, { message, expiresAt: now() + COMPLETION_MEMORY_MS });
    const waiters = completionWaiters.get(key);
    if (!waiters) return;
    completionWaiters.delete(key);
    waiters.forEach(resolve => resolve(message));
  }

  function installChannel() {
    if (!("BroadcastChannel" in window)) return;
    try { channel = new BroadcastChannel(CHANNEL_NAME); }
    catch (_) { channel = null; return; }
    channel.addEventListener("message", event => {
      const message = event?.data;
      if (!message || message.sourceTabId === tabId || !message.url) return;
      const url = normalizeUrl(message.url);
      if (message.type === "claim") {
        const activeClaim = localClaims.get(url);
        if (activeClaim) activeClaim.claims.add(String(message.claimId || ""));
        return;
      }
      if (message.type === "start" || message.type === "heartbeat") {
        remoteInFlight.set(url, {
          requestId: String(message.requestId || ""),
          expiresAt: now() + positiveInteger(message.leaseMs, DEFAULT_REMOTE_LEASE_MS)
        });
        return;
      }
      if (message.type === "complete") {
        remoteInFlight.delete(url);
        resolveCompletion(url, message);
      }
    });
    cleanupTimer = window.setInterval(cleanupCoordination, CLEANUP_INTERVAL_MS);
  }

  function recentCompletion(url) {
    const key = normalizeUrl(url);
    const record = recentCompletions.get(key);
    if (!record || record.expiresAt <= now()) {
      recentCompletions.delete(key);
      return null;
    }
    return record.message;
  }

  function waitForRemote(url, signal = null) {
    const key = normalizeUrl(url);
    const completed = recentCompletion(key);
    if (completed) return Promise.resolve(completed);
    const remote = remoteInFlight.get(key);
    if (!remote || remote.expiresAt <= now()) {
      remoteInFlight.delete(key);
      return Promise.resolve(null);
    }
    counters.coordinatedRemoteWaits += 1;
    return withAbort(new Promise(resolve => {
      if (!completionWaiters.has(key)) completionWaiters.set(key, new Set());
      const waiters = completionWaiters.get(key);
      let timer = null;
      const finish = value => {
        if (timer !== null) window.clearTimeout(timer);
        waiters.delete(finish);
        if (!waiters.size) completionWaiters.delete(key);
        resolve(value);
      };
      waiters.add(finish);
      const recheck = () => {
        const completion = recentCompletion(key);
        if (completion) { finish(completion); return; }
        const active = remoteInFlight.get(key);
        if (!active || active.expiresAt <= now()) { finish(null); return; }
        timer = window.setTimeout(recheck, Math.min(1000, Math.max(25, active.expiresAt - now())));
      };
      recheck();
    }), signal);
  }

  function subscribeLocal(entry, signal = null) {
    counters.coordinatedLocalJoins += 1;
    const subscriber = { cancelled: false };
    entry.subscribers.add(subscriber);
    const wrapped = withAbort(entry.promise, signal);
    if (signal) {
      const onAbort = () => {
        subscriber.cancelled = true;
        entry.subscribers.delete(subscriber);
        if (!entry.subscribers.size && !entry.settled) entry.controller.abort();
        signal.removeEventListener("abort", onAbort);
      };
      signal.addEventListener("abort", onAbort, { once: true });
      wrapped.finally(() => {
        signal.removeEventListener("abort", onAbort);
        entry.subscribers.delete(subscriber);
      }).catch(() => {});
    } else {
      wrapped.finally(() => entry.subscribers.delete(subscriber)).catch(() => {});
    }
    return wrapped;
  }

  async function coordinate(url, task, { signal = null, leaseMs = DEFAULT_REMOTE_LEASE_MS } = {}) {
    const key = normalizeUrl(url);
    if (typeof task !== "function") throw new TypeError("task must be a function.");
    if (signal?.aborted) throw abortError();

    const existing = localInFlight.get(key);
    if (existing) return subscribeLocal(existing, signal);

    const activeRemote = remoteInFlight.get(key);
    if (activeRemote && activeRemote.expiresAt > now()) {
      const completion = await waitForRemote(key, signal);
      if (completion?.success) {
        const entry = await get(key);
        if (entry) return entry;
      }
    }

    if (channel) {
      const claimId = `${tabId}:${String(++requestSequence).padStart(8, "0")}`;
      const claim = { claims: new Set([claimId]), expiresAt: now() + CLAIM_WINDOW_MS + 500 };
      localClaims.set(key, claim);
      post({ type: "claim", url: key, claimId });
      await delay(CLAIM_WINDOW_MS, signal);
      localClaims.delete(key);
      const winner = [...claim.claims].filter(Boolean).sort()[0] || claimId;
      if (winner !== claimId) {
        counters.crossTabClaimsLost += 1;
        remoteInFlight.set(key, { requestId: winner, expiresAt: now() + leaseMs });
        const completion = await waitForRemote(key, signal);
        if (completion?.success) {
          const entry = await get(key);
          if (entry) return entry;
        }
      } else {
        counters.crossTabClaimsWon += 1;
      }
    }

    const controller = new AbortController();
    const requestId = `${tabId}:${++requestSequence}`;
    const localEntry = {
      controller,
      subscribers: new Set(),
      settled: false,
      promise: null
    };
    const heartbeatMs = Math.max(5000, Math.floor(leaseMs / 3));
    let heartbeat = null;
    localEntry.promise = (async () => {
      post({ type: "start", url: key, requestId, leaseMs });
      heartbeat = window.setInterval(
        () => post({ type: "heartbeat", url: key, requestId, leaseMs }),
        heartbeatMs
      );
      try {
        const value = await task(controller.signal);
        post({ type: "complete", url: key, requestId, success: true });
        return value;
      } catch (error) {
        post({ type: "complete", url: key, requestId, success: false, category: error?.category || "unknown" });
        throw error;
      } finally {
        localEntry.settled = true;
        if (heartbeat !== null) window.clearInterval(heartbeat);
        localInFlight.delete(key);
      }
    })();
    localInFlight.set(key, localEntry);
    return subscribeLocal(localEntry, signal);
  }

  async function inspect() {
    const entries = (await getAll()).map(normalizeEntry).filter(Boolean);
    const byKind = {};
    let approximateBytes = 0;
    entries.forEach(entry => {
      approximateBytes += entry.sizeBytes;
      byKind[entry.kind] = (byKind[entry.kind] || 0) + 1;
    });
    return Object.freeze({
      indexedDbEntries: entries.length,
      indexedDbApproximateBytes: approximateBytes,
      entriesByKind: Object.freeze(byKind),
      oldestFetchedAt: entries.length ? Math.min(...entries.map(entry => entry.fetchedAt)) : 0,
      newestFetchedAt: entries.length ? Math.max(...entries.map(entry => entry.fetchedAt)) : 0
    });
  }

  function diagnostics() {
    return Object.freeze({
      database: DB_NAME,
      store: STORE_NAME,
      indexedDBAvailable: Boolean(window.indexedDB),
      broadcastChannelAvailable: Boolean(channel),
      scheduledPruningEnabled,
      memoryEntries: memoryCache.size,
      memoryApproximateBytes: memoryBytes,
      localInFlight: localInFlight.size,
      remoteInFlight: remoteInFlight.size,
      pendingClaims: localClaims.size,
      counters: Object.freeze({ ...counters }),
      calibration: CALIBRATION
    });
  }

  function shutdown() {
    if (cleanupTimer !== null) window.clearInterval(cleanupTimer);
    if (writeFlushTimer !== null) { window.clearTimeout(writeFlushTimer); writeFlushTimer = null; }
    if (pendingWrites.size) void flushWrites();
    for (const entry of localInFlight.values()) entry.controller.abort();
    localInFlight.clear();
    try { channel?.close(); } catch (_) { /* Optional. */ }
    channel = null;
  }

  installChannel();
  window.addEventListener("pagehide", shutdown, { once: true });

  window.P2K_API_CACHE = Object.freeze({
    database: DB_NAME,
    store: STORE_NAME,
    scheduledPruningEnabled,
    calibration: CALIBRATION,
    get,
    getMany,
    put,
    remove,
    clear,
    prune,
    policyFor: cachePolicy,
    coordinate,
    diagnostics,
    inspect,
    shutdown
  });
  window.__P2K_SHARED_API_CACHE__ = window.P2K_API_CACHE;
})();
