/* Shared Chess.com PubAPI cache, request de-duplication, and revalidation. */
(() => {
  "use strict";

  if (window.__P2K_SHARED_API_CACHE_INSTALLED__) return;
  window.__P2K_SHARED_API_CACHE_INSTALLED__ = true;

  const nativeFetch = window.fetch.bind(window);
  const DB_NAME = "P2KSharedApiCache";
  const DB_VERSION = 1;
  const STORE_NAME = "responses";
  const MAX_CACHE_AGE_MS = 12 * 60 * 60 * 1000;
  const PRIORITY_CODES = ["1WL", "TCMAC", "KOTML", "TMCL", "WKCL", "PCL", "CW"];
  const inFlight = new Map();
  const backgroundRefreshes = new Map();
  let databasePromise = null;

  function chessApiURL(input) {
    try {
      const value = input instanceof Request ? input.url : String(input || "");
      const url = new URL(value, window.location.href);
      return url.protocol === "https:" && url.hostname === "api.chess.com" ? url.href : null;
    } catch (_) {
      return null;
    }
  }

  function cachePolicy(url) {
    const path = new URL(url).pathname.toLowerCase();
    if (/\/pub\/club\/[^/]+\/matches\/?$/.test(path)) {
      return { freshFor: 2 * 60 * 1000, usableFor: MAX_CACHE_AGE_MS };
    }
    if (/\/pub\/match\//.test(path)) {
      return { freshFor: 5 * 60 * 1000, usableFor: MAX_CACHE_AGE_MS };
    }
    if (/\/pub\/player\//.test(path)) {
      return { freshFor: 15 * 60 * 1000, usableFor: MAX_CACHE_AGE_MS };
    }
    return { freshFor: 10 * 60 * 1000, usableFor: MAX_CACHE_AGE_MS };
  }

  function openDatabase() {
    if (databasePromise) return databasePromise;
    databasePromise = new Promise(resolve => {
      if (!("indexedDB" in window)) {
        resolve(null);
        return;
      }
      const request = indexedDB.open(DB_NAME, DB_VERSION);
      request.onupgradeneeded = () => {
        const database = request.result;
        if (!database.objectStoreNames.contains(STORE_NAME)) {
          const store = database.createObjectStore(STORE_NAME, { keyPath: "url" });
          store.createIndex("fetchedAt", "fetchedAt", { unique: false });
        }
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => {
        console.warn("P2K shared API cache: IndexedDB unavailable", request.error);
        resolve(null);
      };
      request.onblocked = () => resolve(null);
    });
    return databasePromise;
  }

  async function cacheGet(url) {
    const database = await openDatabase();
    if (!database) return null;
    return new Promise(resolve => {
      try {
        const transaction = database.transaction(STORE_NAME, "readonly");
        const request = transaction.objectStore(STORE_NAME).get(url);
        request.onsuccess = () => resolve(request.result || null);
        request.onerror = () => resolve(null);
      } catch (_) {
        resolve(null);
      }
    });
  }

  async function cachePut(entry) {
    const database = await openDatabase();
    if (!database) return;
    await new Promise(resolve => {
      try {
        const transaction = database.transaction(STORE_NAME, "readwrite");
        transaction.objectStore(STORE_NAME).put(entry);
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => resolve();
        transaction.onabort = () => resolve();
      } catch (_) {
        resolve();
      }
    });
  }

  function abortError() {
    try { return new DOMException("The operation was aborted.", "AbortError"); }
    catch (_) {
      const error = new Error("The operation was aborted.");
      error.name = "AbortError";
      return error;
    }
  }

  function withAbort(promise, signal) {
    if (!signal) return promise;
    if (signal.aborted) return Promise.reject(abortError());
    return new Promise((resolve, reject) => {
      const onAbort = () => reject(abortError());
      signal.addEventListener("abort", onAbort, { once: true });
      promise.then(
        value => {
          signal.removeEventListener("abort", onAbort);
          resolve(value);
        },
        error => {
          signal.removeEventListener("abort", onAbort);
          reject(error);
        }
      );
    });
  }

  function snapshotToResponse(snapshot, cacheState = "MISS") {
    const headers = new Headers(snapshot.headers || {});
    headers.set("X-P2K-Cache", cacheState);
    return new Response(snapshot.body, {
      status: snapshot.status,
      statusText: snapshot.statusText || "",
      headers
    });
  }

  function entryToSnapshot(entry) {
    return {
      body: entry.body,
      status: Number(entry.status) || 200,
      statusText: entry.statusText || "OK",
      headers: entry.headers || { "content-type": "application/json" }
    };
  }

  function responseHeaders(response) {
    const headers = {};
    response.headers.forEach((value, name) => { headers[name] = value; });
    if (!headers["content-type"]) headers["content-type"] = "application/json";
    return headers;
  }

  async function networkSnapshot(url, init, cachedEntry = null) {
    const baseHeaders = new Headers(init?.headers || {});
    const conditionalHeaders = new Headers(baseHeaders);
    let hasConditionalHeaders = false;

    if (cachedEntry?.etag) {
      conditionalHeaders.set("If-None-Match", cachedEntry.etag);
      hasConditionalHeaders = true;
    }
    if (cachedEntry?.lastModified) {
      conditionalHeaders.set("If-Modified-Since", cachedEntry.lastModified);
      hasConditionalHeaders = true;
    }

    const networkInit = {
      ...(init || {}),
      method: "GET",
      cache: "no-cache",
      headers: conditionalHeaders
    };
    delete networkInit.signal;

    let response;
    try {
      response = await nativeFetch(url, networkInit);
    } catch (error) {
      if (!hasConditionalHeaders) throw error;
      const fallbackInit = { ...networkInit, headers: baseHeaders };
      response = await nativeFetch(url, fallbackInit);
    }

    if (response.status === 304 && cachedEntry) {
      const refreshed = { ...cachedEntry, fetchedAt: Date.now() };
      await cachePut(refreshed);
      return entryToSnapshot(refreshed);
    }

    const body = await response.clone().text();
    const snapshot = {
      body,
      status: response.status,
      statusText: response.statusText,
      headers: responseHeaders(response)
    };

    if (response.ok) {
      const entry = {
        url,
        ...snapshot,
        etag: response.headers.get("etag") || cachedEntry?.etag || "",
        lastModified: response.headers.get("last-modified") || cachedEntry?.lastModified || "",
        fetchedAt: Date.now()
      };
      await cachePut(entry);
      try {
        window.dispatchEvent(new CustomEvent("p2k-api-cache-updated", {
          detail: { url, fetchedAt: entry.fetchedAt }
        }));
      } catch (_) { /* Older WebViews may not support CustomEvent construction. */ }
    }

    return snapshot;
  }

  function sharedNetworkRequest(url, init, cachedEntry = null, background = false) {
    const map = background ? backgroundRefreshes : inFlight;
    if (!map.has(url)) {
      const promise = networkSnapshot(url, init, cachedEntry)
        .finally(() => map.delete(url));
      map.set(url, promise);
    }
    return map.get(url);
  }

  window.fetch = async function p2kCachedFetch(input, init = {}) {
    const url = chessApiURL(input);
    const requestMethod = String(
      init?.method || (input instanceof Request ? input.method : "GET") || "GET"
    ).toUpperCase();
    const requestedCacheMode = String(init?.p2kCacheMode || "default").toLowerCase();
    const fetchInit = { ...(init || {}) };
    delete fetchInit.p2kCacheMode;

    if (!url || requestMethod !== "GET") return nativeFetch(input, fetchInit);

    const signal = init?.signal || (input instanceof Request ? input.signal : null);
    const policy = cachePolicy(url);
    const cachedEntry = await cacheGet(url);
    const isClubMatchIndex = /\/pub\/club\/[^/]+\/matches\/?$/.test(
      new URL(url).pathname.toLowerCase()
    );

    /*
     * The club match index defines which records are currently registered,
     * ongoing, or finished. Always await its network revalidation instead of
     * returning stale data and refreshing in the background.
     */
    if (requestedCacheMode === "network-only" || isClubMatchIndex) {
      const snapshot = await withAbort(
        sharedNetworkRequest(url, fetchInit, cachedEntry, false),
        signal
      );
      return snapshotToResponse(snapshot, "REFRESH");
    }

    const age = cachedEntry ? Date.now() - Number(cachedEntry.fetchedAt || 0) : Infinity;

    if (cachedEntry && age <= policy.usableFor) {
      if (age > policy.freshFor && !backgroundRefreshes.has(url)) {
        sharedNetworkRequest(url, fetchInit, cachedEntry, true).catch(error => {
          console.warn("P2K shared API cache: background refresh failed", url, error);
        });
      }
      return snapshotToResponse(
        entryToSnapshot(cachedEntry),
        age <= policy.freshFor ? "HIT" : "STALE"
      );
    }

    const snapshot = await withAbort(
      sharedNetworkRequest(url, fetchInit, cachedEntry, false),
      signal
    );
    return snapshotToResponse(snapshot, "MISS");
  };

  function priorityScore(value) {
    const text = String(value?.name || value?.title || value?.url || value?.apiUrl || value?.summary?.name || "").toUpperCase();
    return PRIORITY_CODES.some(code => new RegExp(`(^|[^A-Z0-9])${code}([^A-Z0-9]|$)`, "i").test(text)) ? 1 : 0;
  }

  function startTimestamp(value) {
    const raw = value?.start_time ?? value?.startTime ?? value?.summary?.start_time;
    const numeric = Number(raw);
    return Number.isFinite(numeric) && numeric > 0 ? numeric : Number.POSITIVE_INFINITY;
  }

  function comparePriority(a, b) {
    const priorityDifference = priorityScore(b) - priorityScore(a);
    if (priorityDifference !== 0) return priorityDifference;
    const dateDifference = startTimestamp(a) - startTimestamp(b);
    if (dateDifference !== 0) return dateDifference;
    return String(a?.name || a?.summary?.name || a?.["@id"] || a?.apiUrl || "")
      .localeCompare(String(b?.name || b?.summary?.name || b?.["@id"] || b?.apiUrl || ""));
  }

  window.p2kPrioritizeMatchReferences = values => [...(Array.isArray(values) ? values : [])].sort(comparePriority);
  window.p2kPrioritizeRecords = values => [...(Array.isArray(values) ? values : [])].sort(comparePriority);
  window.__P2K_SHARED_API_CACHE__ = {
    database: DB_NAME,
    store: STORE_NAME,
    maxAgeMs: MAX_CACHE_AGE_MS,
    clear: async () => {
      const database = await openDatabase();
      if (!database) return;
      await new Promise(resolve => {
        const transaction = database.transaction(STORE_NAME, "readwrite");
        transaction.objectStore(STORE_NAME).clear();
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => resolve();
      });
    }
  };
})();
